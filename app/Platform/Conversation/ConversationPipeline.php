<?php

namespace App\Platform\Conversation;

use App\Factories\AIServiceFactory;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Platform\Capabilities\CapabilityRunner;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgeRetriever;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactCollector;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Conversation flow for stores in 'knowledge' mode (see
 * App\Models\Store::MODE_KNOWLEDGE). It only orchestrates Core services, in
 * order, and holds no domain or memory rules of its own:
 *
 *   FactCollector (pending answer) → ProductResolver (or clarification)
 *   → FactCollector (missing facts) → CustomerMemory (visible facts)
 *   → CapabilityRunner (computed result) → KnowledgeRetriever
 *   → PromptAssembler → LLM → reply.
 *
 * It never touches the sales flow (leads, Conversions API, catalog images).
 */
class ConversationPipeline
{
    private const HISTORY_LIMIT = 10;

    public function __construct(
        private readonly ProductResolver $resolver,
        private readonly DomainRegistry $domains,
        private readonly CustomerMemory $memory,
        private readonly FactCollector $facts,
        private readonly KnowledgeRetriever $knowledge,
        private readonly PromptAssembler $prompts,
        private readonly CapabilityRunner $capabilities,
    ) {}

    public function handle(Store $store, string $from, string $messageBody, ?int $adProductId = null, ?string $wamid = null): void
    {
        $conversation = Conversation::firstOrCreate(
            ['store_id' => $store->id, 'customer_phone' => $from],
            ['last_session_at' => now()],
        );
        $customer = $this->memory->customerFor($store, $from);

        $pending = $this->facts->answerPending($conversation, $customer, $messageBody, $wamid);

        if ($pending->reply !== null) {
            $this->reply($store, $from, $messageBody, $pending->reply, $pending->replyDomainKey);

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

        $domain = $product ? $this->domains->get($product->domain_key) : null;

        $conversation->update(['current_product_id' => $product?->id, 'pending_state' => null]);

        if ($product && $domain && ($question = $this->facts->require($conversation, $customer, $product, $domain, $pending->rejectionFor($product->id)))) {
            Log::info('PIPELINE_FACT_REQUESTED', ['store_id' => $store->id, 'customer_phone' => $from, 'domain_key' => $domain->key()]);

            $this->reply($store, $from, $messageBody, $question, $domain->key());

            return;
        }

        $facts = $domain ? $this->memory->visibleFacts($customer, $domain) : [];
        $computed = $product && $domain ? $this->capabilities->run($customer, $product, $domain) : null;
        $fragments = $domain ? $this->knowledge->retrieve($store, $domain->key(), $product, $messageBody) : collect();

        $answer = AIServiceFactory::make($store)->getResponse(
            $messageBody,
            $this->prompts->build($store, $domain, $product, $facts, $fragments, $computed),
            $this->history($store, $from, $domain?->key()),
        );

        Log::info('PIPELINE_ANSWERED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'domain_key' => $domain?->key(),
            'product_id' => $product?->id,
            'fact_keys' => array_keys($facts),
            'capability_key' => $computed ? $product->capability_key : null,
            'knowledge_chunk_ids' => $fragments->pluck('id')->all(),
        ]);

        $this->reply($store, $from, $messageBody, $answer, $domain?->key());
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
            $label = $showDomain ? " ({$this->domains->get($product->domain_key)->label()})" : '';

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
}
