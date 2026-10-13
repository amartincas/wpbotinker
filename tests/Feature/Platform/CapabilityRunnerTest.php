<?php

/*
 * Capability execution and cache (phase C) with the fictitious 'oracle'
 * (lucky number from the birth date) and 'finance' (savings target from
 * income and birth date) domains.
 */

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\ComputedResult;
use App\Models\Customer;
use App\Models\CustomerFact;
use App\Models\Product;
use App\Models\Store;
use App\Platform\Capabilities\CapabilityResult;
use App\Platform\Capabilities\CapabilityRunner;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\CustomerMemory;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Domains\Capabilities\LuckyNumberCapability;
use Tests\Support\Domains\Capabilities\SavingsTargetCapability;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

/**
 * Counts real executions of the fictitious capabilities and lets a test run
 * code in the middle of a calculation (to simulate a concurrent process).
 */
class CapabilitySpy
{
    public static array $runs = [];

    public static ?Closure $during = null;

    public static string $luckyVersion = '1';

    public static function reset(): void
    {
        self::$runs = ['oracle.lucky_number' => 0, 'finance.savings_target' => 0];
        self::$during = null;
        self::$luckyVersion = '1';
    }

    public static function record(string $key): void
    {
        self::$runs[$key]++;

        if (self::$during) {
            (self::$during)();
        }
    }
}

beforeEach(function () {
    CapabilitySpy::reset();

    app()->bind(LuckyNumberCapability::class, fn () => new class extends LuckyNumberCapability
    {
        public function version(): string
        {
            return CapabilitySpy::$luckyVersion;
        }

        public function run(array $facts): CapabilityResult
        {
            CapabilitySpy::record($this->key());

            return parent::run($facts);
        }
    });

    app()->bind(SavingsTargetCapability::class, fn () => new class extends SavingsTargetCapability
    {
        public function run(array $facts): CapabilityResult
        {
            CapabilitySpy::record($this->key());

            return parent::run($facts);
        }
    });

    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);

    $this->memory = app(CustomerMemory::class);
    $this->runner = app(CapabilityRunner::class);
    $this->domains = app(DomainRegistry::class);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->chart = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Carta astral', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number',
    ]);
    $this->budget = Product::create([
        'store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'Presupuesto', 'description' => 'x', 'price' => 1, 'stock' => 1,
        'required_facts' => ['monthly_income', 'birth_date'], 'capability_key' => 'finance.savings_target',
    ]);
    $this->customer = $this->memory->customerFor($this->store, '573001110000');
});

function giveFact(Customer $customer, string $domainKey, string $key, mixed $value): CustomerFact
{
    $fact = collect(app(DomainRegistry::class)->get($domainKey)->facts())->firstWhere('key', $key);

    return app(CustomerMemory::class)->record($customer, $domainKey, $fact, $value, CustomerFact::SOURCE_CUSTOMER_ANSWER);
}

/** Customer with oracle's birth date, and finance's income + consent to reuse the birth date. */
function readyForBoth(Customer $customer, string $birthDate = '1990-03-15'): void
{
    giveFact($customer, 'oracle', 'birth_date', $birthDate);
    giveFact($customer, 'finance', 'monthly_income', 2500000);
    app(CustomerMemory::class)->grant($customer, 'birth_date', 'finance');
}

function runCapability(Customer $customer, Product $product): ?CapabilityResult
{
    return app(CapabilityRunner::class)->run($customer, $product, app(DomainRegistry::class)->get($product->domain_key));
}

