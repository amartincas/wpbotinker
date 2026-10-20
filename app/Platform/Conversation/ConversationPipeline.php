<?php

namespace App\Platform\Conversation;

use App\Factories\AIServiceFactory;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Platform\Capabilities\CapabilityRunner;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\CommerceResponder;
use App\Platform\Commerce\CommercialAdvisor;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Contracts\DomainModule;
use App\Platform\Contracts\ProvidesRetrievalGuide;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgePlanner;
use App\Platform\Knowledge\KnowledgeRetriever;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactCollector;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Conversation flow for stores in 'knowledge' mode (see
 * App\Models\Store::MODE_KNOWLEDGE). It only orchestrates Core services, in
 * order, and holds no domain or memory rules of its own:
 *
 *   FactCollector (pending answer) → CommerceResponder (commercial
 *   requests, always served) → ProductResolver (or clarification)
 *   → AccessGate → FactCollector (missing facts) → AccessGate again at
 *   the entry of the protected service (full service, free sample via
 *   SampleLedger, or the commercial answer, with CommercialAdvisor's
 *   configured resources when they fit) → CustomerMemory (visible
 *   facts) → CapabilityRunner (computed result) → KnowledgeRetriever
 *   → PromptAssembler → LLM → reply.
 *
 * It never touches the sales flow (leads, Conversions API, catalog images).
 */
class ConversationPipeline
{
    private const HISTORY_LIMIT = 10;

    /** How long a generated sample waits for a retry (the job's retry window). */
    private const SAMPLE_CACHE_MINUTES = 10;

    /** The store's welcome, still to be put before this turn's first reply (see withWelcome()). */
    private ?string $welcome = null;

    public function __construct(
        private readonly ProductResolver $resolver,
        private readonly DomainRegistry $domains,
        private readonly CustomerMemory $memory,
        private readonly FactCollector $facts,
        private readonly KnowledgeRetriever $knowledge,
        private readonly PromptAssembler $prompts,
        private readonly CapabilityRunner $capabilities,
        private readonly AccessGate $access,
        private readonly SampleLedger $samples,
        private readonly CommerceResponder $commerce,
        private readonly KnowledgePlanner $planner,
        private readonly CommercialAdvisor $advisor,
    ) {}

    public function handle(Store $store, string $from, string $messageBody, ?int $adProductId = null, ?string $wamid = null): void
    {
        $conversation = Conversation::firstOrCreate(
            ['store_id' => $store->id, 'customer_phone' => $from],
            ['last_session_at' => now()],
        );
        $customer = $this->memory->customerFor($store, $from);
        $this->welcome = $this->welcomeFor($store, $from);

        $pending = $this->facts->answerPending($conversation, $customer, $messageBody, $wamid);

        if ($pending->reply !== null) {
            $this->reply($store, $from, $messageBody, $pending->reply, $pending->replyDomainKey);

            return;
        }

        // Commercial requests (prices, plans, paying, renewing, payment
        // status) are always served, with or without access.
        $catalog = $this->resolver->catalog($store);
        $target = $this->commercialTarget($store, $conversation, $messageBody, $adProductId, $catalog);

        if ($commercial = $this->commerce->respond($store, $conversation, $customer, $messageBody, $catalog, $target)) {
            $this->reply($store, $from, $messageBody, $commercial->text, $commercial->domainKey);

            return;
        }

        $product = $pending->answeredProductId ? $this->resolver->catalogProduct($store, $pending->answeredProductId) : null;

        if ($product === null) {
            $resolution = $this->resolver->resolve($store, $conversation, $messageBody, $adProductId);

            if ($resolution->needsClarification()) {
                $conversation->update(['pending_state' => [
                    'type' => ProductResolver::PENDING_CLARIFICATION,
                    'product_ids' => $resolution->options->pluck('id')->all(),
                ]]);

                Log::info('PIPELINE_CLARIFICATION', [
                    'store_id' => $store->id,
                    'customer_phone' => $from,
                    'option_count' => $resolution->options->count(),
                ]);

                $this->reply($store, $from, $messageBody, $this->clarificationText($resolution->options));

                return;
            }

            $product = $resolution->product;
        }

        $domain = $product ? $this->domains->get($product->domain_key, $store->id) : null;

        $conversation->update(['current_product_id' => $product?->id, 'pending_state' => null]);

        // Commercial access, from persisted state only. Without access and
        // without a sample to give, no facts are asked: the customer gets
        // the commercial answer straight away.
        if ($product && ($access = $this->access->decide($customer, $product)) && ! $access->allowed && ! $access->sampleAvailable) {
            $this->replyBlocked($store, $from, $messageBody, $customer, $product, $domain, $access);

            return;
        }

        // What the customer asked: when this message only answered a question
        // for the product's data, the query that led to that question.
        $query = ($product ? $pending->originFor($product->id) : null) ?? $messageBody;

        if ($product && $domain && ($question = $this->facts->require($conversation, $customer, $product, $domain, $pending->rejectionFor($product->id), $query))) {
            Log::info('PIPELINE_FACT_REQUESTED', ['store_id' => $store->id, 'customer_phone' => $from, 'domain_key' => $domain->key()]);

            $this->reply($store, $from, $messageBody, $question, $domain->key());

            return;
        }

        // Checked again at the entry of the protected service: the state may
        // have changed while facts were being collected. Without access, the
        // only way in is the free sample, reserved before it is generated.
        $sample = null;

        if ($product && ! ($access = $this->access->decide($customer, $product))->allowed) {
            $sample = $access->sampleAvailable ? $this->samples->reserve($customer, $product) : null;

            if ($sample === null) {
                $this->replyBlocked($store, $from, $messageBody, $customer, $product, $domain, $access);

                return;
            }
        }

        try {
            // A retry of the same message reuses the sample already generated.
            $answer = $sample ? $this->rememberedSample($store, $wamid) : null;
            $answer ??= $this->answer($store, $from, $messageBody, $query, $customer, $product, $domain, $sample !== null);
        } catch (Throwable $e) {
            // Nothing was delivered: the sample stays available.
            if ($sample) {
                $this->samples->release($sample);
            }

            throw $e;
        }

        if ($answer === null) {
            if ($sample) {
                $this->samples->release($sample);
            }

            $this->reply($store, $from, $messageBody, "No pude completar tu solicitud sobre «{$product->name}» en este momento. Intenta de nuevo en unos minutos.", $domain?->key());

            return;
        }

        if ($sample) {
            $this->rememberSample($store, $wamid, $answer);
            $this->deliverSample($store, $from, $messageBody, $answer."\n\n".$this->commerce->afterSampleText($product), $domain?->key(), $sample, $wamid);

            return;
        }

        $this->reply($store, $from, $messageBody, $answer, $domain?->key());
    }

