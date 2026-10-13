<?php

/*
 * Contract tests for domain modules (phase A): what a domain declares,
 * how the registry validates it, and the product-level rules. Invalid
 * modules are bound in the container only for these tests.
 */

use App\Platform\Capabilities\CapabilityResult;
use App\Platform\Contracts\Capability;
use App\Platform\Contracts\DomainModule;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactScope;
use App\Platform\Memory\FactValidation;
use Tests\Support\Domains\Capabilities\LuckyNumberCapability;
use Tests\Support\Domains\Capabilities\SavingsTargetCapability;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;
use Tests\Support\Domains\WellnessTestDomain;

const VALID_TEST_DOMAINS = [
    'oracle' => OracleTestDomain::class,
    'wellness' => WellnessTestDomain::class,
    'finance' => FinanceTestDomain::class,
];

/**
 * A module whose facts and capabilities are given by the test, bound in
 * the container under "test.domain.{key}".
 */
function bindTestDomain(string $key, array $facts, array $capabilities = []): string
{
    app()->bind("test.domain.{$key}", fn () => new class($key, $facts, $capabilities) implements DomainModule
    {
        public function __construct(private string $key, private array $facts, private array $capabilities) {}

        public function key(): string
        {
            return $this->key;
        }

        public function label(): string
        {
            return 'Prueba';
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
            return $this->facts;
        }

        public function validateFact(string $key, string $value): FactValidation
        {
            return FactValidation::valid($value);
        }

        public function capabilities(): array
        {
            return $this->capabilities;
        }
    });

    return "test.domain.{$key}";
}

/** A capability with the given key and required facts, bound as "test.capability.{key}". */
function bindTestCapability(string $key, array $requires): string
{
    app()->bind("test.capability.{$key}", fn () => new class($key, $requires) implements Capability
    {
        public function __construct(private string $key, private array $requires) {}

        public function key(): string
        {
            return $this->key;
        }

        public function requires(): array
        {
            return $this->requires;
        }

        public function version(): string
        {
            return '1';
        }

        public function run(array $facts): CapabilityResult
        {
            return new CapabilityResult([], '');
        }
    });

    return "test.capability.{$key}";
}

function registryWith(array $domains): DomainRegistry
{
    config(['botinker.domains' => $domains]);
    app()->forgetInstance(DomainRegistry::class);

    return app(DomainRegistry::class);
}

describe('what the fictitious domains declare', function () {
    it('validates all three fictitious domains against the contract', function () {
        registryWith(VALID_TEST_DOMAINS)->validateAll();
    })->throwsNoExceptions();

    it('lets a domain reference person facts and declare its own', function () {
        $facts = collect(registryWith(VALID_TEST_DOMAINS)->get('oracle')->facts())->keyBy('key');

        expect($facts['birth_date']->scope)->toBe(FactScope::Person)
            ->and($facts['birth_date']->question)->toBeNull() // comes from the Core catalog
            ->and($facts['favorite_color']->scope)->toBe(FactScope::Domain)
            ->and($facts['favorite_color']->question)->toBe('¿Cuál es tu color favorito?');
    });

    it('lets a domain validate and normalize its own facts', function () {
        $finance = registryWith(VALID_TEST_DOMAINS)->get('finance');

        expect($finance->validateFact('monthly_income', '2.500.000')->value)->toBe(2500000)
            ->and($finance->validateFact('monthly_income', 'mucho')->valid)->toBeFalse()
            ->and($finance->validateFact('monthly_income', 'mucho')->reason)->not->toContain('mucho');
    });

    it('allows a domain without facts or capabilities', function () {
        $wellness = registryWith(VALID_TEST_DOMAINS)->get('wellness');

        expect($wellness->facts())->toBe([])->and($wellness->capabilities())->toBe([]);
    });

    it('runs capabilities deterministically from the facts they require', function () {
        $lucky = new LuckyNumberCapability;
        $savings = new SavingsTargetCapability;

        // 1+9+9+0+0+3+1+5 = 28 → 2+8 = 10 → 1
        expect($lucky->run(['birth_date' => '1990-03-15'])->data)->toBe(['lucky_number' => 1])
            ->and($lucky->run(['birth_date' => '1990-03-15'])->data)->toBe($lucky->run(['birth_date' => '1990-03-15'])->data)
            ->and($savings->run(['monthly_income' => 2000000, 'birth_date' => '1990-03-15'])->data)
            ->toBe(['age' => 35, 'rate' => 0.15, 'monthly_target' => 300000])
            ->and($savings->run(['monthly_income' => 2000000, 'birth_date' => '1980-03-15'])->data['rate'])->toBe(0.25);
    });
});

