<?php

namespace Tests\Support\Domains;

use App\Platform\Contracts\DomainModule;
use App\Platform\Memory\FactValidation;

/**
 * Second fictitious domain, to prove two domains of one store stay apart.
 */
class WellnessTestDomain implements DomainModule
{
    public function key(): string
    {
        return 'wellness';
    }

    public function label(): string
    {
        return 'Bienestar';
    }

    public function vocabulary(): array
    {
        return ['nutricion', 'dieta', 'calorias'];
    }

    public function promptRules(): string
    {
        return 'REGLA-BIENESTAR: nunca des consejos médicos.';
    }

    // A domain without facts or capabilities: both are optional in practice.
    public function facts(): array
    {
        return [];
    }

    public function validateFact(string $key, string $value): FactValidation
    {
        return FactValidation::invalid('Este servicio no pide datos.');
    }

    public function capabilities(): array
    {
        return [];
    }
}
