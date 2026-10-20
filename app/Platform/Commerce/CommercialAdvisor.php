<?php

namespace App\Platform\Commerce;

use App\Factories\AIServiceFactory;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Contracts\DomainModule;
use App\Platform\Conversation\PromptAssembler;
use App\Platform\Knowledge\KnowledgePlanner;
use App\Platform\Knowledge\KnowledgeRetriever;
use App\Platform\Memory\PersonFacts;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A commercial reply for a customer without access, from the product's
 * configured resources: commercial knowledge sources of the product (and
 * of its topic), selected for this message by KnowledgePlanner under the
 * product's commercial instructions. The Core brings selection, isolation
 * and deterministic limits; the selling itself is configuration.
 *
 * - Opt-in: without commercial instructions or commercial sources, nothing
 *   changes (no extra AI call); every such exit is logged with its reason
 *   (COMMERCIAL_NOT_USED: store, product and reason only).
 * - Only the customer's non-sensitive facts reach it: it explains the
 *   service, it never applies it to the customer's case.
 * - Never with access or with an open payment, and never for prices,
 *   payments or payment status: CommerceResponder answered those first.
 * - "verbatim" resources are sent exactly as written, unless they state a
 *   price or amount (CommercialClaims::amount()): then that fragment is
 *   left out. "reference" ones are worded by the AI, which never sees
 *   prices nor the conversation's history (only the current message and
 *   the selected resources), so no paid or sample content can resurface.
 *   A wording that states a price, an amount or a condition only the Core
 *   may state (CommercialClaims::violation()) is discarded.
 * - Choosing no resource, or any failure, returns null: the caller keeps
 *   its current reply. The offer (plans, prices) is always the caller's
 *   deterministic text, after this one.
 */
class CommercialAdvisor
{
    public function __construct(
        private readonly KnowledgeRetriever $retriever,
        private readonly KnowledgePlanner $planner,
        private readonly PromptAssembler $prompts,
        private readonly CommercialClaims $claims,
        private readonly PersonFacts $personFacts,
    ) {}

    /**
     * @param  array<string, mixed>  $facts  fact key => value the domain may read
     * @return string|null the commercial part of the reply, or null to keep the current one
     */
    public function respond(Store $store, DomainModule $domain, Product $product, AccessDecision $access, string $message, array $facts): ?string
    {
        $notApplicable = match (true) {
            $access->allowed => 'has_access',
            $access->openPayment !== null => 'open_payment',
            ! $product->requiresSubscription() => 'not_subscription',
            trim((string) $product->commercial_instructions) === '' => 'no_instructions',
            default => null,
        };

        if ($notApplicable !== null) {
            Log::info('COMMERCIAL_NOT_USED', ['store_id' => $store->id, 'product_id' => $product->id, 'reason' => $notApplicable]);

            return null;
        }

        if ($this->retriever->headings($store, $domain->key(), $product, purpose: KnowledgeSource::PURPOSE_COMMERCIAL) === []) {
            Log::info('COMMERCIAL_NOT_USED', ['store_id' => $store->id, 'product_id' => $product->id, 'reason' => 'no_resources']);

            return null;
        }

        // Selling never reads what the service needs: sensitive facts (and
        // any fact the topic does not define) stay out of the selector and
        // of the wording, so the reply cannot become the service itself.
        $facts = $this->shareableFacts($domain, $facts);

        $plan = $this->planner->planCommercial($store, $domain, $product, $message, $facts);

        if ($plan->fragments->isEmpty()) {
            Log::info('COMMERCIAL_NOT_USED', ['store_id' => $store->id, 'product_id' => $product->id, 'reason' => $plan->status]);

            return null;
        }

        $resources = $plan->fragments->load('source');
        [$verbatim, $reference] = $resources->partition(fn (KnowledgeChunk $chunk) => $chunk->source->delivery === KnowledgeSource::DELIVERY_VERBATIM);
        $parts = [];

        if ($reference->isNotEmpty()) {
            try {
                $worded = trim(AIServiceFactory::make($store)->getResponse(
                    $message,
                    $this->prompts->buildCommercial($store, $domain, $product, $facts, $reference->values()),
                    [], // never the history: it may hold the paid service or the sample
                ));
            } catch (Throwable $e) {
                Log::warning('COMMERCIAL_ANSWER_FAILED', ['store_id' => $store->id, 'product_id' => $product->id, 'error' => $e::class]);

                return null;
            }

            $violation = $worded === '' ? 'empty' : $this->claims->violation($worded);

            if ($violation !== null) {
                Log::warning('COMMERCIAL_ANSWER_DISCARDED', ['store_id' => $store->id, 'product_id' => $product->id, 'reason' => $violation]);

                return null;
            }

            $parts[] = $worded;
        }

        $sent = [];

        foreach ($verbatim as $chunk) {
            if ($reason = $this->claims->amount($chunk->content)) {
                // Only identifiers: the fragment itself stays out of the log.
                Log::warning('COMMERCIAL_VERBATIM_SKIPPED', ['store_id' => $store->id, 'product_id' => $product->id, 'knowledge_chunk_id' => $chunk->id, 'reason' => $reason]);

                continue;
            }

            $parts[] = trim($chunk->content);
            $sent[] = $chunk->id;
        }

        if ($parts === []) {
            return null;
        }

        Log::info('COMMERCIAL_ANSWERED', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'knowledge_chunk_ids' => [...$reference->pluck('id')->all(), ...$sent],
            'verbatim' => count($sent),
        ]);

        return implode("\n\n", $parts);
    }

    /**
     * The facts a commercial reply may read: those the topic defines and
     * marks as not sensitive (person facts by the Core catalog, the topic's
     * own facts by their definition). Anything else is left out.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function shareableFacts(DomainModule $domain, array $facts): array
    {
        $definitions = collect($domain->facts())->keyBy('key');

        return array_filter($facts, function (string $key) use ($definitions) {
            $fact = $definitions->get($key);

            if ($fact === null) {
                return false;
            }

            return ! ($fact->isPerson() ? $this->personFacts->isSensitive($key) : (bool) $fact->sensitive);
        }, ARRAY_FILTER_USE_KEY);
    }
}
