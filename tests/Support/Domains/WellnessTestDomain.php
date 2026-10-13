<?php

namespace Tests\Support\Domains;

use App\Platform\Contracts\DomainModule;

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
}
