<?php

namespace App\Platform\Memory;

/**
 * Outcome of validating a value given by the customer for a fact.
 *
 * The reason is shown to the customer and may end up in logs, so it must
 * describe the expected format and never repeat what the customer wrote.
 */
final class FactValidation
{
    private function __construct(
        public readonly bool $valid,
        public readonly mixed $value,
        public readonly ?string $reason,
    ) {}

    public static function valid(mixed $normalized): self
    {
        return new self(true, $normalized, null);
    }

    public static function invalid(string $reasonForCustomer): self
    {
        return new self(false, null, $reasonForCustomer);
    }
}
