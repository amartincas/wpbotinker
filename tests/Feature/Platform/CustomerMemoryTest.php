<?php

/*
 * Customer memory (phase B): identity, versions, provenance, permissions,
 * revocation, store isolation, encryption and database constraints.
 */

use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\CustomerFactShare;
use App\Platform\Contracts\DomainModule;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\CustomerMemory;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactValidation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    // 'copycat' declares an OWN fact with the same key as oracle's own fact.
    app()->bind('test.domain.copycat', fn () => new class implements DomainModule
    {
        public function key(): string
        {
            return 'copycat';
        }

        public function label(): string
        {
            return 'Copia';
        }

        public function vocabulary(): array
        {
            return [];
        }

        public function promptRules(): string
        {
            return '';
        }

        public function facts(): array
        {
            return [FactDefinition::domain('favorite_color', '¿Color?'), FactDefinition::person('birth_date')];
        }

        public function validateFact(string $key, string $value): FactValidation
        {
            return FactValidation::valid($value);
        }

        public function capabilities(): array
        {
            return [];
        }
    });

    config(['botinker.domains' => [
        'oracle' => OracleTestDomain::class,
        'finance' => FinanceTestDomain::class,
        'copycat' => 'test.domain.copycat',
    ]]);

    $this->memory = app(CustomerMemory::class);
    $this->domains = app(DomainRegistry::class);
    $this->store = makeStore();
    $this->customer = $this->memory->customerFor($this->store, '573001112233');
});

function fact(string $domainKey, string $key): FactDefinition
{
    return collect(app(DomainRegistry::class)->get($domainKey)->facts())->firstWhere('key', $key);
}

describe('customers', function () {
    it('is one customer per store and phone', function () {
        $again = $this->memory->customerFor($this->store, '573001112233');
        $otherStore = $this->memory->customerFor(makeStore(), '573001112233');

        expect($again->id)->toBe($this->customer->id)
            ->and($otherStore->id)->not->toBe($this->customer->id)
            ->and(Customer::count())->toBe(2);
    });

    it('is enforced by a unique index', function () {
        expect(fn () => DB::table('customers')->insert(['store_id' => $this->store->id, 'customer_phone' => '573001112233']))
            ->toThrow(QueryException::class);
    });
});

describe('fact versions and provenance', function () {
    it('appends a new version on change and keeps the previous one superseded', function () {
        $v1 = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER, 'wamid.a');
        $v2 = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1991-04-16', CustomerFact::SOURCE_CUSTOMER_ANSWER, 'wamid.b');

        $v1->refresh();
        expect($v2->version)->toBe(2)
            ->and($v2->status)->toBe(CustomerFact::STATUS_CURRENT)
            ->and($v1->status)->toBe(CustomerFact::STATUS_SUPERSEDED)
            ->and($v1->superseded_by_id)->toBe($v2->id)
            ->and($v1->plainValue())->toBe('1990-03-15')
            ->and($this->memory->visibleFacts($this->customer, $this->domains->get('oracle')))->toBe(['birth_date' => '1991-04-16']);
    });

    it('creates no new version when the same value is given again', function () {
        $first = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);
        $again = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        expect($again->id)->toBe($first->id)->and(CustomerFact::count())->toBe(1);
    });

    it('records identity and provenance of every version', function () {
        $person = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER, 'wamid.p');
        $own = $this->memory->record($this->customer, 'finance', fact('finance', 'monthly_income'), 2500000, CustomerFact::SOURCE_CUSTOMER_ANSWER, 'wamid.o');

        expect($person->only(['scope', 'owner_domain_key', 'identity_key', 'collected_by_domain', 'source', 'source_wamid', 'store_id']))
            ->toBe(['scope' => 'person', 'owner_domain_key' => null, 'identity_key' => 'person:birth_date', 'collected_by_domain' => 'oracle', 'source' => 'customer_answer', 'source_wamid' => 'wamid.p', 'store_id' => $this->store->id])
            ->and($own->only(['scope', 'owner_domain_key', 'identity_key']))
            ->toBe(['scope' => 'domain', 'owner_domain_key' => 'finance', 'identity_key' => 'domain:finance:monthly_income'])
            ->and($own->plainValue())->toBe(2500000);
    });

    it('keeps values encrypted at rest', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        $raw = DB::table('customer_facts')->value('value');
        expect($raw)->not->toContain('1990')->and(strlen($raw))->toBeGreaterThan(50);
    });

    it('rejects a second current version of the same fact at the database level', function () {
        $current = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        expect(fn () => CustomerFact::create(array_merge(
            $current->only(['customer_id', 'store_id', 'scope', 'fact_key', 'identity_key', 'source', 'collected_at']),
            ['version' => 2, 'status' => CustomerFact::STATUS_CURRENT, 'value' => ['v' => 'x']],
        )))->toThrow(QueryException::class, 'current_marker');
    });

    it('rejects a duplicated version number at the database level', function () {
        $current = $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        expect(fn () => CustomerFact::create(array_merge(
            $current->only(['customer_id', 'store_id', 'scope', 'fact_key', 'identity_key', 'source', 'collected_at']),
            ['version' => 1, 'status' => CustomerFact::STATUS_SUPERSEDED, 'value' => ['v' => 'x']],
        )))->toThrow(QueryException::class, 'version');
    });
});

