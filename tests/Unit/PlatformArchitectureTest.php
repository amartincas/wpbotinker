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

// A confirmed payment reaches the sales flow (Lead, Conversions API) only
// through this observer, outside the Core and after the review commits.
arch('the payment lead bridge lives outside the Core and runs after commit')
    ->expect('App\Observers\PaymentObserver')
    ->toImplement('Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit')
    ->not->toBeUsedIn('App\Platform');

arch('domain modules implement the Core contract')
    ->expect('Tests\Support\Domains')
    ->toImplement('App\Platform\Contracts\DomainModule')
    ->ignoring('Tests\Support\Domains\Capabilities');

arch('domain capabilities implement the Core contract')
    ->expect('Tests\Support\Domains\Capabilities')
    ->toImplement('App\Platform\Contracts\Capability');

// Facts reach a domain only as arguments: a domain never reads customer
// memory or the database on its own.
arch('domains do not access models or the database directly')
    ->expect(['App\Domains', 'Tests\Support\Domains'])
    ->not->toUse(['App\Models', 'Illuminate\Support\Facades\DB', 'Illuminate\Database']);

arch('domains do not use the memory or knowledge services')
    ->expect(['App\Domains', 'Tests\Support\Domains'])
    ->not->toUse([
        'App\Platform\Memory\CustomerMemory',
        'App\Platform\Memory\FactCollector',
        'App\Platform\Knowledge',
        'App\Platform\Conversation',
    ]);

// The pipeline orchestrates: memory and knowledge are reached only
// through their Core services, never through their models.
arch('the conversation layer does not touch memory or knowledge storage')
    ->expect('App\Platform\Conversation')
    ->not->toUse([
        'App\Models\Customer',
        'App\Models\CustomerFact',
        'App\Models\CustomerFactShare',
        'App\Models\ComputedResult',
        'App\Models\KnowledgeSource',
        'Illuminate\Support\Facades\DB',
    ]);
