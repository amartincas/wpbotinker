<?php

namespace App\Platform\Memory;

/**
 * A fact a domain knows how to ask for and use (see DomainModule::facts()).
 *
 * Person facts only reference the Core catalog: their question, type and
 * sensitivity come from config('botinker.person_facts'), never from the
 * domain, so two domains cannot give the same fact two meanings.
 */
final class FactDefinition
{
    private function __construct(
        public readonly string $key,
        public readonly FactScope $scope,
        public readonly ?string $question,
        public readonly ?bool $sensitive,
    ) {}

    /** A fact of the Core person catalog (question and sensitivity come from there). */
    public static function person(string $key): self
    {
        return new self($key, FactScope::Person, null, null);
    }

    /** A fact owned by the declaring domain; validated by DomainModule::validateFact(). */
    public static function domain(string $key, string $question, bool $sensitive = false): self
    {
        return new self($key, FactScope::Domain, $question, $sensitive);
    }

    public function isPerson(): bool
    {
        return $this->scope === FactScope::Person;
    }
}
