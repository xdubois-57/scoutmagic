<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Module\ModuleManager;

/**
 * The optional cross-module capabilities a task handler may ask for
 * through {@see TaskContext::getOptional()}.
 *
 * A task handler is auto-resolved with `new $handlerClass()` and receives
 * only a TaskContext, so before this existed every handler needing
 * another module's capability rebuilt that module's internals by hand
 * from the PDO — bypassing both the `Api\` contract and the enablement
 * check (ARCHITECTURE.md §7.5 held on the HTTP path and not on the
 * scheduled one). This registry is the §7.5 pattern brought to the
 * scheduler: the composition root (public/scheduler-bootstrap.php, shared
 * by both entry points) registers a factory per published `Api\`
 * interface, and a handler resolves it by that interface — or gets null
 * and degrades, exactly like a nullable constructor dependency on the
 * HTTP path.
 *
 * This is deliberately NOT a DI container, which this project has
 * explicitly refused: the key set is closed (whatever the bootstrap
 * registered, nothing else), keys are `Api\` interface names (never a
 * free string), there is no auto-wiring and no transitive resolution —
 * each factory is a hand-written construction, exactly like the
 * composition root's own wiring, just deferred.
 *
 * Enablement is re-read from the ModuleManager on EVERY resolve, never
 * cached: a module's enabled state can change mid-request on the Modules
 * configuration page, and the scheduler tail of that very request must
 * see the new state (the same reason `getEnabledModuleIds()` is re-asked
 * everywhere in public/index.php rather than snapshotted).
 */
final class TaskCapabilities
{
    /** @var array<class-string, array{moduleId: string, factory: callable(): object}> */
    private array $factories = [];

    /** @var array<class-string, object> */
    private array $resolved = [];

    public function __construct(private readonly ModuleManager $moduleManager)
    {
    }

    /**
     * Declare that $interface is provided by $providerModuleId, built by
     * $factory when first resolved while that module is enabled.
     *
     * @param class-string $interface a published `Api\` interface
     * @param callable(): object $factory
     */
    public function register(string $interface, string $providerModuleId, callable $factory): void
    {
        if (isset($this->factories[$interface])) {
            // Two providers for one interface would make resolution depend
            // on registration order — the same trap FileAccessGuard's
            // owner_type registry refuses (ARCHITECTURE.md §8.3).
            throw new \LogicException("A capability is already registered for {$interface}");
        }

        $this->factories[$interface] = ['moduleId' => $providerModuleId, 'factory' => $factory];
    }

    /**
     * The capability, or null when it was never registered or its
     * providing module is not enabled right now. The instance is built
     * once and reused for the rest of the request; the enablement check
     * runs fresh on every call.
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return T|null
     */
    public function resolve(string $interface): ?object
    {
        $entry = $this->factories[$interface] ?? null;
        if ($entry === null) {
            return null;
        }

        if (!in_array($entry['moduleId'], $this->moduleManager->getEnabledModuleIds(), true)) {
            return null;
        }

        if (!isset($this->resolved[$interface])) {
            $instance = ($entry['factory'])();
            if (!$instance instanceof $interface) {
                // A factory returning the wrong type is a wiring bug in the
                // bootstrap; surfacing it loudly beats a handler getting a
                // TypeError three calls later.
                throw new \LogicException(
                    "The capability factory for {$interface} returned " . get_class($instance)
                );
            }
            $this->resolved[$interface] = $instance;
        }

        /** @var T */
        return $this->resolved[$interface];
    }

    public function isModuleEnabled(string $moduleId): bool
    {
        return in_array($moduleId, $this->moduleManager->getEnabledModuleIds(), true);
    }
}
