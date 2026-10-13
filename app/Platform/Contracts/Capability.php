<?php

namespace App\Platform\Contracts;

use App\Platform\Capabilities\CapabilityResult;

/**
 * A deterministic calculation a domain offers (e.g. a chart, a budget).
 * The Core runs it with the customer's facts and never knows what it does.
 *
 * Same facts + same version() must always give the same result: that is
 * what lets the Core cache results and invalidate them when either changes.
 */
interface Capability
{
    /** Namespaced by its domain: "{domain_key}.{name}", e.g. "finance.budget". */
    public function key(): string;

    /**
     * Fact keys this capability needs; all must be declared by its domain.
     *
     * @return list<string>
     */
    public function requires(): array;

    /** Change it whenever the calculation changes, so cached results are recomputed. */
    public function version(): string;

    /**
     * @param  array<string, mixed>  $facts  fact key => normalized value (exactly requires())
     */
    public function run(array $facts): CapabilityResult;
}
