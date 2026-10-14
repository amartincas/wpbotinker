<?php

namespace App\Platform\Memory;

use InvalidArgumentException;

/**
 * The Core catalog of person facts (config('botinker.person_facts')),
 * validated by their basic type (FactTypes). Domains reference these
 * facts; they never redefine or validate them.
 */
class PersonFacts
{
    public const TYPES = FactTypes::TYPES;

    /**
     * @param  array<string, array{type: string, question: string, label?: string, sensitive?: bool}>  $catalog
     */
    public function __construct(
        private readonly array $catalog,
        private readonly FactTypes $types = new FactTypes,
    ) {}

    public function has(string $key): bool
    {
        return isset($this->catalog[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->catalog);
    }

    public function type(string $key): string
    {
        return $this->entry($key)['type'];
    }

    public function question(string $key): string
    {
        return $this->entry($key)['question'];
    }

    /** Human-readable name ("fecha de nacimiento"), used in permission questions. */
    public function label(string $key): string
    {
        return $this->entry($key)['label'] ?? str_replace('_', ' ', $key);
    }

    public function isSensitive(string $key): bool
    {
        return (bool) ($this->entry($key)['sensitive'] ?? false);
    }

    public function validate(string $key, string $value): FactValidation
    {
        return $this->types->validate($this->type($key), $value);
    }

    /**
     * @return array{type: string, question: string, sensitive?: bool}
     */
    private function entry(string $key): array
    {
        $entry = $this->catalog[$key] ?? throw new InvalidArgumentException("Unknown person fact [{$key}].");

        if (! in_array($entry['type'] ?? null, self::TYPES, true)) {
            throw new InvalidArgumentException("Person fact [{$key}] has an invalid type; expected one of: ".implode(', ', self::TYPES).'.');
        }

        if (! is_string($entry['question'] ?? null) || trim($entry['question']) === '') {
            throw new InvalidArgumentException("Person fact [{$key}] needs a question.");
        }

        return $entry;
    }
}
