<?php

namespace App\Platform\Memory;

use App\Models\ComputedResult;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\CustomerFactShare;
use App\Models\Store;
use App\Platform\Contracts\DomainModule;
use Illuminate\Support\Facades\DB;

/**
 * The only way into customer memory. Facts are reached through the
 * customer of the current conversation (store + phone), never through ids
 * taken from a message.
 *
 * Reading rules:
 * - a domain fact is readable only by its owner domain;
 * - a person fact is readable by a domain only if the domain declares it
 *   AND holds an active share (granted when the customer gave the fact to
 *   that domain, or consented to reuse it there).
 *
 * Writes are append-only versions inside a transaction that locks the
 * customer row, so concurrent writers cannot create two current versions
 * (the unique (customer_id, current_marker) index is the last guard).
 */
class CustomerMemory
{
    public function customerFor(Store $store, string $phone): Customer
    {
        return Customer::createOrFirst(['store_id' => $store->id, 'customer_phone' => $phone]);
    }

    public static function identityKey(FactDefinition $fact, string $domainKey): string
    {
        return $fact->isPerson() ? "person:{$fact->key}" : "domain:{$domainKey}:{$fact->key}";
    }

    /**
     * Stores a validated value as the current version of the fact. Storing
     * the same value again creates no new version. A person fact given to a
     * domain grants that domain an active share ('provided').
     */
    public function record(
        Customer $customer,
        string $domainKey,
        FactDefinition $fact,
        mixed $value,
        string $source,
        ?string $sourceWamid = null,
        ?int $actorUserId = null,
    ): CustomerFact {
        $identity = self::identityKey($fact, $domainKey);

        return DB::transaction(function () use ($customer, $domainKey, $fact, $value, $source, $sourceWamid, $actorUserId, $identity) {
            $this->lock($customer);

            $current = $this->currentFact($customer, $identity);

            if ($current === null || $current->plainValue() !== $value) {
                $version = (int) CustomerFact::where('customer_id', $customer->id)->where('identity_key', $identity)->max('version') + 1;

                // Supersede first: the unique current_marker allows one current row.
                $current?->update(['status' => CustomerFact::STATUS_SUPERSEDED]);

                $new = CustomerFact::create([
                    'customer_id' => $customer->id,
                    'store_id' => $customer->store_id,
                    'scope' => $fact->scope->value,
                    'owner_domain_key' => $fact->isPerson() ? null : $domainKey,
                    'fact_key' => $fact->key,
                    'identity_key' => $identity,
                    'version' => $version,
                    'status' => CustomerFact::STATUS_CURRENT,
                    'value' => ['v' => $value],
                    'source' => $source,
                    'collected_by_domain' => $domainKey,
                    'source_wamid' => $sourceWamid,
                    'actor_user_id' => $actorUserId,
                    'collected_at' => now(),
                ]);

                if ($current) {
                    $current->update(['superseded_by_id' => $new->id]);
                    // Results computed from the old value are wrong now.
                    $this->forgetResults($customer, fn (ComputedResult $result) => $result->usesFactId($current->id));
                }

                $current = $new;
            }

            if ($fact->isPerson()) {
                $this->activeShareOrCreate($customer, $fact->key, $domainKey, CustomerFactShare::BASIS_PROVIDED);
            }

            return $current;
        });
    }

    /**
     * Current values the domain may read: fact key => value.
     *
     * @return array<string, mixed>
     */
    public function visibleFacts(Customer $customer, DomainModule $domain): array
    {
        return array_map(fn (CustomerFact $row) => $row->plainValue(), $this->visibleFactRows($customer, $domain));
    }

    /**
     * The current value of a person fact of the catalog (e.g. "name"), or
     * null. For the store's own records, never for a domain prompt: domains
     * read facts through visibleFacts(), which applies the sharing rules.
     */
    public function personValue(Customer $customer, string $factKey): mixed
    {
        return $this->currentFact($customer, "person:{$factKey}")?->plainValue();
    }

    /**
     * The current fact versions the domain may read, by fact key — same
     * rules as visibleFacts(), but keeping each version's identity.
     *
     * @param  list<string>|null  $only  limit to these fact keys
     * @return array<string, CustomerFact>
     */
    public function visibleFactRows(Customer $customer, DomainModule $domain, ?array $only = null): array
    {
        $definitions = collect($domain->facts())->keyBy('key');

        if ($only !== null) {
            $definitions = $definitions->only($only);
        }

        if ($definitions->isEmpty()) {
            return [];
        }

        $current = CustomerFact::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->where('status', CustomerFact::STATUS_CURRENT)
            ->whereIn('identity_key', $definitions->map(fn (FactDefinition $fact) => self::identityKey($fact, $domain->key()))->values())
            ->get()
            ->keyBy('identity_key');

        $shared = $this->activeSharesFor($customer, $domain->key());

        $visible = [];

        foreach ($definitions as $key => $fact) {
            if ($fact->isPerson() && ! in_array($key, $shared, true)) {
                continue;
            }

            if ($row = $current->get(self::identityKey($fact, $domain->key()))) {
                $visible[$key] = $row;
            }
        }

        return $visible;
    }

