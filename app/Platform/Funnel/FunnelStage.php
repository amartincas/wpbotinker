<?php

namespace App\Platform\Funnel;

/**
 * One validated stage of a funnel (see FunnelDefinition). A stage is an
 * objective, not a script: `message` sends its text exactly as written, `ai`
 * converses towards its goal.
 */
final class FunnelStage
{
    public const TYPE_MESSAGE = 'message';

    public const TYPE_AI = 'ai';

    /**
     * @param  list<string>  $context  the sources this stage may use (FunnelDefinition::CONTEXTS)
     * @param  list<array{condition: string, value: mixed, min_confidence: float, action: string, stage: ?string}>  $rules
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $goal,
        public readonly string $instructions,
        public readonly string $restrictions,
        public readonly string $text,
        public readonly array $context,
        public readonly bool $usesFreeReplies,
        public readonly ?int $maxInteractions,
        public readonly ?string $onLimit,
        public readonly ?string $next,
        public readonly array $rules,
    ) {}

    public function isMessage(): bool
    {
        return $this->type === self::TYPE_MESSAGE;
    }

    public function uses(string $context): bool
    {
        return in_array($context, $this->context, true);
    }

    public function hasIntentRules(): bool
    {
        return in_array(FunnelDefinition::WHEN_INTENT, array_column($this->rules, 'condition'), true);
    }
}
