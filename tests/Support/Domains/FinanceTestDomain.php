<?php

namespace Tests\Support\Domains;

use App\Platform\Contracts\DomainModule;

/**
 * Third fictitious domain, to test topic changes away from an active
 * product of another domain.
 */
class FinanceTestDomain implements DomainModule
{
    public function key(): string
    {
        return 'finance';
    }

    public function label(): string
    {
        return 'Finanzas';
    }

    public function vocabulary(): array
    {
        return ['ahorrar', 'ahorro', 'presupuesto', 'deudas', 'finanzas', 'invertir'];
    }

    public function promptRules(): string
    {
        return 'REGLA-FINANZAS: no prometas rendimientos.';
    }
}
