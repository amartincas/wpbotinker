<?php

namespace App\Platform\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\CommercialClaims;
use App\Platform\Contracts\DomainModule;
use App\Platform\Funnel\FunnelDefinition;
use App\Platform\Funnel\FunnelStage;
use Illuminate\Support\Collection;

/**
 * What a funnel stage may read, for this customer. Three levels: commercial
 * (public), sample (authorized for free replies and demonstrations) and
 * knowledge (subscribers only). The stage's configuration can only narrow
 * what the customer's access allows, and knowledge is never within reach of a
 * customer without access, however the stage is configured. Whether free
 * replies are being spent decides nothing here.
 */
class KnowledgeAccess
{
    public function __construct(
        private readonly KnowledgeRetriever $retriever,
        private readonly KnowledgePlanner $planner,
        private readonly CommercialClaims $claims,
    ) {}

    /**
     * @return list<string> the source purposes allowed
     */
    public function allowedPurposes(AccessDecision $access, FunnelStage $stage): array
    {
        // A customer with access reads the service's knowledge, outside any
        // funnel: sample content is a subset meant for who has no access.
        if ($access->allowed) {
            return [KnowledgeSource::PURPOSE_KNOWLEDGE];
        }

        return array_values(array_filter([
            $stage->uses(FunnelDefinition::CONTEXT_COMMERCIAL) ? KnowledgeSource::PURPOSE_COMMERCIAL : null,
            $stage->uses(FunnelDefinition::CONTEXT_SAMPLE) ? KnowledgeSource::PURPOSE_SAMPLE : null,
        ]));
    }

    /**
     * The sections of the allowed sources that fit the message, chosen by
     * KnowledgePlanner: nothing is consulted for a level without sources, and
     * a selection that fails leaves the reply without that material.
     *
     * @param  array<string, mixed>  $facts
     * @return array{reference: Collection<int, KnowledgeChunk>, verbatim: list<string>} what the model may word, and
     *                                                                                   what goes out exactly as configured
     */
    public function forStage(Store $store, DomainModule $domain, Product $product, AccessDecision $access, FunnelStage $stage, string $query, array $facts): array
    {
        $reference = collect();
        $verbatim = [];

        // Stages only serve customers without access; a stage never reads knowledge.
        foreach (array_diff($this->allowedPurposes($access, $stage), [KnowledgeSource::PURPOSE_KNOWLEDGE]) as $purpose) {
            if ($this->retriever->headings($store, $domain->key(), $product, purpose: $purpose) === []) {
                continue;
            }

            $plan = $purpose === KnowledgeSource::PURPOSE_COMMERCIAL
                ? $this->planner->planCommercial($store, $domain, $product, $query, $facts)
                : $this->planner->planSample($store, $domain, $product, $query, $facts);

            foreach ($plan->fragments as $chunk) {
                if ($chunk->source->delivery !== KnowledgeSource::DELIVERY_VERBATIM) {
                    $reference->push($chunk);
                } elseif ($this->claims->amount($chunk->content) === null) {
                    $verbatim[] = trim($chunk->content);
                }
            }
        }

        return ['reference' => $reference, 'verbatim' => $verbatim];
    }
}
