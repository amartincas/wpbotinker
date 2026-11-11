<?php

namespace App\Platform\Funnel;

use App\Models\Conversation;
use App\Models\ConversationFunnelState;
use App\Models\Product;
use App\Models\SalesFunnel;
use App\Platform\Commerce\AccessDecision;
use App\Platform\Commerce\CommerceResponder;
use App\Platform\Commerce\OpeningMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Interprets a published funnel: reads where the conversation is, evaluates
 * the configured rules and decides what comes next. It knows no product: all
 * it reads is the definition. It never sends a message, charges or grants
 * access: the pipeline executes its decisions with the Core's services, and
 * the state only moves once the customer got the reply.
 *
 * Rules that need no model (phrases, a plan named, limits) are evaluated
 * first, so they cost no call; intents come in the same call as the reply. An
 * uncertain intent moves nothing: the conversation stays in its stage.
 */
class SalesFunnelRunner
{
    public function __construct(
        private readonly FunnelStates $states,
        private readonly OpeningMessage $openings,
        private readonly CommerceResponder $commerce,
    ) {}

    /** A funnel applies to a customer without access; the rest is the Core's. */
    public function appliesTo(Product $product, AccessDecision $access): bool
    {
        return $product->requiresSubscription() && ! $access->allowed && $product->activeFunnel() !== null;
    }

    /**
     * A funnel is for who never had the product. An expired or revoked
     * subscription is not a prospect: that customer gets the Core's own answer
     * about their state and how to renew, and no opening nor free replies.
     */
    public function isProspect(AccessDecision $access): bool
    {
        return $access->reason === AccessDecision::NO_SUBSCRIPTION;
    }

    /**
     * @param  string  $query  what the customer asked: differs from $message when it only picked an option
     */
    public function begin(Conversation $conversation, Product $product, SalesFunnel $funnel, AccessDecision $access, string $message, string $query): FunnelStep
    {
        $definition = $funnel->funnelDefinition();
        $state = $this->states->find($conversation, $product);

        if ($state === null && ($opening = $this->openings->for($product, $query)) !== null) {
            return FunnelStep::literal($definition, $opening, $definition->startStage, $definition->openingConsumesFreeReply && $definition->freeRepliesLimit > 0, isOpening: true);
        }

        [$current, $interactions] = $this->position($definition, $state);
        $exhausted = $definition->freeRepliesLimit > 0 && ! $access->sampleAvailable;
        $visited = [];

        while (true) {
            if (isset($visited[$current->key])) {
                // A configuration going in circles never loops: it stops where it is.
                return $current->isMessage() ? FunnelStep::blocked($definition) : FunnelStep::converse($definition, $current, $interactions, false);
            }

            $visited[$current->key] = true;

            if ($current->isMessage()) {
                return FunnelStep::literal($definition, $current->text, (string) $current->next, $current->usesFreeReplies);
            }

            $rule = $this->deterministicRule($current, $product, $message, $interactions, $exhausted);

            if ($rule !== null && $rule['action'] !== FunnelDefinition::ACTION_STAY) {
                if ($rule['action'] !== FunnelDefinition::ACTION_GO_TO) {
                    $next = $rule['stage'] ?? $current->key;

                    return FunnelStep::action($definition, $rule['action'], $next, $next === $current->key ? $interactions : 0);
                }

                $current = $definition->stage($rule['stage']);
                $interactions = 0;

                continue;
            }

            // In a guided funnel every message is first a commercial turn, with
            // or without free replies left: the Core gives a free reply only
            // to a request for content (ConversationPipeline::commercialTurn()).
            if ($current->usesFreeReplies && $definition->guided) {
                $action = GuidedFunnel::commercialAction($message);

                return $action !== null
                    ? FunnelStep::action($definition, $action, $current->key, $interactions)
                    : FunnelStep::read($definition, $current, $interactions);
            }

            if ($current->usesFreeReplies && $exhausted) {
                $target = $definition->onFreeRepliesExhausted !== null ? $definition->stage($definition->onFreeRepliesExhausted) : null;

                if ($target === null || isset($visited[$target->key])) {
                    return FunnelStep::blocked($definition);
                }

                $current = $target;
                $interactions = 0;

                continue;
            }

            return FunnelStep::converse($definition, $current, $interactions, $current->usesFreeReplies);
        }
    }

