<?php

namespace App\Platform\Conversation;

use App\Factories\AIServiceFactory;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Models\SampleDelivery;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Platform\Capabilities\CapabilityRunner;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\AccessGate;
use App\Platform\Commerce\CommerceResponder;
use App\Platform\Commerce\CommercialAdvisor;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Commerce\FreeTurn;
use App\Platform\Commerce\OpeningMessage;
use App\Platform\Commerce\SampleLedger;
use App\Platform\Contracts\DomainModule;
use App\Platform\Contracts\ProvidesRetrievalGuide;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStep;
use App\Platform\Funnel\SalesFunnelRunner;
use App\Platform\Funnel\StageReply;
use App\Platform\Knowledge\KnowledgeAccess;
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

    /** Characters kept of the question a clarification interrupted. */
    private const MAX_ORIGIN_LENGTH = 1000;

    /** The store's welcome, still to be put before this turn's first reply (see withWelcome()). */
    private ?string $welcome = null;

    /**
     * Set by answer() when a free sample found no section of its topic for
     * the query (not a failure): the sources do not cover it.
     */
    private bool $uncovered = false;

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
        private readonly TopicClassifier $topics,
        private readonly OpeningMessage $openings,
        private readonly SalesFunnelRunner $funnels,
        private readonly KnowledgeAccess $knowledgeAccess,
        private readonly CommercialClaims $claims,
    ) {}

    public function handle(Store $store, string $from, string $messageBody, ?int $adProductId = null, ?string $wamid = null): void
    {
        $conversation = Conversation::firstOrCreate(
            ['store_id' => $store->id, 'customer_phone' => $from],
            ['last_session_at' => now()],
        );
        $customer = $this->memory->customerFor($store, $from);
        $this->welcome = $this->welcomeFor($store, $from);
        $this->uncovered = false;

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
        $clarifiedQuery = null;

        if ($product === null) {
            $resolution = $this->resolver->resolve($store, $conversation, $messageBody, $adProductId);

            // Nothing pointed to a product: a store that configured its open
            // question asks (or identifies the topic) instead of listing the catalog.
            if ($resolution->unidentified && filled($store->topic_prompt_message)) {
                $resolution = $this->identifyTopic($store, $from, $messageBody, $catalog);

                if ($resolution === null) {
                    return;
                }
            }

            if ($resolution->needsClarification()) {
                // The question asked is kept: once the customer picks an
                // option, it is what gets answered, not the choice itself.
                // A clarification asked again keeps the first question.
                $previous = $conversation->pending_state;
                $origin = ($previous['type'] ?? null) === ProductResolver::PENDING_CLARIFICATION && filled($previous['origin_message'] ?? null)
                    ? $previous['origin_message']
                    : mb_substr(trim($messageBody), 0, self::MAX_ORIGIN_LENGTH);

                $conversation->update(['pending_state' => [
                    'type' => ProductResolver::PENDING_CLARIFICATION,
                    'product_ids' => $resolution->options->pluck('id')->all(),
                    'origin_message' => $origin,
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

            // The customer only picked an option: what gets answered is the
            // question the clarification interrupted.
            if ($resolution->answersClarification && filled($conversation->pending_state['origin_message'] ?? null)) {
                $clarifiedQuery = (string) $conversation->pending_state['origin_message'];
            }
        }

        $domain = $product ? $this->domains->get($product->domain_key, $store->id) : null;

        $conversation->update(['current_product_id' => $product?->id, 'pending_state' => null]);

        // A customer without access to a product with an active funnel goes
        // through the funnel; without a funnel, nothing below changes.
        if ($product && $domain && $product->activeFunnel() !== null && $this->funnels->appliesTo($product, $access = $this->access->decide($customer, $product))) {
            $this->runFunnel($store, $conversation, $from, $messageBody, $clarifiedQuery ?? $messageBody, $customer, $product, $domain, $access, $wamid);

            return;
        }

        // Commercial access, from persisted state only. Without access and
        // without a sample to give, no facts are asked: the customer gets
        // the commercial answer straight away.
        if ($product && ($access = $this->access->decide($customer, $product)) && ! $access->allowed && ! $access->sampleAvailable) {
            $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

            return;
        }

        // What the customer asked: when this message only answered a question
        // for the product's data, or only picked an option, the query that
        // led to that question.
        $query = ($product ? $pending->originFor($product->id) : null) ?? $clarifiedQuery ?? $messageBody;

        if ($product && $domain && ($question = $this->facts->require($conversation, $customer, $product, $domain, $pending->rejectionFor($product->id), $query))) {
            Log::info('PIPELINE_FACT_REQUESTED', ['store_id' => $store->id, 'customer_phone' => $from, 'domain_key' => $domain->key()]);

            $this->reply($store, $from, $messageBody, $question, $domain->key());

            return;
        }

        // Checked again at the entry of the protected service: the state may
        // have changed while facts were being collected. Without access, the
        // only way in is the free sample, reserved before it is generated.
        $sample = null;
        $freeTurn = null;

        if ($product && ! ($access = $this->access->decide($customer, $product))->allowed) {
            $sample = $access->sampleAvailable ? $this->samples->reserve($customer, $product) : null;

            if ($sample === null) {
                $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

                return;
            }

            $freeTurn = new FreeTurn($sample->turns_used, SampleLedger::limit());
        }

        // The product's configured opening, exactly as written, is the first
        // free reply when the message is one of its triggers; it replaces
        // the store's generic welcome.
        $opening = $freeTurn?->number === 1 ? $this->openings->for($product, $messageBody) : null;

        if ($opening !== null) {
            $this->welcome = null;

            Log::info('PIPELINE_OPENING', ['store_id' => $store->id, 'customer_phone' => $from, 'product_id' => $product->id]);
        }

        try {
            // A retry of the same message reuses the sample already generated.
            $answer = $opening ?? ($sample ? $this->rememberedSample($store, $wamid) : null);
            $answer ??= $this->answer($store, $from, $messageBody, $query, $customer, $product, $domain, $freeTurn);
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

            // The sources do not cover the query: say so (the sample stays
            // available). Anything else failed: the customer may retry.
            $text = $this->uncovered && filled($store->topic_unavailable_message)
                ? trim($store->topic_unavailable_message)
                : "No pude completar tu solicitud sobre «{$product->name}» en este momento. Intenta de nuevo en unos minutos.";

            $this->reply($store, $from, $messageBody, $text, $domain?->key());

            return;
        }

        if ($sample) {
            // Only the last free reply carries the offer; the others leave
            // the conversation open.
            $this->rememberSample($store, $wamid, $answer);
            $this->deliverSample($store, $from, $messageBody, $freeTurn->isLast() ? $answer."\n\n".$this->commerce->afterSampleText($product) : $answer, $domain?->key(), $sample, $wamid);

            if ($freeTurn->isLast()) {
                $this->commerce->recordOfferShown($conversation, $product);
            }

            return;
        }

        $this->reply($store, $from, $messageBody, $answer, $domain?->key());
    }

    /**
     * Executes what the funnel's Runner decides, with the same services as
     * everything else: AccessGate already decided, SampleLedger reserves the
     * free replies, CommerceResponder owns plans and payments. Who already
     * was a customer, or has a payment open, gets the Core's answer.
     *
     * Every reply goes through deliverTurn(), which fails the job if
     * WhatsApp did not take it: until then nothing is spent, recorded or
     * moved, so the queue's retry finds everything as it was. Once sent,
     * nothing fails the job, or the retry would send it again.
     */
    private function runFunnel(Store $store, Conversation $conversation, string $from, string $messageBody, string $query, $customer, Product $product, DomainModule $domain, AccessDecision $access, ?string $wamid): void
    {
        if ($access->openPayment !== null || ! $this->funnels->isProspect($access)) {
            $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);
            $this->finishPurchase($store, $conversation, $product, $access, $wamid);

            return;
        }

        $funnel = $product->activeFunnel();
        $step = $this->funnels->begin($conversation, $product, $funnel, $access, $messageBody, $query);

        Log::info('FUNNEL_STEP', ['store_id' => $store->id, 'product_id' => $product->id, 'version' => $funnel->version, 'kind' => $step->kind, 'stage' => $step->stage?->key, 'next' => $step->nextStage]);

        if ($step->kind === FunnelStep::BLOCKED) {
            $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

            return;
        }

        if ($step->kind === FunnelStep::READ) {
            $this->readAfterFreeReplies($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step, $access, $wamid);

            return;
        }

        if ($step->kind === FunnelStep::ACTION && $step->action === FunnelDefinition::ACTION_START_PAYMENT) {
            // In a guided funnel only a plan the customer chose starts a payment, even with one plan to choose from.
            $this->deliverPurchase($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step->definition, $step->definition->guided, $step->nextStage, $step->interactions);

            return;
        }

        if ($step->kind === FunnelStep::ACTION) {
            $this->deliverTurn($store, $from, $messageBody, $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $step->definition, $step->action, false), $domain->key());
            $this->afterFunnelOffer($step->action === FunnelDefinition::ACTION_SHOW_OFFER, $conversation, $product);
            $this->funnels->advance($conversation, $product, $funnel, $step->nextStage, $step->interactions);

            return;
        }

        $reservation = null;

        try {
            if ($step->kind === FunnelStep::LITERAL) {
                // A literal goes out as configured, in place of the store's welcome.
                $this->welcome = null;
                $reservation = $step->useFreeReply && $access->sampleAvailable ? $this->samples->reserve($customer, $product) : null;
                $this->deliverTurn($store, $from, $messageBody, $this->fillPlaceholders($step->text, $product), $domain->key());
                $this->afterFunnelOffer(str_contains($step->text, '{planes}'), $conversation, $product);
                $this->funnels->advance($conversation, $product, $funnel, $step->nextStage, 0);

                return;
            }

            $reservation = $step->useFreeReply ? $this->samples->reserve($customer, $product) : null;

            if ($step->useFreeReply && $reservation === null) {
                if ($step->definition->guided) {
                    $this->readAfterFreeReplies($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step, $access, $wamid);
                } else {
                    $this->replyBlocked($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);
                }

                return;
            }

            $freeTurn = $reservation !== null ? new FreeTurn($reservation->turns_used, SampleLedger::limit($product)) : null;
            $facts = $this->advisor->shareableFacts($domain, $this->memory->visibleFacts($customer, $domain));
            $knowledge = $this->knowledgeAccess->forStage($store, $domain, $product, $access, $step->stage, $query, $facts);

            // A retry of the same message reuses the answer already generated.
            $raw = $this->rememberedSample($store, $wamid);

            if ($raw === null) {
                $raw = AIServiceFactory::make($store)->getResponse(
                    $messageBody,
                    $this->prompts->buildFunnelStage($store, $domain, $product, $step->definition, $step->stage, $facts, $knowledge['reference'], $freeTurn, $query !== $messageBody ? $query : null, $step->definition->guided ? $this->commerce->planSummary($product) : null),
                    $this->history($store, $from, $domain->key()),
                );
                $this->rememberSample($store, $wamid, $raw);
            }

            $reply = StageReply::parse($raw, $step->stage->hasIntentRules(), $step->definition->intents);
            $violation = $reply->reply !== null ? $this->claims->funnelViolation($reply->reply, $product->name) : 'empty';

            Log::info('FUNNEL_REPLY', ['store_id' => $store->id, 'product_id' => $product->id, 'stage' => $step->stage->key, 'intent' => $reply->intent, 'confidence' => $reply->confidence, 'free_turn' => $freeTurn?->number, 'discarded' => $violation]);

            // A reply that cannot go out spends nothing and moves nothing.
            if ($violation !== null) {
                $this->releaseFunnelReply($reservation);
                $unavailable = filled($store->topic_unavailable_message);
                $this->deliverTurn($store, $from, $messageBody, $unavailable ? trim($store->topic_unavailable_message) : $this->commerce->funnelOffer($product, $step->definition->offerCta), $domain->key());
                $this->afterFunnelOffer(! $unavailable, $conversation, $product);
                $this->forgetSample($store, $wamid);

                return;
            }

            $outcome = $this->funnels->afterReply($step, $reply);

            // A purchase answers with the Core's own text, and is no free reply.
            if ($outcome->action === FunnelDefinition::ACTION_START_PAYMENT) {
                $this->releaseFunnelReply($reservation);
                $this->deliverPurchase($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step->definition, true, $outcome->nextStage, $outcome->interactions);
                $this->forgetSample($store, $wamid);

                return;
            }

            $parts = [$reply->reply, ...$knowledge['verbatim']];

            // A guided funnel's last free reply always closes with the offer.
            $showsOffer = $outcome->action === FunnelDefinition::ACTION_SHOW_OFFER || ($step->definition->guided && $freeTurn?->isLast());

            if ($showsOffer) {
                $parts[] = $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $step->definition, FunnelDefinition::ACTION_SHOW_OFFER, true);
            }

            if ($outcome->text !== null) {
                $parts[] = $this->fillPlaceholders($outcome->text, $product);
            }

            $this->deliverTurn($store, $from, $messageBody, implode("\n\n", $parts), $domain->key());
            $this->afterFunnelOffer($showsOffer || str_contains((string) $outcome->text, '{planes}'), $conversation, $product);
            $this->forgetSample($store, $wamid);
            $this->funnels->advance($conversation, $product, $funnel, $outcome->nextStage, $outcome->interactions);
        } catch (Throwable $e) {
            // Nothing was delivered: the free reply stays available and the state does not move.
            $this->releaseFunnelReply($reservation);

            throw $e;
        }
    }

    /** Gives back a reservation once: the variable is emptied so nothing releases it again. */
    /**
     * A guided funnel without free replies left. While the conversation has
     * reads left, one model call, without material or history, reads what the customer
     * wants and words a commercial reply that the Core validates. Without reads,
     * if the model fails or if its reply does not pass, the customer gets the
     * Core's fixed reply, which says how to see the plans and pay.
     */
    private function readAfterFreeReplies(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, DomainModule $domain, SalesFunnel $funnel, FunnelStep $step, AccessDecision $access, ?string $wamid): void
    {
        $outcome = null;
        $reply = null;
        $canRead = $this->funnels->takeLimitRead($conversation, $product, $funnel, $step->stage->key, $wamid);

        if ($canRead) {
            try {
                // A retry of the same message reuses the answer already generated.
                $raw = $this->rememberedSample($store, $wamid);

                if ($raw === null) {
                    $facts = $this->advisor->shareableFacts($domain, $this->memory->visibleFacts($customer, $domain));
                    $raw = AIServiceFactory::make($store)->getResponse(
                        $messageBody,
                        $this->prompts->buildFunnelStage($store, $domain, $product, $step->definition, $step->stage, $facts, collect(), null, null, $this->commerce->planSummary($product), freeRepliesOver: true),
                        [], // never the history: it holds what the free replies already taught
                    );
                    $this->rememberSample($store, $wamid, $raw);
                }

                $reply = StageReply::parse($raw, true, $step->definition->intents);
                $outcome = $this->funnels->afterReply($step, $reply);
            } catch (Throwable $e) {
                Log::warning('FUNNEL_LIMIT_READ_FAILED', ['store_id' => $store->id, 'product_id' => $product->id, 'error' => $e::class]);
            }
        }

        if ($outcome?->action === FunnelDefinition::ACTION_START_PAYMENT) {
            Log::info('FUNNEL_LIMIT_READ', ['store_id' => $store->id, 'product_id' => $product->id, 'intent' => $reply->intent, 'confidence' => $reply->confidence, 'action' => $outcome->action, 'discarded' => null]);
            $this->deliverPurchase($store, $conversation, $from, $messageBody, $customer, $product, $domain, $funnel, $step->definition, true, $outcome->nextStage, $outcome->interactions);
            $this->forgetSample($store, $wamid);

            return;
        }

        if ($outcome?->action === FunnelDefinition::ACTION_SHOW_OFFER) {
            Log::info('FUNNEL_LIMIT_READ', ['store_id' => $store->id, 'product_id' => $product->id, 'intent' => $reply->intent, 'confidence' => $reply->confidence, 'action' => $outcome->action, 'discarded' => null]);
            $this->deliverTurn($store, $from, $messageBody, $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $step->definition, $outcome->action, true), $domain->key());
            $this->afterFunnelOffer(true, $conversation, $product);
            $this->forgetSample($store, $wamid);
            $this->funnels->advance($conversation, $product, $funnel, $outcome->nextStage, $outcome->interactions);

            return;
        }

        $violation = $reply?->reply !== null ? $this->claims->limitReplyViolation($reply->reply, $product->name, (array) $product->aliases) : 'empty';

        if ($outcome !== null) {
            Log::info('FUNNEL_LIMIT_READ', ['store_id' => $store->id, 'product_id' => $product->id, 'intent' => $reply->intent, 'confidence' => $reply->confidence, 'action' => null, 'discarded' => $violation]);
        } elseif (! $canRead) {
            Log::info('FUNNEL_LIMIT_READS_EXHAUSTED', ['store_id' => $store->id, 'product_id' => $product->id]);
        }

        if ($outcome !== null && $violation === null) {
            // The model already invited: the plans only join if they were not just shown.
            $text = $this->commerce->offerRecentlyShown($product, $conversation)
                ? $reply->reply
                : $reply->reply."\n\n".$this->commerce->offerAfterCommercialReply($store, $product, $access, $conversation);
        } else {
            $text = trim((string) config('botinker.sales_funnels.guided.limit_reply'))."\n\n".$this->commerce->offerAfterCommercialReply($store, $product, $access, $conversation);
        }

        $this->deliverTurn($store, $from, $messageBody, $text, $domain->key());
        $this->commerce->recordOfferIfSent($conversation, $product, $text);
        $this->forgetSample($store, $wamid);
    }

    private function releaseFunnelReply(?SampleDelivery &$reservation): void
    {
        if ($reservation !== null) {
            $this->samples->release($reservation);
            $reservation = null;
        }
    }

    /** Only what the customer actually got counts as an offer shown. */
    private function afterFunnelOffer(bool $shown, Conversation $conversation, Product $product): void
    {
        if ($shown) {
            $this->commerce->recordOfferShown($conversation, $product);
        }
    }

    /**
     * A purchase: the payment exists before its message goes out. If the send
     * fails, the retry finds that payment open, never starts another, and
     * finishPurchase() takes the funnel where this turn was going.
     */
    private function deliverPurchase(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, DomainModule $domain, SalesFunnel $funnel, FunnelDefinition $definition, bool $readByModel, string $nextStage, int $interactions): void
    {
        $text = $this->funnelAction($store, $conversation, $messageBody, $customer, $product, $definition, FunnelDefinition::ACTION_START_PAYMENT, $readByModel);
        $payment = $this->access->decide($customer, $product)->openPayment;

        if ($payment !== null) {
            Cache::put($this->purchaseDestinationKey($payment), ['funnel_id' => $funnel->id, 'stage' => $nextStage, 'interactions' => $interactions], now()->addHours(CommerceResponder::PAYMENT_WINDOW_HOURS));
        }

        $this->deliverTurn($store, $from, $messageBody, $text, $domain->key());
        $this->funnels->advance($conversation, $product, $funnel, $nextStage, $interactions);

        if ($payment !== null) {
            Cache::forget($this->purchaseDestinationKey($payment));
        }
    }

    /**
     * The open payment's message went out: if the purchase that started it
     * failed to send, the funnel now moves where it was going. A funnel
     * published in between is not moved to a stage of the previous version.
     */
    private function finishPurchase(Store $store, Conversation $conversation, Product $product, AccessDecision $access, ?string $wamid): void
    {
        $destination = $access->openPayment !== null ? Cache::get($this->purchaseDestinationKey($access->openPayment)) : null;

        if ($destination === null) {
            return;
        }

        $funnel = $product->activeFunnel();

        if ($funnel?->id === $destination['funnel_id']) {
            $this->funnels->advance($conversation, $product, $funnel, $destination['stage'], $destination['interactions']);
        }

        Cache::forget($this->purchaseDestinationKey($access->openPayment));
        $this->forgetSample($store, $wamid);
    }

    private function purchaseDestinationKey(Payment $payment): string
    {
        return "funnel-purchase-destination:{$payment->id}";
    }

    /**
     * The funnel's operations, always through CommerceResponder. A purchase
     * read by the model never assumes a plan the customer did not name.
     */
    private function funnelAction(Store $store, Conversation $conversation, string $messageBody, $customer, Product $product, FunnelDefinition $definition, string $action, bool $readByModel): string
    {
        return $action === FunnelDefinition::ACTION_START_PAYMENT
            ? $this->commerce->startPurchase($store, $conversation, $customer, $product, $messageBody, $readByModel)->text
            : $this->commerce->funnelOffer($product, $definition->offerCta);
    }

    /** A configured text with the data only the Core has: the plans always come from the catalog. */
    private function fillPlaceholders(string $text, Product $product): string
    {
        return strtr($text, ['{producto}' => $product->name, '{planes}' => str_contains($text, '{planes}') ? $this->commerce->plansBlock($product) : '']);
    }

    /**
     * Sent first, recorded once WhatsApp took it, like a sample: a turn that
     * did not go out fails the job, so the queue retries it. Once sent,
     * nothing fails the job.
     */
    private function deliverTurn(Store $store, string $from, string $messageBody, string $text, ?string $domainKey): void
    {
        $text = $this->withWelcome($text);
        $sentWamid = WhatsAppService::sendMessage($from, $text, $store);

        if ($sentWamid === null) {
            throw new RuntimeException("The funnel's reply for store [{$store->id}] could not be sent.");
        }

        try {
            WhatsAppMessage::recordDeliveredTurn($store->id, $from, $domainKey, $messageBody, $text, $sentWamid);
        } catch (Throwable $e) {
            Log::error('TURN_HISTORY_NOT_SAVED', ['store_id' => $store->id, 'error' => $e::class]);
        }
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
    private function answer(Store $store, string $from, string $messageBody, string $query, $customer, ?Product $product, ?DomainModule $domain, ?FreeTurn $freeTurn): ?string
    {
        $isSample = $freeTurn !== null;
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

        // A later free reply usually answers the question the previous one
        // asked ("en el trabajo"): the customer's previous message keeps
        // the search on the subject, since a free reply must rest on the
        // sources.
        if ($freeTurn !== null && $freeTurn->number > 1 && $domain !== null && ($previous = $this->previousCustomerMessage($store, $from, $domain->key())) !== null) {
            $searchText = $previous."\n".$searchText;
        }
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

        // A domain with a retrieval guide picks the sections from the
        // customer's data and query, for the free sample and the service
        // alike: words of the message matching a title (another entity, a
        // neighbouring area) never override the guide.
        // Nothing is ever answered as if it rested on sources it does not have:
        // - selection failed: the service falls back to the sections the
        //   message found, if any, so a customer with access still gets an
        //   answer; otherwise, and always for the sample, null (the sample is
        //   released, nothing changes, the customer can retry);
        // - a free sample without sections: null, as above;
        // - a message that needs the sources but got none: a fixed reply
        //   saying so, without the LLM;
        // - a message that needs no sources (thanks, a greeting): answered
        //   normally, without fragments.
        $planned = false;

        if ($guided) {
            $plan = $this->planner->plan($store, $domain, $product, $searchText, $facts, $isSample);

            if ($plan->failed && ! $isSample && $fragments->isNotEmpty()) {
                Log::warning('PIPELINE_KNOWLEDGE_FALLBACK', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'plan_status' => $plan->status,
                ]);
            } elseif ($plan->failed || ($isSample && $plan->fragments->isEmpty())) {
                $this->uncovered = $isSample && $plan->unsupported();

                Log::warning('PIPELINE_KNOWLEDGE_UNAVAILABLE', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'sample' => $isSample,
                    'plan_status' => $plan->status,
                ]);

                return null;
            } elseif ($plan->unsupported()) {
                Log::info('PIPELINE_KNOWLEDGE_NOT_FOUND', [
                    'store_id' => $store->id,
                    'domain_key' => $domain->key(),
                    'product_id' => $product?->id,
                    'plan_status' => $plan->status,
                ]);

                return filled($store->topic_unavailable_message)
                    ? trim($store->topic_unavailable_message)
                    : "No encontré información sobre eso en las fuentes de «{$domain->label()}». ¿Puedes contarme un poco más o preguntarlo de otra forma?";
            } else {
                $fragments = $plan->fragments;
                $planned = true;
            }
        }

        $answer = AIServiceFactory::make($store)->getResponse(
            $messageBody,
            $this->prompts->build($store, $domain, $product, $facts, $fragments, $computed, $freeTurn, $query !== $messageBody ? $query : null),
            $this->history($store, $from, $domain?->key()),
        );

        Log::info('PIPELINE_ANSWERED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'domain_key' => $domain?->key(),
            'product_id' => $product?->id,
            'sample' => $isSample,
            'free_turn' => $freeTurn?->number,
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
     * resources when CommercialAdvisor finds some that fit the message. The
     * offer counts as shown only once WhatsApp took it; if it did not, the
     * job fails and the retry shows it whole.
     */
    private function replyBlocked(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, ?DomainModule $domain, AccessDecision $access): void
    {
        $text = $this->blockedText($store, $conversation, $from, $messageBody, $customer, $product, $domain, $access);

        $this->deliverTurn($store, $from, $messageBody, $text, $product->domain_key);
        $this->commerce->recordOfferIfSent($conversation, $product, $text);
    }

    private function blockedText(Store $store, Conversation $conversation, string $from, string $messageBody, $customer, Product $product, ?DomainModule $domain, AccessDecision $access): string
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
            ? $this->commerce->offerAfterCommercialReply($store, $product, $access, $conversation)
            : $this->commerce->blockedReply($store, $product, $access, $conversation);

        return $commercial !== null ? $commercial."\n\n".$offer : $offer;
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
    /** The customer's last message in this domain before the current one (not yet saved), or null. */
    private function previousCustomerMessage(Store $store, string $from, string $domainKey): ?string
    {
        $content = WhatsAppMessage::where('store_id', $store->id)
            ->where('customer_phone', $from)
            ->where('domain_key', $domainKey)
            ->where('role', 'user')
            ->latest('id')
            ->value('content');

        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    private function history(Store $store, string $from, ?string $domainKey): array
    {
        return WhatsAppMessage::where('store_id', $store->id)
            ->where('customer_phone', $from)
            ->where('domain_key', $domainKey)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (WhatsAppMessage $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();
    }

    /**
     * A message that pointed to no product, in a store that asks instead of
     * listing its catalog. Returns the product (or the real choice between
     * products) to go on with, or null once it has replied:
     * - only a commercial request ("quiero pagar"): the products to pay for;
     * - a topic the classifier recognized: its product, or a choice among
     *   that topic's products; this same message then gets the answer;
     * - no topic and nothing asked (a greeting), or the classification
     *   failed: the store's open question;
     * - no topic but something asked: the store's "not available" reply
     *   (its open question if that is not configured).
     * No product is set and no sample is used before a topic is known.
     *
     * @param  Collection<int, Product>  $catalog
     */
    private function identifyTopic(Store $store, string $from, string $messageBody, Collection $catalog): ?ProductResolution
    {
        if ($this->commerce->isProductlessRequest($messageBody)) {
            $payable = $catalog->filter(fn (Product $product) => $product->requiresSubscription())->values();

            return ProductResolution::clarify($payable->isNotEmpty() ? $payable : $catalog);
        }

        $classification = $this->topics->classify($store, $messageBody, $catalog);

        if ($classification->topicKey !== null) {
            $products = $catalog->where('domain_key', $classification->topicKey)->values();

            return $products->count() === 1 ? ProductResolution::selected($products->first()) : ProductResolution::clarify($products);
        }

        $unavailable = ! $classification->failed && $classification->request && filled($store->topic_unavailable_message);

        Log::info('PIPELINE_TOPIC_UNIDENTIFIED', [
            'store_id' => $store->id,
            'reply' => $unavailable ? 'unavailable' : 'open_question',
            'classification_failed' => $classification->failed,
        ]);

        $this->reply($store, $from, $messageBody, trim($unavailable ? $store->topic_unavailable_message : $store->topic_prompt_message));

        return null;
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
