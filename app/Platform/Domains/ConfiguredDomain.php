<?php

namespace App\Platform\Domains;

use App\Platform\Contracts\DomainModule;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactTypes;
use App\Platform\Memory\FactValidation;
use InvalidArgumentException;

/**
 * A domain configured as data (a store's topic) instead of a PHP module.
 * Everything it declares comes from the panel: label, vocabulary,
 * instructions and facts. Its own facts are validated by their declared
 * basic type. It has no capabilities: deterministic capabilities are code,
 * registered by the Core.
 *
 * The instructions are untrusted text: they only ever reach the LLM prompt
 * (neutralized, under the Core rules), never code or tools.
 */
final class ConfiguredDomain implements DomainModule
{
    /**
     * @param  list<string>  $vocabulary
     * @param  list<array{scope: string, key: string, type?: string, question?: string, label?: string, sensitive?: bool}>  $facts
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $vocabulary,
        private readonly string $instructions,
        private readonly array $facts,
        private readonly FactTypes $types,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function vocabulary(): array
    {
        return $this->vocabulary;
    }

    public function promptRules(): string
    {
        return $this->instructions;
    }

    public function facts(): array
    {
        return array_map(fn (array $fact) => $fact['scope'] === 'person'
            ? FactDefinition::person($fact['key'])
            : FactDefinition::domain($fact['key'], (string) $fact['question'], (bool) ($fact['sensitive'] ?? false), $fact['label'] ?? null),
            $this->facts);
    }

    public function validateFact(string $key, string $value): FactValidation
    {
        foreach ($this->facts as $fact) {
            if ($fact['scope'] === 'domain' && $fact['key'] === $key) {
                return $this->types->validate((string) $fact['type'], $value);
            }
        }

        throw new InvalidArgumentException("Topic [{$this->key}] declares no fact [{$key}] of its own.");
    }

    public function capabilities(): array
    {
        return [];
    }
}