    /**
     * What follows the model's reply. Rules go in their configured order; an
     * intent counts only if the model is sure enough.
     */
    public function afterReply(FunnelStep $step, StageReply $reply): FunnelOutcome
    {
        $definition = $step->definition;
        $stage = $step->stage;
        $interactions = $step->interactions + 1;

        foreach ($stage->rules as $rule) {
            $matches = match ($rule['condition']) {
                FunnelDefinition::WHEN_INTENT => $reply->intent === $rule['value'] && $reply->confidence >= $rule['min_confidence'],
                FunnelDefinition::WHEN_ANY_REPLY => true,
                default => false,
            };

            if (! $matches || $rule['action'] === FunnelDefinition::ACTION_STAY) {
                if ($matches) {
                    break;
                }

                continue;
            }

            if ($rule['action'] === FunnelDefinition::ACTION_GO_TO) {
                return $this->moveTo($definition, $rule['stage']);
            }

            $next = $rule['stage'] ?? $stage->key;

            return new FunnelOutcome($next, $next === $stage->key ? $interactions : 0, $rule['action']);
        }

        if ($stage->maxInteractions !== null && $interactions >= $stage->maxInteractions) {
            return $this->moveTo($definition, (string) $stage->onLimit);
        }

        return new FunnelOutcome($stage->key, $interactions);
    }

    /**
     * Which read a guided funnel's commercial turn may take for this message
     * (FunnelStates::takeRead()), or null: the after_limit_reads, then a grace
     * read per free reply left ($freeLeft), then one per reactivation sent.
     * Without certainty there is none: the customer gets the Core's reply
     * instead, and no call goes unaccounted.
     */
    public function takeLimitRead(Conversation $conversation, Product $product, SalesFunnel $funnel, string $stage, ?string $wamid, int $freeLeft = 0, ?string $reactivationEpisode = null): ?string
    {
        try {
            return $this->states->takeRead($conversation, $product, $funnel, $stage, $wamid, max(0, (int) config('botinker.sales_funnels.guided.after_limit_reads', 5)), max(0, $freeLeft), $reactivationEpisode);
        } catch (Throwable $e) {
            Log::error('FUNNEL_LIMIT_READ_NOT_TAKEN', ['conversation_id' => $conversation->id, 'product_id' => $product->id, 'error' => $e::class]);

            return null;
        }
    }

    /**
     * A commercial turn that became a free reply gives its read back: the
     * free replies are bounded by their own limit. A failure only leaves the
     * read spent, which is the safe side.
     */
    public function releaseLimitRead(Conversation $conversation, Product $product, ?string $wamid, string $kind = FunnelStates::READ): void
    {
        try {
            $this->states->releaseLimitRead($conversation, $product, $wamid, $kind);
        } catch (Throwable $e) {
            Log::error('FUNNEL_LIMIT_READ_NOT_RELEASED', ['conversation_id' => $conversation->id, 'product_id' => $product->id, 'error' => $e::class]);
        }
    }

    /**
     * Only after the customer got the reply, and outside any transaction (see
     * FunnelStates). The message already went out: a failure here must not
     * fail the job, or a retry would send it again.
     */
    public function advance(Conversation $conversation, Product $product, SalesFunnel $funnel, string $stage, int $interactions): void
    {
        try {
            $this->states->save($conversation, $product, $funnel, $stage, $interactions);
        } catch (Throwable $e) {
            Log::error('FUNNEL_STATE_NOT_SAVED', ['conversation_id' => $conversation->id, 'product_id' => $product->id, 'stage' => $stage, 'error' => $e::class]);
        }
    }

    /**
     * A stage reached after a reply: a message stage goes out in that same
     * reply, so the customer never gets two messages in a row.
     */
    private function moveTo(FunnelDefinition $definition, string $key): FunnelOutcome
    {
        $target = $definition->stage($key);

        return $target->isMessage()
            ? new FunnelOutcome((string) $target->next, 0, null, $target->text)
            : new FunnelOutcome($target->key, 0);
    }

    /**
     * The conversation's stage in this version. A stage a newer version no
     * longer has goes to the start, without repeating the opening.
     *
     * @return array{0: FunnelStage, 1: int}
     */
    private function position(FunnelDefinition $definition, ?ConversationFunnelState $state): array
    {
        $current = $state !== null ? $definition->stage($state->stage) : null;

        return $current !== null ? [$current, $state->stage_interactions] : [$definition->start(), 0];
    }

    /**
     * @return array{condition: string, value: mixed, min_confidence: float, action: string, stage: ?string}|null
     */
    private function deterministicRule(FunnelStage $stage, Product $product, string $message, int $interactions, bool $exhausted): ?array
    {
        $normalized = OpeningMessage::normalize($message);

        foreach ($stage->rules as $rule) {
            $matches = match ($rule['condition']) {
                FunnelDefinition::WHEN_PHRASE => in_array($normalized, array_map(OpeningMessage::normalize(...), $rule['value']), true),
                FunnelDefinition::WHEN_PLAN_NAMED => $this->commerce->choosesPlan($message, $product),
                FunnelDefinition::WHEN_INTERACTIONS => $interactions >= $rule['value'],
                FunnelDefinition::WHEN_FREE_EXHAUSTED => $exhausted,
                default => false,
            };

            if ($matches) {
                return $rule;
            }
        }

        return null;
    }
}
