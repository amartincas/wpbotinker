<?php

namespace App\Platform\Capabilities;

/**
 * Output of a deterministic capability: structured data to keep, and the
 * summary the LLM receives as a fact to explain — never to recompute.
 */
final class CapabilityResult
{
    /**
     * @param  array<string, mixed>  $data  JSON-serializable
     */
    public function __construct(
        public readonly array $data,
        public readonly string $summary,
    ) {}
}
