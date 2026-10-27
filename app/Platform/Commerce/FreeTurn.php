<?php

namespace App\Platform\Commerce;

use InvalidArgumentException;

/**
 * Which free reply (1..limit) a customer without access is getting for a
 * product. The limit is the Core's global one (SampleLedger::limit()); the
 * offer follows only the last reply.
 */
final class FreeTurn
{
    public function __construct(public readonly int $number, public readonly int $limit)
    {
        if ($limit < 1 || $number < 1 || $number > $limit) {
            throw new InvalidArgumentException("Invalid free turn {$number} of {$limit}.");
        }
    }

    public function isLast(): bool
    {
        return $this->number === $this->limit;
    }

    /** The strategy part for this position: first, middle or last (a single free reply is the last). */
    public function position(): string
    {
        return match (true) {
            $this->isLast() => 'last',
            $this->number === 1 => 'first',
            default => 'middle',
        };
    }
}
