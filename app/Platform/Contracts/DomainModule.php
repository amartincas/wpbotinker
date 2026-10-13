<?php

namespace App\Platform\Contracts;

use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactValidation;

/**
 * A domain (astrology, finance, ...) plugged into the conversation
 * pipeline. The Core only talks to domains through this contract and never
 * knows what a concrete domain does: a domain specializes the conversation
 * only through what it declares here.
 *
 * Modules never read customer memory themselves: facts reach them as
 * arguments (validateFact(), Capability::run()). Knowledge is data scoped
 * by domain, not something a module declares.
 */
interface DomainModule
{
    /** Stable identifier, matches products.domain_key and the config key. */
    public function key(): string;

    /** Human-readable name, shown to customers in clarification questions. */
    public function label(): string;

    /**
     * Lowercase terms that identify the domain in a customer message when
     * no product is named, e.g. ['horoscopo', 'signo'].
     *
     * @return list<string>
     */
    public function vocabulary(): array;

    /** Domain rules added to the LLM prompt, whatever the product. */
    public function promptRules(): string;

    /**
     * Facts the domain can ask for and use: references to the Core person
     * catalog (FactDefinition::person()) and facts of its own
     * (FactDefinition::domain()). A domain without facts returns [].
     *
     * @return list<FactDefinition>
     */
    public function facts(): array;

    /**
     * Validates and normalizes a value for one of the domain's OWN facts.
     * Person facts are validated by the Core catalog, never here.
     */
    public function validateFact(string $key, string $value): FactValidation;

    /**
     * Deterministic capabilities of the domain, resolved by the container.
     * A domain without capabilities returns [].
     *
     * @return list<class-string<Capability>>
     */
    public function capabilities(): array;
}
