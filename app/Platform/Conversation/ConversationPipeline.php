<?php

namespace App\Platform\Conversation;

use App\Factories\AIServiceFactory;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Platform\Contracts\DomainModule;
use App\Platform\Domains\DomainRegistry;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Conversation flow for stores in 'knowledge' mode (see
 * App\Models\Store::MODE_KNOWLEDGE): resolve the product → ask when the
 * message is ambiguous → otherwise answer with the store persona, the
 * domain rules and the product instructions.
 *
 * Skeleton on purpose: no knowledge retrieval, customer memory or domain
 * capabilities yet. It never touches the sales flow (leads, Conversions
 * API, catalog images).
 */
class ConversationPipeline
{
    private const HISTORY_LIMIT = 10;

    public function __construct(
        private readonly ProductResolver $resolver,
        private readonly DomainRegistry $domains,
    ) {}

    public function handle(Store $store, string $from, string $messageBody, ?int $adProductId = null): void
    {
        $conversation = Conversation::firstOrCreate(
            ['store_id' => $store->id, 'customer_phone' => $from],
            ['last_session_at' => now()],
        );

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
        $domain = $product ? $this->domains->get($product->domain_key) : null;

        $conversation->update(['current_product_id' => $product?->id, 'pending_state' => null]);

        $answer = AIServiceFactory::make($store)->getResponse(
            $messageBody,
            $this->systemPrompt($store, $domain, $product),
            $this->history($store, $from, $domain?->key()),
        );

        Log::info('PIPELINE_ANSWERED', [
            'store_id' => $store->id,
            'customer_phone' => $from,
            'domain_key' => $domain?->key(),
            'product_id' => $product?->id,
        ]);

        $this->reply($store, $from, $messageBody, $answer, $domain?->key());
    }

    private function systemPrompt(Store $store, ?DomainModule $domain, ?Product $product): string
    {
        $prompt = trim($store->system_prompt ?? '') ?: 'You are an assistant.';

        if ($domain) {
            $prompt .= "\n\n### DOMINIO: {$domain->label()}\n".trim($domain->promptRules());
        }

        if ($product) {
            $prompt .= "\n\n### PRODUCTO: {$product->name}";

            foreach ([
                'Descripción' => $product->description,
                'Instrucciones' => $product->ai_sales_strategy,
                'Reglas' => $product->faq_context,
            ] as $label => $value) {
                if (filled($value)) {
                    $prompt .= "\n{$label}: ".trim($value);
                }
            }
        }

        return $prompt;
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