describe('registry rejects an invalid domain', function () {
    it('rejects a fact declared twice', function () {
        $registry = registryWith(['dup' => bindTestDomain('dup', [
            FactDefinition::domain('color', '¿Color?'),
            FactDefinition::domain('color', '¿Color otra vez?'),
        ])]);

        expect(fn () => $registry->get('dup'))->toThrow(InvalidArgumentException::class, 'declares fact [color] twice');
    });

    it('rejects a reference to a person fact that is not in the catalog', function () {
        $registry = registryWith(['ghost' => bindTestDomain('ghost', [FactDefinition::person('shoe_size')])]);

        expect(fn () => $registry->get('ghost'))->toThrow(InvalidArgumentException::class, 'unknown person fact [shoe_size]');
    });

    it('rejects an own fact that redefines a person fact', function () {
        $registry = registryWith(['clash' => bindTestDomain('clash', [FactDefinition::domain('birth_date', '¿Fecha?')])]);

        expect(fn () => $registry->get('clash'))->toThrow(InvalidArgumentException::class, 'which is a person fact');
    });

    it('rejects a fact key with an invalid format', function () {
        $registry = registryWith(['badkey' => bindTestDomain('badkey', [FactDefinition::domain('Mi:Dato', '¿?')])]);

        expect(fn () => $registry->get('badkey'))->toThrow(InvalidArgumentException::class, 'invalid key');
    });

    it('rejects an own fact without a question', function () {
        $registry = registryWith(['mute' => bindTestDomain('mute', [FactDefinition::domain('color', '  ')])]);

        expect(fn () => $registry->get('mute'))->toThrow(InvalidArgumentException::class, 'needs a question');
    });

    it('rejects a capability that requires a fact the domain does not declare', function () {
        $registry = registryWith(['calc' => bindTestDomain('calc', [FactDefinition::domain('income', '¿Ingreso?')], [
            bindTestCapability('calc.total', ['income', 'expenses']),
        ])]);

        expect(fn () => $registry->get('calc'))->toThrow(InvalidArgumentException::class, 'requires fact [expenses], which domain [calc] does not declare');
    });

    it('rejects a capability declared twice', function () {
        $registry = registryWith(['twice' => bindTestDomain('twice', [], [
            bindTestCapability('twice.sum', []),
            bindTestCapability('twice.sum', []),
        ])]);

        expect(fn () => $registry->get('twice'))->toThrow(InvalidArgumentException::class, 'declares capability [twice.sum] twice');
    });

    it('rejects a capability not named after its domain', function () {
        $registry = registryWith(['owner' => bindTestDomain('owner', [], [bindTestCapability('other.sum', [])])]);

        expect(fn () => $registry->get('owner'))->toThrow(InvalidArgumentException::class, 'must be named [owner.<name>]');
    });

    it('rejects a capability class that does not implement the contract', function () {
        $registry = registryWith(['nocap' => bindTestDomain('nocap', [], [stdClass::class])]);

        expect(fn () => $registry->get('nocap'))->toThrow(InvalidArgumentException::class, 'must implement');
    });

    it('rejects facts that are not FactDefinition objects', function () {
        $registry = registryWith(['raw' => bindTestDomain('raw', ['birth_date'])]);

        expect(fn () => $registry->get('raw'))->toThrow(InvalidArgumentException::class, 'must declare facts as');
    });
});

describe('product configuration against its domain', function () {
    it('accepts declared facts and the domain own capability', function () {
        registryWith(VALID_TEST_DOMAINS)->assertProductConfiguration('finance', ['monthly_income', 'birth_date'], 'finance.savings_target');
        registryWith(VALID_TEST_DOMAINS)->assertProductConfiguration('wellness', []);
    })->throwsNoExceptions();

    it('rejects a capability of another domain', function () {
        $registry = registryWith(VALID_TEST_DOMAINS);

        expect(fn () => $registry->assertProductConfiguration('finance', [], 'oracle.lucky_number'))
            ->toThrow(InvalidArgumentException::class, 'Capability [oracle.lucky_number] belongs to domain [oracle]; a product of domain [finance] cannot use it.')
            ->and(fn () => $registry->capabilityFor('wellness', 'oracle.lucky_number'))
            ->toThrow(InvalidArgumentException::class, 'belongs to domain [oracle]');
    });

    it('rejects a capability that does not exist', function () {
        expect(fn () => registryWith(VALID_TEST_DOMAINS)->assertProductConfiguration('finance', [], 'finance.unknown'))
            ->toThrow(InvalidArgumentException::class, 'Domain [finance] has no capability [finance.unknown].');
    });

    it('rejects a required fact the domain does not declare', function () {
        expect(fn () => registryWith(VALID_TEST_DOMAINS)->assertProductConfiguration('finance', ['favorite_color']))
            ->toThrow(InvalidArgumentException::class, 'requires fact [favorite_color], which the domain does not declare');
    });

    it('rejects a product of an unregistered domain', function () {
        expect(fn () => registryWith(VALID_TEST_DOMAINS)->assertProductConfiguration('astrology', []))
            ->toThrow(InvalidArgumentException::class, 'No domain module registered for key [astrology].');
    });

    it('returns the domain own capability', function () {
        expect(registryWith(VALID_TEST_DOMAINS)->capabilityFor('oracle', 'oracle.lucky_number'))
            ->toBeInstanceOf(LuckyNumberCapability::class);
    });
});
