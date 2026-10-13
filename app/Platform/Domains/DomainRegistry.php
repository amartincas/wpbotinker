<?php

namespace App\Platform\Domains;

use App\Platform\Contracts\Capability;
use App\Platform\Contracts\DomainModule;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\PersonFacts;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The domain modules this installation knows about, from
 * config('botinker.domains'). Modules are resolved through the container,
 * so a module can declare its own constructor dependencies.
 *
 * Every module is validated against the contract the first time it is
 * resolved: a misconfigured domain fails loudly instead of misbehaving in
 * a conversation.
 */
class DomainRegistry
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, DomainModule> */
    private array $resolved = [];

    /** @var array<string, array<string, Capability>> domain key => capability key => capability */
    private array $capabilities = [];

    /**
     * @param  array<string, class-string<DomainModule>>  $modules  domain key => module class
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $modules,
        private readonly PersonFacts $personFacts,
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
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

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

        $factKeys = $this->validateFacts($key, $module);
        $this->capabilities[$key] = $this->validateCapabilities($key, $module, $factKeys);

        return $this->resolved[$key] = $module;
    }

    /** Validates every configured domain (e.g. from a deploy check or a test). */
    public function validateAll(): void
    {
        foreach ($this->keys() as $key) {
            $this->get($key);
        }
    }

    /**
     * The capability a product of $domainKey may run. A capability of
     * another domain is rejected, even if that domain is registered.
     */
    public function capabilityFor(string $domainKey, string $capabilityKey): Capability
    {
        $this->get($domainKey);

        if (isset($this->capabilities[$domainKey][$capabilityKey])) {
            return $this->capabilities[$domainKey][$capabilityKey];
        }

        $owner = strstr($capabilityKey, '.', true);

        if ($owner !== false && $owner !== $domainKey && $this->has($owner)) {
            throw new InvalidArgumentException("Capability [{$capabilityKey}] belongs to domain [{$owner}]; a product of domain [{$domainKey}] cannot use it.");
        }

        throw new InvalidArgumentException("Domain [{$domainKey}] has no capability [{$capabilityKey}].");
    }

    /**
     * Checks what a product declares against its domain: the domain exists,
     * every required fact is declared by it, and the capability is its own.
     *
     * @param  list<string>  $requiredFacts
     */
    public function assertProductConfiguration(string $domainKey, array $requiredFacts, ?string $capabilityKey = null): void
    {
        $declared = array_map(fn (FactDefinition $fact) => $fact->key, $this->get($domainKey)->facts());

        foreach ($requiredFacts as $factKey) {
            if (! in_array($factKey, $declared, true)) {
                throw new InvalidArgumentException("Product of domain [{$domainKey}] requires fact [{$factKey}], which the domain does not declare.");
            }
        }

        if ($capabilityKey !== null) {
            // Everything the capability needs must be asked for by the
            // product, so it is collected before the capability runs.
            foreach ($this->capabilityFor($domainKey, $capabilityKey)->requires() as $factKey) {
                if (! in_array($factKey, $requiredFacts, true)) {
                    throw new InvalidArgumentException("Capability [{$capabilityKey}] requires fact [{$factKey}]; add it to the product's required facts.");
                }
            }
        }
    }

    /**
     * @return list<string> declared fact keys
     */
    private function validateFacts(string $domainKey, DomainModule $module): array
    {
        $keys = [];

        foreach ($module->facts() as $fact) {
            if (! $fact instanceof FactDefinition) {
                throw new InvalidArgumentException("Domain [{$domainKey}] must declare facts as ".FactDefinition::class.'.');
            }

            if (! preg_match(self::KEY_PATTERN, $fact->key)) {
                throw new InvalidArgumentException("Domain [{$domainKey}] declares fact [{$fact->key}] with an invalid key (expected lowercase letters, digits and _).");
            }

            if (in_array($fact->key, $keys, true)) {
                throw new InvalidArgumentException("Domain [{$domainKey}] declares fact [{$fact->key}] twice.");
            }

            if ($fact->isPerson()) {
                if (! $this->personFacts->has($fact->key)) {
                    throw new InvalidArgumentException("Domain [{$domainKey}] references unknown person fact [{$fact->key}].");
                }

                // Fails on a malformed catalog entry (type, question).
                $this->personFacts->type($fact->key);
            } else {
                if ($this->personFacts->has($fact->key)) {
                    throw new InvalidArgumentException("Domain [{$domainKey}] declares its own fact [{$fact->key}], which is a person fact; reference it with FactDefinition::person().");
                }

                if (trim((string) $fact->question) === '') {
                    throw new InvalidArgumentException("Domain [{$domainKey}] fact [{$fact->key}] needs a question.");
                }
            }

            $keys[] = $fact->key;
        }

        return $keys;
    }

    /**
     * @param  list<string>  $factKeys
     * @return array<string, Capability>
     */
    private function validateCapabilities(string $domainKey, DomainModule $module, array $factKeys): array
    {
        $capabilities = [];

        foreach ($module->capabilities() as $class) {
            $capability = $this->container->make($class);

            if (! $capability instanceof Capability) {
                throw new InvalidArgumentException("Domain [{$domainKey}] capability [{$class}] must implement ".Capability::class.'.');
            }

            $key = $capability->key();

            if (! str_starts_with($key, "{$domainKey}.") || ! preg_match(self::KEY_PATTERN, substr($key, strlen($domainKey) + 1))) {
                throw new InvalidArgumentException("Capability [{$key}] of domain [{$domainKey}] must be named [{$domainKey}.<name>].");
            }

            if (isset($capabilities[$key])) {
                throw new InvalidArgumentException("Domain [{$domainKey}] declares capability [{$key}] twice.");
            }

            foreach ($capability->requires() as $factKey) {
                if (! in_array($factKey, $factKeys, true)) {
                    throw new InvalidArgumentException("Capability [{$key}] requires fact [{$factKey}], which domain [{$domainKey}] does not declare.");
                }
            }

            $capabilities[$key] = $capability;
        }

        return $capabilities;
    }
}
