<?php

namespace Tests\Support\Domains\Capabilities;

use App\Platform\Capabilities\CapabilityResult;
use App\Platform\Contracts\Capability;

/**
 * Fictitious deterministic capability of the test 'oracle' domain: reduces
 * the digits of the birth date to a single digit.
 */
class LuckyNumberCapability implements Capability
{
    public function key(): string
    {
        return 'oracle.lucky_number';
    }

    public function requires(): array
    {
        return ['birth_date'];
    }

    public function version(): string
    {
        return '1';
    }

    public function run(array $facts): CapabilityResult
    {
        $number = array_sum(str_split(preg_replace('/\D/', '', $facts['birth_date'])));

        while ($number > 9) {
            $number = array_sum(str_split((string) $number));
        }

        return new CapabilityResult(['lucky_number' => $number], "Número de la suerte calculado: {$number}.");
    }
}