    /**
     * Runs $callback in a transaction holding this customer's lock — the
     * same lock every memory write takes, so the callback sees facts and
     * shares that cannot change until it commits.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(Customer $customer, callable $callback): mixed
    {
        return DB::transaction(function () use ($customer, $callback) {
            $this->lock($customer);

            return $callback();
        });
    }

    /** Whether the customer has a current value for the fact, visible or not to this domain. */
    public function hasCurrent(Customer $customer, FactDefinition $fact, string $domainKey): bool
    {
        return $this->currentFact($customer, self::identityKey($fact, $domainKey)) !== null;
    }

    /** The customer allows $domainKey to read a person fact given elsewhere. */
    public function grant(Customer $customer, string $factKey, string $domainKey, string $basis = CustomerFactShare::BASIS_CONSENTED): CustomerFactShare
    {
        return DB::transaction(function () use ($customer, $factKey, $domainKey, $basis) {
            $this->lock($customer);

            return $this->activeShareOrCreate($customer, $factKey, $domainKey, $basis);
        });
    }

    /**
     * Revokes the active share: from now on $domainKey no longer reads the
     * fact, and the results $domainKey computed from it are deleted. The
     * fact itself, other domains' shares and their results are untouched.
     */
    public function revoke(Customer $customer, string $factKey, string $domainKey): bool
    {
        return DB::transaction(function () use ($customer, $factKey, $domainKey) {
            $this->lock($customer);

            $share = $this->activeShare($customer, $factKey, $domainKey);

            if ($share === null) {
                return false;
            }

            $share->update(['revoked_at' => now()]);
            $this->forgetResults($customer, fn (ComputedResult $result) => $result->domain_key === $domainKey && $result->usesFactKey($factKey));

            return true;
        });
    }

    /**
     * Records that the customer refused to share the fact with $domainKey.
     * The row is born revoked: it never grants access. A later grant() still
     * creates an active share as usual.
     */
    public function decline(Customer $customer, string $factKey, string $domainKey): void
    {
        DB::transaction(function () use ($customer, $factKey, $domainKey) {
            $this->lock($customer);

            CustomerFactShare::create([
                'customer_id' => $customer->id,
                'store_id' => $customer->store_id,
                'fact_key' => $factKey,
                'grantee_domain_key' => $domainKey,
                'basis' => CustomerFactShare::BASIS_DECLINED,
                'granted_at' => now(),
                'revoked_at' => now(),
            ]);
        });
    }

    /** Whether the customer's latest decision for this fact and domain was a refusal. */
    public function hasDeclined(Customer $customer, string $factKey, string $domainKey): bool
    {
        return CustomerFactShare::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->where('fact_key', $factKey)
            ->where('grantee_domain_key', $domainKey)
            ->latest('id')
            ->value('basis') === CustomerFactShare::BASIS_DECLINED;
    }

    /**
     * Deletes this customer's computed results matching $affected. A
     * customer has few results, so they are filtered here rather than with
     * database-specific JSON queries. Must run inside the customer lock.
     *
     * @param  callable(ComputedResult): bool  $affected
     */
    private function forgetResults(Customer $customer, callable $affected): void
    {
        ComputedResult::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->get()
            ->filter($affected)
            ->each->delete();
    }

    private function lock(Customer $customer): void
    {
        Customer::whereKey($customer->id)->lockForUpdate()->first();
    }

    private function currentFact(Customer $customer, string $identity): ?CustomerFact
    {
        return CustomerFact::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->where('identity_key', $identity)
            ->where('status', CustomerFact::STATUS_CURRENT)
            ->first();
    }

    private function activeShare(Customer $customer, string $factKey, string $domainKey): ?CustomerFactShare
    {
        return CustomerFactShare::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->where('fact_key', $factKey)
            ->where('grantee_domain_key', $domainKey)
            ->whereNull('revoked_at')
            ->first();
    }

    /** Must run inside a transaction holding the customer lock. */
    private function activeShareOrCreate(Customer $customer, string $factKey, string $domainKey, string $basis): CustomerFactShare
    {
        return $this->activeShare($customer, $factKey, $domainKey) ?? CustomerFactShare::create([
            'customer_id' => $customer->id,
            'store_id' => $customer->store_id,
            'fact_key' => $factKey,
            'grantee_domain_key' => $domainKey,
            'basis' => $basis,
            'granted_at' => now(),
        ]);
    }

    /**
     * @return list<string> person fact keys the domain holds an active share for
     */
    private function activeSharesFor(Customer $customer, string $domainKey): array
    {
        return CustomerFactShare::where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id)
            ->where('grantee_domain_key', $domainKey)
            ->whereNull('revoked_at')
            ->pluck('fact_key')
            ->all();
    }
}