describe('reading permissions', function () {
    it('lets only the owner domain read its own facts, even with the same key elsewhere', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'favorite_color'), 'azul', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        expect($this->memory->visibleFacts($this->customer, $this->domains->get('oracle')))->toBe(['favorite_color' => 'azul'])
            ->and($this->memory->visibleFacts($this->customer, $this->domains->get('copycat')))->toBe([])
            ->and($this->memory->visibleFacts($this->customer, $this->domains->get('finance')))->toBe([]);
    });

    it('shares a person fact with another domain only with an active permission', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);
        $finance = $this->domains->get('finance');

        expect($this->memory->visibleFacts($this->customer, $finance))->toBe([])
            ->and($this->memory->hasCurrent($this->customer, fact('finance', 'birth_date'), 'finance'))->toBeTrue();

        $share = $this->memory->grant($this->customer, 'birth_date', 'finance');
        expect($share->basis)->toBe(CustomerFactShare::BASIS_CONSENTED)
            ->and($this->memory->visibleFacts($this->customer, $finance))->toBe(['birth_date' => '1990-03-15']);
    });

    it('stops sharing on revocation without affecting the fact or other domains', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);
        $this->memory->grant($this->customer, 'birth_date', 'finance');

        expect($this->memory->revoke($this->customer, 'birth_date', 'finance'))->toBeTrue()
            ->and($this->memory->visibleFacts($this->customer, $this->domains->get('finance')))->toBe([])
            ->and($this->memory->visibleFacts($this->customer, $this->domains->get('oracle')))->toBe(['birth_date' => '1990-03-15'])
            ->and(CustomerFactShare::where('grantee_domain_key', 'finance')->sole()->revoked_at)->not->toBeNull();
    });

    it('gives a permission to the corrected value too, since it covers the fact, not a version', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);
        $this->memory->grant($this->customer, 'birth_date', 'finance');
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-16', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        expect($this->memory->visibleFacts($this->customer, $this->domains->get('finance')))->toBe(['birth_date' => '1990-03-16']);
    });

    it('allows a single active permission per fact and domain, and a new one after revoking', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);

        $first = $this->memory->grant($this->customer, 'birth_date', 'finance');
        expect($this->memory->grant($this->customer, 'birth_date', 'finance')->id)->toBe($first->id);

        expect(fn () => CustomerFactShare::create([
            'customer_id' => $this->customer->id, 'store_id' => $this->store->id, 'fact_key' => 'birth_date',
            'grantee_domain_key' => 'finance', 'basis' => 'consented', 'granted_at' => now(),
        ]))->toThrow(QueryException::class);

        $this->memory->revoke($this->customer, 'birth_date', 'finance');
        expect($this->memory->grant($this->customer, 'birth_date', 'finance')->id)->not->toBe($first->id)
            ->and(CustomerFactShare::where('grantee_domain_key', 'finance')->count())->toBe(2);
    });

    it('never shows facts of the same phone in another store', function () {
        $this->memory->record($this->customer, 'oracle', fact('oracle', 'birth_date'), '1990-03-15', CustomerFact::SOURCE_CUSTOMER_ANSWER);
        $otherCustomer = $this->memory->customerFor(makeStore(), '573001112233');

        expect($this->memory->visibleFacts($otherCustomer, $this->domains->get('oracle')))->toBe([])
            ->and($this->memory->hasCurrent($otherCustomer, fact('oracle', 'birth_date'), 'oracle'))->toBeFalse();
    });
});