    /**
     * Sends the sample first and records the turn only once WhatsApp took
     * it: a sample that did not go out never appears in the history as
     * given. If the send fails, the reservation is released and the job
     * fails, so the existing queue retries try again (no turn was written,
     * and the generated answer is reused, see rememberSample()).
     *
     * Once WhatsApp accepted it, nothing may fail the job any more: a retry
     * would send the customer a contradicting offer. Both turns are saved
     * in one transaction; if that fails, it is logged and the job ends.
     */
    private function deliverSample(Store $store, string $from, string $messageBody, string $answer, ?string $domainKey, SampleDelivery $reservation, ?string $inboundWamid): void
    {
        $answer = $this->withWelcome($answer);
        $sentWamid = WhatsAppService::sendMessage($from, $answer, $store);

        if ($sentWamid === null) {
            $this->samples->release($reservation);

            throw new RuntimeException("The free sample of product [{$reservation->product_id}] could not be sent.");
        }

        try {
            $this->forgetSample($store, $inboundWamid);
            WhatsAppMessage::recordDeliveredTurn($store->id, $from, $domainKey, $messageBody, $answer, $sentWamid);
        } catch (Throwable $e) {
            Log::error('SAMPLE_HISTORY_NOT_SAVED', [
                'store_id' => $store->id,
                'product_id' => $reservation->product_id,
                'error' => $e::class,
            ]);
        }
    }

    /**
     * The sample generated for this inbound message by an earlier attempt,
     * so a retry does not call the LLM again. Null without a message id.
     */
    private function rememberedSample(Store $store, ?string $inboundWamid): ?string
    {
        $cached = $inboundWamid ? Cache::get($this->sampleCacheKey($store, $inboundWamid)) : null;

        if (! is_string($cached)) {
            return null;
        }

        try {
            return Crypt::decryptString($cached);
        } catch (Throwable) {
            return null;
        }
    }

    /** Kept encrypted (it holds the customer's data) for the retry window of the job. */
    private function rememberSample(Store $store, ?string $inboundWamid, string $answer): void
    {
        if ($inboundWamid) {
            Cache::put($this->sampleCacheKey($store, $inboundWamid), Crypt::encryptString($answer), now()->addMinutes(self::SAMPLE_CACHE_MINUTES));
        }
    }

