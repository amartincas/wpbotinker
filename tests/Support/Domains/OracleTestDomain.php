<?php

namespace Tests\Support\Domains;

use App\Platform\Contracts\DomainModule;

/**
 * Fictitious domain used only by tests: it is registered through
 * config('botinker.domains') and nothing in App\Platform knows about it.
 */
class OracleTestDomain implements DomainModule
{
    public function key(): string
    {
        return 'oracle';
    }

    public function label(): string
    {
        return 'Oráculo';
    }

    public function vocabulary(): array
    {
        return ['horoscopo', 'zodiaco', 'astros'];
    }

    public function promptRules(): string
    {
        return 'REGLA-ORACULO: responde solo sobre lecturas simbólicas.';
    }
}
