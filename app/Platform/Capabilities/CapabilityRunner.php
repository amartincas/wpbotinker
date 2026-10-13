<?php

namespace App\Platform\Capabilities;

use App\Models\ComputedResult;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Product;
use App\Platform\Contracts\Capability;
use App\Platform\Contracts\DomainModule;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs the deterministic capability of a product for a customer, or reuses
 * the cached result.
 *
 * - The capability must belong to the product's domain (DomainRegistry).
 * - Its inputs are the CURRENT fact versions the domain may read
 *   (CustomerMemory); a missing or unauthorized input is an error, never a
 *   guess.
 * - The cache key covers the capability key, its version() and the exact
 *   fact version ids, so a corrected fact or a changed calculation is never
 *   served from an old result.
 * - A new result is used only if, checked again inside the customer lock,
 *   its inputs are still the current and authorized versions; then it is
 *   stored in that same lock. Otherwise it is discarded (neither returned
 *   nor stored) and computed again, so a concurrent correction or
 *   revocation never reaches the customer through a stale result.
 */
class CapabilityRunner
{
    /** Computations tried when the inputs change underneath; then it gives up. */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly DomainRegistry $domains,
        private readonly CustomerMemory $memory,
    ) {}

    /** Null when the product has no capability. */
    public function run(Customer $customer, Product $product, DomainModule $domain): ?CapabilityResult
    {
        if (blank($product->capability_key)) {
            return null;
        }

        if ($product->domain_key !== $domain->key() || $product->store_id !== $customer->store_id) {
            throw new InvalidArgumentException("Product [{$product->id}] does not belong to domain [{$domain->key()}] of the customer's store.");
        }

        $this->domains->assertProductConfiguration($domain->key(), $product->required_facts ?? [], $product->capability_key);
        $capability = $this->domains->capabilityFor($domain->key(), $product->capability_key);

        // If the inputs change while computing (correction, revocation), the
        // result is discarded and computed again from the current inputs: a
        // result based on stale or no-longer-authorized facts is never
        // returned, nor stored.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attempt($customer, $domain, $capability);

            if ($result !== null) {
                return $result;
            }

            Log::warning('CAPABILITY_INPUTS_CHANGED', ['capability_key' => $capability->key(), 'customer_id' => $customer->id, 'attempt' => $attempt]);
        }

        throw new RuntimeException("Capability [{$capability->key()}] gave up: its inputs kept changing while it was being computed.");
    }

    /**
     * One computation. Null when the inputs changed before the result could
     * be stored, so the caller must not use it.
     */
    private function attempt(Customer $customer, DomainModule $domain, Capability $capability): ?CapabilityResult
    {
        $inputs = $this->memory->visibleFactRows($customer, $domain, $capability->requires());
        $missing = array_diff($capability->requires(), array_keys($inputs));

        if ($missing !== []) {
            throw new RuntimeException("Capability [{$capability->key()}] cannot run: facts [".implode(', ', $missing)."] are missing or not authorized for domain [{$domain->key()}].");
        }

        $inputIds = $this->inputIds($inputs);
        $hash = hash('sha256', json_encode([$capability->key(), $capability->version(), $inputIds]));

        $cached = ComputedResult::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->where('capability_key', $capability->key())
            ->where('input_hash', $hash)
            ->first();

        if ($cached) {
            Log::info('CAPABILITY_REUSED', ['capability_key' => $capability->key(), 'customer_id' => $customer->id, 'result_id' => $cached->id]);

            return new CapabilityResult($cached->payload['data'], $cached->payload['summary'], $cached->payload['knowledge_keys'] ?? []);
        }

        $result = $capability->run(array_map(fn (CustomerFact $row) => $row->plainValue(), $inputs));
        json_encode($result->data, JSON_THROW_ON_ERROR);

        $stored = $this->memory->transaction($customer, function () use ($customer, $domain, $capability, $inputIds, $hash, $result) {
            // Final check under the customer lock: the inputs must still be
            // the current, authorized versions the result was computed from.
            if ($this->inputIds($this->memory->visibleFactRows($customer, $domain, $capability->requires())) !== $inputIds) {
                return null;
            }

            $row = ComputedResult::createOrFirst(
                ['customer_id' => $customer->id, 'capability_key' => $capability->key(), 'input_hash' => $hash],
                [
                    'store_id' => $customer->store_id,
                    'domain_key' => $domain->key(),
                    'capability_version' => $capability->version(),
                    'input_fact_ids' => $inputIds,
                    'payload' => ['data' => $result->data, 'summary' => $result->summary, 'knowledge_keys' => $result->knowledgeKeys],
                    'computed_at' => now(),
                ],
            );

            // Older results of this capability for this customer can no
            // longer be reused (other inputs or version): drop them.
            ComputedResult::where('customer_id', $customer->id)
                ->where('capability_key', $capability->key())
                ->whereKeyNot($row->id)
                ->delete();

            return $row;
        });

        if ($stored === null) {
            return null;
        }

        Log::info('CAPABILITY_COMPUTED', [
            'capability_key' => $capability->key(),
            'customer_id' => $customer->id,
            'result_id' => $stored->id,
        ]);

        return $result;
    }

    /**
     * @param  array<string, CustomerFact>  $rows
     * @return array<string, int> fact key => fact version id, sorted by key
     */
    private function inputIds(array $rows): array
    {
        $ids = array_map(fn (CustomerFact $row) => $row->id, $rows);
        ksort($ids);

        return $ids;
    }
}