    private function forgetSample(Store $store, ?string $inboundWamid): void
    {
        if ($inboundWamid) {
            Cache::forget($this->sampleCacheKey($store, $inboundWamid));
        }
    }

    private function sampleCacheKey(Store $store, string $inboundWamid): string
    {
        return "sample-answer:{$store->id}:".hash('sha256', $inboundWamid);
    }

    /**
     * The LLM answer for the product (its full service, or its free sample),
     * or null when its capability failed: then nothing of the protected
     * service is produced this turn.
     *
     * @param  string  $query  what the customer asked (see handle()); it differs from
     *                         $messageBody when this message answered a question for
     *                         their data, and then joins it in the knowledge search
     */
    private function answer(Store $store, string $from, string $messageBody, string $query, $customer, ?Product $product, ?DomainModule $domain, bool $isSample): ?string
    {
        $facts = $domain ? $this->memory->visibleFacts($customer, $domain) : [];
        $capabilityKey = $isSample ? $product?->sample_capability_key : $product?->capability_key;
        $computed = null;

        if ($product && $domain && filled($capabilityKey)) {
            try {
                $computed = $this->capabilities->run($customer, $product, $domain, $capabilityKey);
            } catch (Throwable $e) {
                Log::error('CAPABILITY_FAILED', [
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'capability_key' => $capabilityKey,
                    'error' => $e::class,
                ]);

                return null;
            }
        }

        // A computed result that names its knowledge entries gets exactly
        // those; otherwise knowledge is searched with the customer's query.
        $keyed = $computed !== null && $computed->knowledgeKeys !== [];
        // The original query adds to this message (which may itself carry
        // meaning, e.g. a question asked as a fact); it never replaces it.
        $searchText = $query === $messageBody ? $messageBody : $query."\n".$messageBody;
        $guided = ! $keyed && $domain instanceof ProvidesRetrievalGuide && trim($domain->retrievalGuide()) !== '';
        $fragments = match (true) {
            $domain === null => collect(),
            $keyed => $this->knowledge->retrieveByKeys($store, $domain->key(), $product, $computed->knowledgeKeys),
            // The free sample of a guided topic rests on the sections its
            // guide picks from the customer's data, never on whatever words
            // of the message happen to match a title.
            $guided && $isSample => collect(),
            default => $this->knowledge->retrieve($store, $domain->key(), $product, $searchText),
        };

        // A free sample, or a query that alone found nothing (e.g. the
        // customer only answered with their data): a domain with a retrieval
        // guide picks the sections from the customer's data and query.
        // Nothing is ever answered as if it rested on sources it does not have:
        // - selection failed, or a free sample without sections: null (the
        //   sample is released, nothing changes, the customer can retry);
        // - a message that needs the sources but got none: a fixed reply
        //   saying so, without the LLM;
        // - a message that needs no sources (thanks, a greeting): answered
        //   normally, without fragments.
        $planned = false;

        if ($guided && ($isSample || $fragments->isEmpty())) {
            $plan = $this->planner->plan($store, $domain, $product, $searchText, $facts, $isSample);
            $planned = true;

            if ($plan->failed || ($isSample && $plan->fragments->isEmpty())) {
                Log::warning('PIPELINE_KNOWLEDGE_UNAVAILABLE', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'sample' => $isSample,
                    'plan_status' => $plan->status,
                ]);

                return null;
            }

            if ($plan->unsupported()) {
                Log::info('PIPELINE_KNOWLEDGE_NOT_FOUND', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'plan_status' => $plan->status,
                ]);

                return "No encontré información sobre eso en las fuentes de «{$domain->label()}». ¿Puedes contarme un poco más o preguntarlo de otra forma?";
            }