describe('execution and cache', function () {
    it('computes once, stores the result and reuses it while the inputs do not change', function () {
        $fact = giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');

        $first = runCapability($this->customer, $this->chart);
        $second = runCapability($this->customer, $this->chart);

        $row = ComputedResult::sole();
        expect($first->data)->toBe(['lucky_number' => 1])
            ->and($second->data)->toBe($first->data)
            ->and($second->summary)->toBe('Número de la suerte calculado: 1.')
            ->and(CapabilitySpy::$runs['oracle.lucky_number'])->toBe(1)
            ->and($row->only(['store_id', 'customer_id', 'domain_key', 'capability_key', 'capability_version']))
            ->toBe(['store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'domain_key' => 'oracle', 'capability_key' => 'oracle.lucky_number', 'capability_version' => '1'])
            ->and($row->input_fact_ids)->toBe(['birth_date' => $fact->id])
            ->and($row->input_hash)->toHaveLength(64);
    });

    it('keeps the payload encrypted at rest', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        runCapability($this->customer, $this->chart);

        expect(DB::table('computed_results')->value('payload'))->not->toContain('suerte')->not->toContain('lucky');
    });

    it('returns nothing for a product without a capability', function () {
        $plain = Product::create(['store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'Consulta', 'description' => 'x', 'price' => 1, 'stock' => 1]);

        expect(runCapability($this->customer, $plain))->toBeNull()->and(ComputedResult::count())->toBe(0);
    });

    it('recomputes when the capability version changes and drops the old result', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        runCapability($this->customer, $this->chart);

        CapabilitySpy::$luckyVersion = '2';
        app()->forgetInstance(DomainRegistry::class);
        runCapability($this->customer, $this->chart);

        expect(CapabilitySpy::$runs['oracle.lucky_number'])->toBe(2)
            ->and(ComputedResult::sole()->capability_version)->toBe('2');
    });
});

describe('invalidation', function () {
    beforeEach(function () {
        readyForBoth($this->customer);
        runCapability($this->customer, $this->chart);
        runCapability($this->customer, $this->budget);
        expect(ComputedResult::count())->toBe(2);
    });

    it('deletes every result that used a corrected person fact, and recomputes with the new value', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1980-03-15');

        expect(ComputedResult::count())->toBe(0);

        expect(runCapability($this->customer, $this->budget)->data)->toBe(['age' => 45, 'rate' => 0.25, 'monthly_target' => 625000])
            ->and(CapabilitySpy::$runs['finance.savings_target'])->toBe(2);
    });

    it('deletes only the owner domain results when one of its own facts is corrected', function () {
        giveFact($this->customer, 'finance', 'monthly_income', 3000000);

        expect(ComputedResult::pluck('domain_key')->all())->toBe(['oracle']);
    });

    it('deletes only the revoked domain results when a permission is revoked', function () {
        $this->memory->revoke($this->customer, 'birth_date', 'finance');

        expect(ComputedResult::pluck('domain_key')->all())->toBe(['oracle'])
            ->and(fn () => runCapability($this->customer, $this->budget))
            ->toThrow(RuntimeException::class, 'facts [birth_date] are missing or not authorized for domain [finance]');
    });

    it('never touches the results of another customer', function () {
        $other = $this->memory->customerFor($this->store, '573009990000');
        readyForBoth($other);
        runCapability($other, $this->chart);

        giveFact($this->customer, 'oracle', 'birth_date', '1980-03-15');

        expect(ComputedResult::where('customer_id', $other->id)->count())->toBe(1);
    });
});

describe('isolation and validation', function () {
    it('never reuses another customer result, even with identical facts', function () {
        $other = $this->memory->customerFor($this->store, '573009990000');
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        giveFact($other, 'oracle', 'birth_date', '1990-03-15');

        runCapability($this->customer, $this->chart);
        runCapability($other, $this->chart);

        expect(CapabilitySpy::$runs['oracle.lucky_number'])->toBe(2)
            ->and(ComputedResult::pluck('customer_id')->sort()->values()->all())->toBe(collect([$this->customer->id, $other->id])->sort()->values()->all());
    });

    it('refuses to run with a missing or unauthorized input', function () {
        giveFact($this->customer, 'finance', 'monthly_income', 2500000);
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15'); // not shared with finance

        expect(fn () => runCapability($this->customer, $this->budget))
            ->toThrow(RuntimeException::class, 'not authorized for domain [finance]')
            ->and(CapabilitySpy::$runs['finance.savings_target'])->toBe(0);
    });

    it('refuses a product of another domain or store', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        $foreign = Product::create(['store_id' => makeStore()->id, 'domain_key' => 'oracle', 'name' => 'Ajeno', 'description' => 'x', 'price' => 1, 'stock' => 1, 'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number']);

        expect(fn () => $this->runner->run($this->customer, $this->chart, $this->domains->get('finance')))
            ->toThrow(InvalidArgumentException::class, 'does not belong')
            ->and(fn () => runCapability($this->customer, $foreign))
            ->toThrow(InvalidArgumentException::class, 'does not belong');
    });

    it('rejects saving a product with a capability of another domain', function () {
        expect(fn () => Product::create([
            'store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'Mal', 'description' => 'x', 'price' => 1, 'stock' => 1,
            'required_facts' => ['birth_date'], 'capability_key' => 'oracle.lucky_number',
        ]))->toThrow(InvalidArgumentException::class, 'belongs to domain [oracle]');
    });

    it('rejects saving a product whose capability needs facts the product does not require', function () {
        expect(fn () => Product::create([
            'store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'Incompleto', 'description' => 'x', 'price' => 1, 'stock' => 1,
            'required_facts' => ['monthly_income'], 'capability_key' => 'finance.savings_target',
        ]))->toThrow(InvalidArgumentException::class, "requires fact [birth_date]; add it to the product's required facts");
    });
});

