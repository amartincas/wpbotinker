<?php

namespace App\Platform\Contracts;

/**
 * A domain (astrology, finance, ...) plugged into the conversation
 * pipeline. The Core only talks to domains through this contract and never
 * knows what a concrete domain does.
 *
 * Deliberately minimal for now: facts, capabilities and knowledge sources
 * are added when a phase actually needs them.
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
}
