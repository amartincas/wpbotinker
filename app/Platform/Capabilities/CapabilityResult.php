<?php

namespace App\Platform\Capabilities;

/**
 * Output of a deterministic capability: structured data to keep, and the
 * summary the LLM receives as a fact to explain — never to recompute.
 *
 * knowledgeKeys optionally name the knowledge entries (exact section
 * headings) that explain this result, in priority order. When present, the
 * pipeline retrieves exactly those entries instead of searching with the
 * customer's message.
 */
final class CapabilityResult
{
    /** @var list<string> */
    public readonly array $knowledgeKeys;

    /**
     * @param  array<string, mixed>  $data  JSON-serializable
     * @param  list<string>  $knowledgeKeys
     */
    public function __construct(
        public readonly array $data,
        public readonly string $summary,
        array $knowledgeKeys = [],
    ) {
        $keys = [];

        foreach ($knowledgeKeys as $key) {
            if (is_string($key) && trim($key) !== '' && ! in_array(trim($key), $keys, true)) {
                $keys[] = trim($key);
            }
        }

        $this->knowledgeKeys = $keys;
    }
}
