<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use App\Services\AI\GeminiService;
use App\Models\Lead;
use App\Models\Product;
use App\Observers\LeadObserver;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Memory\PersonFacts;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Exception traces must not carry function arguments: they can hold
        // customer messages and personal facts, and traces reach the logs.
        ini_set('zend.exception_ignore_args', '1');

        // Domain modules for stores in 'knowledge' mode and the person fact
        // catalog they reference (config/botinker.php).
        $this->app->singleton(PersonFacts::class, fn () => new PersonFacts(config('botinker.person_facts', [])));
        $this->app->singleton(DomainRegistry::class, fn ($app) => new DomainRegistry($app, config('botinker.domains', []), $app->make(PersonFacts::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        
        Schema::defaultStringLength(191);

        if (app()->environment('production') || env('FORCE_HTTPS')) {
            URL::forceScheme('https');
        }

        $this->configureDefaults();

        // Register Livewire components
        Livewire::component('whats-app-chat-center', \App\Livewire\WhatsAppChatCenter::class);

        // Sends every new Lead (bot, manual, backfill) to Meta's Conversions API
        Lead::observe(LeadObserver::class);

        // A domain product may only require facts its domain declares and use
        // its own domain's capabilities (main and sample), from any write
        // path (panel, seeders, scripts). Sales products and products of
        // unregistered domains are not checked.
        Product::saving(function (Product $product) {
            $registry = $this->app->make(DomainRegistry::class);

            if ($product->domain_key && $registry->has($product->domain_key)) {
                $registry->assertProductConfiguration($product->domain_key, $product->required_facts ?? [], $product->capability_key, $product->sample_capability_key);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
