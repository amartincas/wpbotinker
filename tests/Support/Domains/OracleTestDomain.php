<?php

namespace Tests\Support\Domains;

use App\Platform\Contracts\DomainModule;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactValidation;
use Tests\Support\Domains\Capabilities\LuckyNumberCapability;

/**
 * Fictitious domain used only by tests: it is registered through
 * config('botinker.domains') and nothing in App\Platform knows about it.
 * Declares a person fact, a fact of its own and one capability.
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

    public function facts(): array
    {
        return [
            FactDefinition::person('birth_date'),
            FactDefinition::domain('favorite_color', '¿Cuál es tu color favorito?'),
        ];
    }

    public function validateFact(string $key, string $value): FactValidation
    {
        if ($key !== 'favorite_color') {
            return FactValidation::invalid('Ese dato no corresponde a este servicio.');
        }

        $color = mb_strtolower(trim($value));

        return in_array($color, ['rojo', 'azul', 'verde', 'amarillo', 'negro', 'blanco'], true)
            ? FactValidation::valid($color)
            : FactValidation::invalid('Elige un color: rojo, azul, verde, amarillo, negro o blanco.');
    }

    public function capabilities(): array
    {
        return [LuckyNumberCapability::class];
    }
}
