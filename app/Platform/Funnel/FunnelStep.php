<?php

namespace App\Platform\Funnel;

/**
 * What the Runner decided for a message before any model is called. The
 * pipeline executes it: it never sends, charges or grants anything here.
 */
final class FunnelStep
{
    /** Send a configured text, exactly as written. */
    public const LITERAL = 'literal';

    /** An operation of the Core: show the offer or start a purchase. */
    public const ACTION = 'action';

    /** Let the model converse towards the stage's goal. */
    public const CONVERSE = 'converse';

    /** Nothing left in the funnel for this customer: the Core's commercial reply. */
    public const BLOCKED = 'blocked';

    private function __construct(
        public readonly string $kind,
        public readonly FunnelDefinition $definition,
        public readonly ?FunnelStage $stage = null,
        public readonly ?string $nextStage = null,
        public readonly int $interactions = 0,
        public readonly ?string $text = null,
        public readonly ?string $action = null,
        public readonly bool $useFreeReply = false,
        public readonly bool $isOpening = false,
    ) {}

    public static function literal(FunnelDefinition $definition, string $text, string $nextStage, bool $useFreeReply, bool $isOpening = false): self
    {
        return new self(self::LITERAL, $definition, nextStage: $nextStage, text: $text, useFreeReply: $useFreeReply, isOpening: $isOpening);
    }

    public static function action(FunnelDefinition $definition, string $action, string $nextStage, int $interactions): self
    {
        return new self(self::ACTION, $definition, nextStage: $nextStage, interactions: $interactions, action: $action);
    }

    public static function converse(FunnelDefinition $definition, FunnelStage $stage, int $interactions, bool $useFreeReply): self
    {
        return new self(self::CONVERSE, $definition, stage: $stage, nextStage: $stage->key, interactions: $interactions, useFreeReply: $useFreeReply);
    }

    public static function blocked(FunnelDefinition $definition): self
    {
        return new self(self::BLOCKED, $definition);
    }
}
