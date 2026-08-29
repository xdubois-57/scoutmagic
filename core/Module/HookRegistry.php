<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * The one place a module's implementation of a single-implementation CORE
 * hook (ARCHITECTURE.md §7.4) is registered, and the one place a core
 * consumer resolves it.
 *
 * Before this registry every hook travelled as its own nullable
 * constructor argument, which forced the null-then-re-register dance:
 * PageController was constructed SIX times in `public/index.php` — once
 * bare, then again in the trombinoscope, banner, finance, news and groups
 * blocks, each re-registration re-passing every hook the earlier ones had
 * set — and a 14th hook meant a new parameter threaded through all six
 * plus a null-seed. Now a module block calls `register()` once and a
 * consumer holds the registry, resolving at use time; adding a hook is
 * the interface, one `register()` line in the providing module's block,
 * and the consumer's `getOptional()` — zero threading.
 *
 * **This is NOT a dependency-injection container**, for the same reasons
 * `Core\Scheduler\TaskCapabilities` is not: registrations are explicit
 * lines in the composition root, keys are hook interface names only, and
 * `getOptional()` answers null for anything unregistered instead of
 * building it. There is no auto-wiring, no reflection, no string-to-class
 * resolution chain.
 *
 * One implementation per hook, enforced: a second `register()` for the
 * same interface throws instead of silently shadowing the first. Hooks
 * that genuinely accept SEVERAL contributors keep their own dedicated
 * registries — MenuEntryProvider through Core\View\DynamicMenuRegistrar,
 * SubProcessorProvider through RgpdContentService, DeskImportListener
 * through Core\Import\DeskImportListenerRegistry.
 *
 * Resolution at USE time is what makes consumers order-independent: a
 * controller constructed before the providing module's block still sees
 * the hook, because it holds the registry, not a snapshot. A SERVICE that
 * resolves at construction instead (the sos_staff block does, for the
 * responsable hook) stays order-sensitive exactly as a direct handle was
 * — the registry does not change when your block runs.
 */
class HookRegistry
{
    /** @var array<class-string, object> */
    private array $implementations = [];

    /**
     * @template T of object
     * @param class-string<T> $hookInterface
     * @param T $implementation
     */
    public function register(string $hookInterface, object $implementation): void
    {
        if (!interface_exists($hookInterface)) {
            throw new \LogicException("'{$hookInterface}' is not an interface — hooks are registered under their Core\\Module interface name.");
        }
        if (!$implementation instanceof $hookInterface) {
            throw new \LogicException(get_class($implementation) . " does not implement {$hookInterface}.");
        }
        if (isset($this->implementations[$hookInterface])) {
            throw new \LogicException("A '{$hookInterface}' implementation is already registered — one implementation per hook; multi-contributor hooks use their own dedicated registry.");
        }

        $this->implementations[$hookInterface] = $implementation;
    }

    /**
     * The registered implementation, or null when no enabled module
     * provides one — the consumer then degrades exactly as it did when
     * its nullable constructor argument was null.
     *
     * @template T of object
     * @param class-string<T> $hookInterface
     * @return T|null
     */
    public function getOptional(string $hookInterface): ?object
    {
        $implementation = $this->implementations[$hookInterface] ?? null;
        \assert($implementation === null || $implementation instanceof $hookInterface);

        return $implementation;
    }
}
