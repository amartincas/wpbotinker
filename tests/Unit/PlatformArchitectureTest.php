<?php

/*
 * App\Platform is the domain-agnostic Core: domains plug in through
 * App\Platform\Contracts and configuration, never the other way round, and
 * the Core never reaches into the sales flow.
 */

arch('the Core does not depend on concrete domain modules')
    ->expect('App\Platform')
    ->not->toUse(['App\Domains', 'Tests']);

arch('the Core does not depend on the sales flow or the admin UI')
    ->expect('App\Platform')
    ->not->toUse([
        'App\Jobs',
        'App\Models\Lead',
        'App\Observers',
        'App\Services\Inventory',
        'App\Services\MetaConversionsApiService',
        'App\Services\LeadAlertService',
        'App\Livewire',
        'App\Filament',
    ]);

arch('domain modules implement the Core contract')
    ->expect('Tests\Support\Domains')
    ->toImplement('App\Platform\Contracts\DomainModule');
