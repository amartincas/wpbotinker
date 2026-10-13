<?php

namespace Tests\Support\Domains\Capabilities;

use App\Platform\Capabilities\CapabilityResult;
use App\Platform\Contracts\Capability;
use Carbon\CarbonImmutable;

/**
 * Fictitious deterministic capability of the test 'finance' domain: a
 * savings target from the monthly income, higher after 40 years of age.
 * The reference date is fixed so the result is deterministic.
 */
class SavingsTargetCapability implements Capability
{
    private const REFERENCE_DATE = '2026-01-01';

    public function key(): string
    {
        return 'finance.savings_target';
    }

    public function requires(): array
    {
        return ['monthly_income', 'birth_date'];
    }

    public function version(): string
    {
        return '1';
    }

    public function run(array $facts): CapabilityResult
    {
        $age = CarbonImmutable::parse($facts['birth_date'])->diffInYears(CarbonImmutable::parse(self::REFERENCE_DATE));
        $rate = $age >= 40 ? 0.25 : 0.15;
        $target = (int) round($facts['monthly_income'] * $rate);

        return new CapabilityResult(
            ['age' => (int) $age, 'rate' => $rate, 'monthly_target' => $target],
            "Meta de ahorro mensual: {$target} (".($rate * 100).'% del ingreso).',
        );
    }
}