describe('concurrency', function () {
    it('reuses the row of a concurrent calculation that stored the same result first', function () {
        $fact = giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        $hash = hash('sha256', json_encode(['oracle.lucky_number', '1', ['birth_date' => $fact->id]]));

        CapabilitySpy::$during = function () use ($fact, $hash) {
            CapabilitySpy::$during = null;
            ComputedResult::create([
                'store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'domain_key' => 'oracle',
                'capability_key' => 'oracle.lucky_number', 'capability_version' => '1', 'input_hash' => $hash,
                'input_fact_ids' => ['birth_date' => $fact->id], 'payload' => ['data' => ['lucky_number' => 1], 'summary' => 'x'],
            ]);
        };

        expect(runCapability($this->customer, $this->chart)->data)->toBe(['lucky_number' => 1])
            ->and(ComputedResult::count())->toBe(1);
    });

    it('neither returns nor stores a result whose input was corrected while it was being computed', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');

        CapabilitySpy::$during = function () {
            CapabilitySpy::$during = null;
            giveFact($this->customer, 'oracle', 'birth_date', '1985-01-01');
        };

        $result = runCapability($this->customer, $this->chart);

        // 1+9+8+5+0+1+0+1 = 25 → 7: computed again from the corrected date, not 1 from the old one.
        expect($result->data)->toBe(['lucky_number' => 7])
            ->and(CapabilitySpy::$runs['oracle.lucky_number'])->toBe(2)
            ->and(ComputedResult::sole()->input_fact_ids['birth_date'])->toBe(CustomerFact::where('status', 'current')->sole()->id);
    });

    it('does not deliver a result whose permission was revoked while it was being computed', function () {
        readyForBoth($this->customer);

        CapabilitySpy::$during = function () {
            CapabilitySpy::$during = null;
            app(CustomerMemory::class)->revoke($this->customer, 'birth_date', 'finance');
        };

        expect(fn () => runCapability($this->customer, $this->budget))
            ->toThrow(RuntimeException::class, 'facts [birth_date] are missing or not authorized for domain [finance]')
            ->and(ComputedResult::where('domain_key', 'finance')->count())->toBe(0);
    });

    it('gives up instead of answering when the inputs keep changing', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        $dates = ['1985-01-01', '1986-02-02', '1987-03-03'];

        CapabilitySpy::$during = function () use (&$dates) {
            giveFact($this->customer, 'oracle', 'birth_date', array_shift($dates));
        };

        expect(fn () => runCapability($this->customer, $this->chart))
            ->toThrow(RuntimeException::class, 'inputs kept changing')
            ->and(ComputedResult::count())->toBe(0);
    });

    it('rejects a duplicated result at the database level', function () {
        giveFact($this->customer, 'oracle', 'birth_date', '1990-03-15');
        runCapability($this->customer, $this->chart);
        $row = ComputedResult::sole();

        expect(fn () => ComputedResult::create(array_merge($row->only(['store_id', 'customer_id', 'domain_key', 'capability_key', 'capability_version', 'input_hash', 'input_fact_ids']), ['payload' => ['data' => [], 'summary' => '']])))
            ->toThrow(QueryException::class, 'input_hash');
    });
});

describe('through the conversation', function () {
    function capabilityJob(Store $store, string $body): void
    {
        ProcessWhatsAppMessage::dispatchSync($store, '573004440000', $body, 'wamid.in.'.uniqid());
        test()->travel(1)->seconds();
    }

    it('gives the LLM the computed result as an exact fact and reuses it in later turns', function () {
        fakeExternalApis();

        capabilityJob($this->store, 'Quiero mi Carta astral');
        capabilityJob($this->store, '15/03/1990');
        capabilityJob($this->store, '¿Y qué más me dice?');

        $prompt = collect(mainChatRequests())->last()->data()['messages'][0]['content'];
        expect($prompt)
            ->toContain("### RESULTADO CALCULADO\nEl sistema calculó este resultado de forma exacta a partir de los datos del cliente. Explícalo tal como está: no lo recalcules, no lo corrijas y no inventes otros valores ni cálculos.\nNúmero de la suerte calculado: 1.")
            ->and(CapabilitySpy::$runs['oracle.lucky_number'])->toBe(1)
            ->and(mainChatRequests())->toHaveCount(2); // only the two answers, never a calculation request
    });

    it('keeps computed values out of the logs', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
        fakeExternalApis();

        capabilityJob($this->store, 'Quiero mi Carta astral');
        capabilityJob($this->store, '15/03/1990');

        // The capability key is an identifier and may be logged; its computed values may not.
        expect(collect($logged)->filter(fn ($line) => str_contains($line, 'suerte') || str_contains($line, '"lucky_number":'))->values()->all())->toBe([])
            ->and(collect($logged)->contains(fn ($line) => str_contains($line, 'CAPABILITY_COMPUTED')))->toBeTrue();
    });
});
