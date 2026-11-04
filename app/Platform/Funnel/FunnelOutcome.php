<?php

namespace App\Platform\Funnel;

/**
 * What follows a model's reply in a conversational stage, once the Runner
 * validated it: the operation of the Core to run (if any), the configured text
 * to add, and where the conversation goes.
 */
final class FunnelOutcome
{
    public function __construct(
        public readonly string $nextStage,
        public readonly int $interactions,
        public readonly ?string $action = null,
        public readonly ?string $text = null,
    ) {}
}
