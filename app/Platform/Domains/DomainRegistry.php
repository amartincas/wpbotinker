<?php

namespace App\Platform\Domains;

use App\Models\Topic;
use App\Platform\Contracts\Capability;
use App\Platform\Contracts\DomainModule;
use App\Platform\Memory\FactDefinition;
use App\Platform\Memory\FactTypes;
use App\Platform\Memory\PersonFacts;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The domains a store can use: the PHP modules of config('botinker.domains')
 * (shared by every store) plus the store's own active topics, configured in
 * the panel and served as ConfiguredDomain. Without a store, only the
 * modules are known.
 *
 * A module key always wins: a topic can never take a module's key. Modules
 * are validated against the contract the first time they are resolved and
 * fail loudly. A stored topic that no longer validates (e.g. the person
 * catalog changed) is left out and logged, so it cannot break a
 * conversation.
 *
 * Bound as scoped (one instance per request or queued job), and topics are
 * forgotten whenever one is saved: a long-running worker never serves
 * stale instructions.
 */
class DomainRegistry
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, DomainModule> */
    private array $resolved = [];

    /** @var array<string, array<string, Capability>> module key => capability key => capability */
    private array $capabilities = [];

    /** @var array<int, array<string, ConfiguredDomain>> store id => topic key => domain */
    private array $topics = [];

    /**
     * @param  array<string, class-string<DomainModule>>  $modules  domain key => module class
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $modules,
        private readonly PersonFacts $personFacts,
        private readonly FactTypes $factTypes = new FactTypes,
    ) {}

    public function has(string $key, ?int $storeId = null): bool
    {
        return isset($this->modules[$key]) || isset($this->topicsFor($storeId)[$key]);
    }

    /**
     * Module keys, then the store's topic keys.
     *
     * @return list<string>
     */
    public function keys(?int $storeId = null): array
    {
        return [...array_keys($this->modules), ...array_keys($this->topicsFor($storeId))];
    }

    /** Whether $key is a PHP module (as opposed to a configured topic). */
    public function isModule(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    public function get(string $key, ?int $storeId = null): DomainModule
    {
        if (isset($this->modules[$key])) {
            return $this->module($key);
        }

        return $this->topicsFor($storeId)[$key]
            ?? throw new InvalidArgumentException("No domain module registered for key [{$key}].");
    }

    /** Validates every configured module (e.g. from a deploy check or a test). */
    public function validateAll(): void
    {
        foreach (array_keys($this->modules) as $key) {
            $this->module($key);
        }
    }

    /** Drops the cached topics; called whenever a topic is saved or deleted. */
    public function forgetTopics(): void
    {
        $this->topics = [];
    }

    /**
     * The capability a product of $domainKey may run. A capability of
     * another domain is rejected, even if that domain is registered.
     * Topics have no capabilities of their own.
     */
    public function capabilityFor(string $domainKey, string $capabilityKey, ?int $storeId = null): Capability
    {
        $this->get($domainKey, $storeId);

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
     * Keys of the capabilities a product of $domainKey may use.
     *
     * @return list<string>
     */
    public function capabilityKeys(string $domainKey, ?int $storeId = null): array
    {
        $this->get($domainKey, $storeId);

        return array_keys($this->capabilities[$domainKey] ?? []);
    }

    /**
     * Checks what a product declares against its domain: the domain exists,
     * every required fact is declared by it, and its capabilities (main and
     * free sample) are its own.
     *
     * @param  list<string>  $requiredFacts
     */
    public function assertProductConfiguration(string $domainKey, array $requiredFacts, ?string $capabilityKey = null, ?string $sampleCapabilityKey = null, ?int $storeId = null): void
    {
        $declared = array_map(fn (FactDefinition $fact) => $fact->key, $this->get($domainKey, $storeId)->facts());

        foreach ($requiredFacts as $factKey) {
            if (! in_array($factKey, $declared, true)) {
                throw new InvalidArgumentException("Product of domain [{$domainKey}] requires fact [{$factKey}], which the domain does not declare.");
            }
        }

        foreach (array_filter([$capabilityKey, $sampleCapabilityKey]) as $key) {
            // Everything a capability needs must be asked for by the
            // product, so it is collected before the capability runs.
            foreach ($this->capabilityFor($domainKey, $key, $storeId)->requires() as $factKey) {
                if (! in_array($factKey, $requiredFacts, true)) {
                    throw new InvalidArgumentException("Capability [{$key}] requires fact [{$factKey}]; add it to the product's required facts.");
                }
            }
        }
    }

    private function module(string $key): DomainModule
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
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

    /**
     * The store's active topics as domains, loaded once per store. Topics
     * whose key a module uses are ignored: the module wins.
     *
     * @return array<string, ConfiguredDomain>
     */
    private function topicsFor(?int $storeId): array
    {
        if ($storeId === null) {
            return [];
        }

        if (isset($this->topics[$storeId])) {
            return $this->topics[$storeId];
        }

        $domains = [];

        foreach (Topic::where('store_id', $storeId)->where('is_active', true)->orderBy('id')->get() as $topic) {
            if (isset($this->modules[$topic->key])) {
                continue;
            }

            $domain = new ConfiguredDomain($topic->key, $topic->label, $topic->vocabulary ?? [], $topic->instructions, $topic->facts ?? [], $this->factTypes);

            try {
                $this->validateFacts($topic->key, $domain);
            } catch (InvalidArgumentException) {
                Log::warning('TOPIC_INVALID', ['store_id' => $storeId, 'topic_id' => $topic->id]);

                continue;
            }

            $domains[$topic->key] = $domain;
        }

        return $this->topics[$storeId] = $domains;
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
