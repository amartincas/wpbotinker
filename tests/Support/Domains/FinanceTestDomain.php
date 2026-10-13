<?php

namespace Tests\Support\Domains;

use App\Platform\Contracts\DomainModule;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactValidation;
use Tests\Support\Domains\Capabilities\SavingsTargetCapability;

/**
 * Fictitious domain used only by tests. Declares a numeric fact of its own,
 * references the same person fact as 'oracle' (to be shared with
 * permission in a later phase) and one capability using both.
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

    public function facts(): array
    {
        return [
            FactDefinition::domain('monthly_income', '¿Cuál es tu ingreso mensual?', sensitive: true),
            FactDefinition::person('birth_date'),
        ];
    }

    public function validateFact(string $key, string $value): FactValidation
    {
        if ($key !== 'monthly_income') {
            return FactValidation::invalid('Ese dato no corresponde a este servicio.');
        }

        $digits = preg_replace('/[\s.$]/', '', $value);

        return ctype_digit($digits) && (int) $digits > 0
            ? FactValidation::valid((int) $digits)
            : FactValidation::invalid('Escribe tu ingreso mensual como un número mayor que cero, por ejemplo 2500000.');
    }

    public function capabilities(): array
    {
        return [SavingsTargetCapability::class];
    }
}
