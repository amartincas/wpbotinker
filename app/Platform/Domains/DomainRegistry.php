<?php

namespace App\Platform\Domains;

use App\Platform\Contracts\DomainModule;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The domain modules this installation knows about, from
 * config('botinker.domains'). Modules are resolved through the container,
 * so a module can declare its own constructor dependencies.
 */
class DomainRegistry
{
    /**
     * @param  array<string, class-string<DomainModule>>  $modules  domain key => module class
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $modules,
    ) {}

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->modules);
    }

    public function get(string $key): DomainModule
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("No domain module registered for key [{$key}].");
        }

        $module = $this->container->make($this->modules[$key]);

        if (! $module instanceof DomainModule) {
            throw new InvalidArgumentException("Domain [{$key}] must implement ".DomainModule::class.'.');
        }

        if ($module->key() !== $key) {
            throw new InvalidArgumentException("Domain registered as [{$key}] reports key [{$module->key()}].");
        }

        return $module;
    }
}
