<?php

use App\Platform\Domains\DomainRegistry;
use Tests\Support\Domains\OracleTestDomain;
use Tests\Support\Domains\WellnessTestDomain;

it('knows only the domains listed in configuration', function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class]]);

    $registry = app(DomainRegistry::class);

    expect($registry->keys())->toBe(['oracle'])
        ->and($registry->has('oracle'))->toBeTrue()
        ->and($registry->has('wellness'))->toBeFalse()
        ->and($registry->get('oracle'))->toBeInstanceOf(OracleTestDomain::class);
});

it('has no domains by default', function () {
    expect(app(DomainRegistry::class)->keys())->toBe([]);
});

it('rejects an unknown domain key', function () {
    expect(fn () => app(DomainRegistry::class)->get('oracle'))
        ->toThrow(InvalidArgumentException::class, 'No domain module registered for key [oracle].');
});

it('rejects a class that is not a domain module', function () {
    config(['botinker.domains' => ['oracle' => stdClass::class]]);

    expect(fn () => app(DomainRegistry::class)->get('oracle'))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

it('rejects a module registered under a different key than its own', function () {
    config(['botinker.domains' => ['oracle' => WellnessTestDomain::class]]);

    expect(fn () => app(DomainRegistry::class)->get('oracle'))
        ->toThrow(InvalidArgumentException::class, 'reports key [wellness]');
});
