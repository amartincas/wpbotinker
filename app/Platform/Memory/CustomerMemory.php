<?php

namespace App\Platform\Memory;

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

                $current?->update(['superseded_by_id' => $new->id]);
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
        $definitions = collect($domain->facts())->keyBy('key');

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
                $visible[$key] = $row->plainValue();
            }
        }

        return $visible;
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
     * fact. The fact itself and the shares of other domains are untouched.
     * (Phase C: also invalidates results computed from it.)
     */
    public function revoke(Customer $customer, string $factKey, string $domainKey): bool
    {
        return DB::transaction(function () use ($customer, $factKey, $domainKey) {
            $this->lock($customer);

            $share = $this->activeShare($customer, $factKey, $domainKey);
            $share?->update(['revoked_at' => now()]);

            return $share !== null;
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
