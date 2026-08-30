<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\InstallationProfile;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Offline\OfflineWhitelist;
use Core\Security\Role;
use Core\View\MenuBuilder;

/**
 * Turns every module the installation ships on, through the application's own
 * Core\Module\ModuleManager::activate().
 *
 * **Why every module, and why first.** The dataset used to test each module's
 * state before applying the extras that depend on it, and skip the ones whose
 * module was off. That is the right behaviour when a module genuinely is not
 * there — and it is a silent gap when the module is merely one nobody thought
 * to switch on: the build reported a counter of zero, said "ignoré", and the
 * page it was meant to fill stayed empty for a reason nobody read. Activating
 * everything before anything is written removes the gap rather than reporting
 * it, and leaves the end state the roadmap asks for: every module enabled.
 *
 * **Through the module service, never a row in `module_registry`.** Activation
 * creates the module's default settings, registers its routes and writes its
 * journal line; an `INSERT` into the registry sets a flag and does none of the
 * three, which is exactly the sort of half-installed instance a "recipe, not a
 * dump" fixture exists to make impossible. The registration seeder, for
 * instance, sets `registration_form_open` through SettingService — a setting
 * that does not exist at all until this has run.
 *
 * The activation ORDER is a topological sort of the modules' `requires`
 * declarations, because `activate()` refuses a module whose hard dependencies
 * are not enabled yet (groups requires gallery). This mirrors
 * `scripts/e2e-support.php`'s `e2e_module_activation_order()`, which does the
 * same job for the browser harness — deliberately re-stated here rather than
 * required from a CLI script that is not in `phpstan.neon`'s paths and would
 * have to be loaded behind a constant to keep it from running its own
 * dispatcher.
 */
final class ModuleActivator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly SettingService $settingService,
        private readonly string $modulesDir,
    ) {
    }

    /**
     * @return array{activated: list<string>, failed: array<string, string>}
     *         the module ids enabled, in the order they were, and any that
     *         refused with the reason they gave
     */
    public function activateAll(): array
    {
        $manager = $this->manager();

        $requirements = [];
        foreach ($manager->discoverModules() as $module) {
            if (!$module->presentOnDisk || $module->validationError !== null) {
                continue;
            }
            $requirements[$module->manifest->id] = $module->manifest->requires;
        }

        $order = self::activationOrder($requirements);
        if ($order === null) {
            return ['activated' => [], 'failed' => ['*' => "Les déclarations `requires` des modules ne forment pas un ordre installable."]];
        }

        $activated = [];
        $failed = [];
        foreach ($order as $moduleId) {
            try {
                // A null activator: a system activation, exactly like the
                // `enabled_by_default` one public/index.php performs on first
                // discovery.
                $manager->activate($moduleId, null);
                $activated[] = $moduleId;
            } catch (\Throwable $exception) {
                $failed[$moduleId] = $exception->getMessage();
            }
        }

        return ['activated' => $activated, 'failed' => $failed];
    }

    /**
     * The order in which every module's hard dependencies are already enabled
     * when its turn comes. Alphabetical among the modules ready at each step,
     * so one repository always yields one order and a failure is
     * reproducible. Null when no order exists — a cycle, or a module
     * requiring one that is not on disk.
     *
     * @param array<string, list<string>> $requirements module id => required module ids
     * @return list<string>|null
     */
    public static function activationOrder(array $requirements): ?array
    {
        $pending = $requirements;
        $order = [];

        while ($pending !== []) {
            $ready = [];
            foreach ($pending as $moduleId => $requires) {
                $unmet = array_filter($requires, static fn (string $required): bool => !in_array($required, $order, true));
                if ($unmet === []) {
                    $ready[] = (string) $moduleId;
                }
            }

            if ($ready === []) {
                return null;
            }

            sort($ready);
            foreach ($ready as $moduleId) {
                $order[] = $moduleId;
                unset($pending[$moduleId]);
            }
        }

        return $order;
    }

    /**
     * The same ModuleManager the admin's Modules page builds, minus the
     * notification service — a build has nobody to tell that a module was
     * switched on.
     *
     * The installation profile is resolved from the instance's own settings
     * rather than invented: it decides which modules are visible at all
     * (ARCHITECTURE.md §8.49), and a hand-built profile would silently hide
     * the receiver-only modules from a build that is meant to enable
     * everything.
     */
    private function manager(): ModuleManager
    {
        return new ModuleManager(
            $this->modulesDir,
            $this->settingService,
            new CookieConsentService(),
            new MenuBuilder(Role::SUPERADMIN),
            new ModuleRegistryRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo)),
            new Router(),
            null,
            new OfflineWhitelist(),
            InstallationProfile::resolve(
                (string) ($this->settingService->get('base_url') ?? ''),
                (string) ($this->settingService->get('statistics_destination') ?? ''),
            ),
        );
    }
}