            $fragments = $plan->fragments;
        }

        $answer = AIServiceFactory::make($store)->getResponse(
            $messageBody,
            $this->prompts->build($store, $domain, $product, $facts, $fragments, $computed, $isSample ? $product->sample_instructions : null, $query !== $messageBody ? $query : null),
            $this->history($store, $from, $domain?->key()),
        );

        Log::info('PIPELINE_ANSWERED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'domain_key' => $domain?->key(),
            'product_id' => $product?->id,
            'sample' => $isSample,
            'fact_keys' => array_keys($facts),
            'capability_key' => $computed ? $capabilityKey : null,
            'knowledge_retrieval' => $keyed ? 'keys' : ($planned ? 'planned' : 'message'),
            'knowledge_chunk_ids' => $fragments->pluck('id')->all(),
            'original_query' => $query !== $messageBody,
        ]);

        return $answer;
    }

    /**
     * Without access: the deterministic commercial answer (subscription
     * state, plans), preceded by the product's configured commercial
     * resources when CommercialAdvisor finds some that fit the message.
     */
    private function replyBlocked(Store $store, string $from, string $messageBody, $customer, Product $product, ?DomainModule $domain, AccessDecision $access): void
    {
        $commercial = $domain
            ? $this->advisor->respond($store, $domain, $product, $access, $messageBody, $this->memory->visibleFacts($customer, $domain))
            : null;

        Log::info('PIPELINE_ACCESS_DENIED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'product_id' => $product->id,
            'reason' => $access->reason,
            'open_payment_status' => $access->openPayment?->status,
            'commercial' => $commercial !== null,
        ]);

        $offer = $commercial !== null
            ? $this->commerce->offerAfterCommercialReply($store, $product, $access)
            : $this->commerce->blockedReply($store, $product, $access);

        $this->reply($store, $from, $messageBody, $commercial !== null ? $commercial."\n\n".$offer : $offer, $product->domain_key);
    }

    /**
     * The subscription product a commercial request is about: the ad's, the
     * conversation's, the store's only one, or the one the message names.
     * Null when there is none, or it is ambiguous (then the normal flow,
     * with its clarification, takes over).
     *
     * @param  Collection<int, Product>  $catalog
     */
    private function commercialTarget(Store $store, Conversation $conversation, string $messageBody, ?int $adProductId, Collection $catalog): ?Product
    {
        $subscriptions = $catalog->filter(fn (Product $product) => $product->requiresSubscription())->values();

        foreach ([$adProductId, $conversation->current_product_id] as $id) {
            if ($id && ($product = $subscriptions->firstWhere('id', $id))) {
                return $product;
            }
        }

        if ($subscriptions->count() <= 1) {
            return $subscriptions->first();
        }

        $resolution = $this->resolver->resolve($store, $conversation, $messageBody);

        return $resolution->product?->requiresSubscription() ? $resolution->product : null;
    }

    /**
     * Recent turns of THIS domain only: what the customer said about another
     * domain never reaches the LLM. Untagged turns (clarification questions,
     * operator messages, sales flow) are left out as well.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Store $store, string $from, ?string $domainKey): array
    {
        return WhatsAppMessage::where('store_id', $store->id)
            ->where('customer_phone', $from)
            ->where('domain_key', $domainKey)
            ->orderBy('created_at', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (WhatsAppMessage $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Product>  $options
     */
    private function clarificationText(Collection $options): string
    {
        $showDomain = $options->pluck('domain_key')->unique()->count() > 1;

        $lines = $options->values()->map(function (Product $product, int $index) use ($showDomain) {
            $label = $showDomain ? " ({$this->domains->get($product->domain_key, $product->store_id)->label()})" : '';

            return ($index + 1).". {$product->name}{$label}";
        });

        return "¿Sobre cuál de estas opciones quieres hablar?\n"
            .$lines->implode("\n")
            ."\n\nResponde con el número o el nombre.";
    }

    /**
     * @param  string|null  $domainKey  null for clarification turns, which
     *                                  belong to no single domain
     */
    private function reply(Store $store, string $from, string $messageBody, string $answer, ?string $domainKey = null): void
    {
        $answer = $this->withWelcome($answer);

        WhatsAppMessage::create([
            'store_id' => $store->id,
            'customer_phone' => $from,
            'role' => 'user',
            'domain_key' => $domainKey,
            'content' => $messageBody,
        ]);

        $assistantMessage = WhatsAppMessage::create([
            'store_id' => $store->id,
            'customer_phone' => $from,
            'role' => 'assistant',
            'domain_key' => $domainKey,
            'content' => $answer,
        ]);

        $wamid = WhatsAppService::sendMessage($from, $answer, $store);

        if ($wamid) {
            WhatsAppStatusTracker::trackMessage($assistantMessage->id, $wamid);
        }
    }

    /**
     * The store's welcome when this customer never wrote to it (no turn of
     * theirs is stored yet), or null; it is then sent once, with the first
     * reply. Without a configured welcome, nothing changes.
     */
    private function welcomeFor(Store $store, string $from): ?string
    {
        $welcome = trim((string) $store->welcome_message);

        if ($welcome === '' || WhatsAppMessage::where('store_id', $store->id)->where('customer_phone', $from)->exists()) {
            return null;
        }

        return $welcome;
    }

    /** The reply, preceded by the pending welcome the first time only. */
    private function withWelcome(string $text): string
    {
        if ($this->welcome === null) {
            return $text;
        }

        $text = $this->welcome."\n\n".$text;
        $this->welcome = null;

        return $text;
    }
}
