<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Offline\OfflineWhitelist;
use Core\View\MenuBuilder;

class ModuleManager
{
    // Effective menu_order for a module's default-order (non-explicit)
    // routes is BASE + position*STEP + the route's own (always-100) value —
    // 1000 apart is far more than any single module will ever declare
    // routes in one menu. This value only ever breaks ties *within* the
    // module group now (Core\View\MenuBuilder::buildPages() sorts by group
    // — dynamic, then core, then module — before it ever looks at `order`),
    // so unlike before this file's own numeric convention no longer has to
    // carry the "core sorts first" guarantee by itself.
    private const MODULE_ORDER_BASE = 1000;
    private const MODULE_ORDER_STEP = 1000;

    /** @var array<string, string> module_id::task_key => handler class */
    private array $taskHandlers = [];

    /** @var string[] */
    private array $enabledModuleIds = [];

    public function __construct(
        private string $modulesDir,
        private SettingService $settingService,
        private CookieConsentService $cookieConsentService,
        private MenuBuilder $menuBuilder,
        private ModuleRegistryRepository $registryRepo,
        private MigrationRunner $migrationRunner,
        private JournalService $journalService,
        private Router $router,
        private ?NotificationService $notificationService = null,
        private OfflineWhitelist $offlineWhitelist = new OfflineWhitelist(),
        // The named flags that hold for THIS installation
        // (ARCHITECTURE.md §8.49). Resolved once by the composition root
        // via InstallationProfile::resolve(base_url,
        // statistics_destination) and passed in already decided — this
        // class only ever asks the profile whether a flag holds, and
        // deliberately has no business knowing what a statistics
        // destination or a reference host is.
        private InstallationProfile $installationProfile = new InstallationProfile()
    ) {
    }

    /**
     * Scan modules/ directory, read each module.json, compare with registry.
     *
     * @return ModuleInfo[]
     */
    public function discoverModules(): array
    {
        $modules = [];
        $registryEntries = $this->registryRepo->findAll();
        $registryMap = [];
        foreach ($registryEntries as $entry) {
            $registryMap[$entry['module_id']] = $entry;
        }

        // A module with no registry row yet (never toggled, not
        // enabled_by_default) has no persisted position — it sorts after
        // every module the admin has actually seen/ordered, ties broken
        // alphabetically. Deliberately read-only: no row is created here,
        // so this never interferes with loadEnabledModules()'s own
        // "registry row === null" check for first-discovery auto-activation
        // below. A row (and a real sort_order) is only ever created when
        // the module is toggled (upsert()) or explicitly reordered
        // (see reorder()).
        $sortKeys = [];

        // Scan disk
        if (is_dir($this->modulesDir)) {
            $dirs = scandir($this->modulesDir);
            if ($dirs !== false) {
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') {
                        continue;
                    }
                    $fullPath = $this->modulesDir . '/' . $dir;
                    if (!is_dir($fullPath)) {
                        continue;
                    }
                    $manifestPath = $fullPath . '/module.json';
                    $validationError = null;
                    $manifest = null;

                    try {
                        $manifest = ModuleManifest::fromFile($manifestPath);
                        // Verify id matches directory name
                        if ($manifest->id !== $dir) {
                            throw new ModuleException("Module id '{$manifest->id}' does not match directory name '{$dir}'");
                        }
                    } catch (ModuleException $e) {
                        $validationError = $e->getMessage();
                        // Create a dummy manifest for display
                        $manifest = new ModuleManifest($dir, $dir, '0.0.0', [], [], [], [], []);
                    }

                    // A module gated by visible_when is invisible unless ONE
                    // of its flags holds (OR semantics): filtered here, once,
                    // so routes, menus, the module registry page and the
                    // scheduler all follow automatically. This is ergonomic,
                    // not a security boundary — the real protection is that a
                    // module which is not loaded has no routes at all.
                    //
                    // The unset() matters as much as the continue: without it
                    // the hidden module falls through to the "in registry but
                    // missing from disk" loop below and reappears there.
                    if ($manifest->visibleWhen !== [] && !$this->installationProfile->hasAny($manifest->visibleWhen)) {
                        unset($registryMap[$dir]);
                        continue;
                    }

                    $registry = $registryMap[$dir] ?? null;
                    $enabled = $registry !== null && $registry['enabled'];
                    $installedVersion = $registry['installed_version'] ?? null;

                    $modules[$dir] = new ModuleInfo($manifest, $enabled, $installedVersion, true, $validationError);
                    $sortKeys[$dir] = $registry['sort_order'] ?? PHP_INT_MAX;
                    unset($registryMap[$dir]);
                }
            }
        }

        // Modules in registry but missing from disk
        foreach ($registryMap as $moduleId => $entry) {
            $manifest = new ModuleManifest($moduleId, $moduleId, $entry['installed_version'], [], [], [], [], []);
            $modules[$moduleId] = new ModuleInfo($manifest, $entry['enabled'], $entry['installed_version'], false, null);
            $sortKeys[$moduleId] = $entry['sort_order'];
        }

        uksort($modules, fn(string $a, string $b) => [$sortKeys[$a], $a] <=> [$sortKeys[$b], $b]);
        return array_values($modules);
    }

    /**
     * Persist a new module display/menu order from the general
     * configuration page's drag-and-drop list. A module the admin drags
     * but has never toggled (no registry row yet) gets one created here
     * first (disabled — dragging it must not implicitly enable it), purely
     * so it has somewhere to persist its new position.
     *
     * @param string[] $orderedModuleIds
     */
    public function reorder(array $orderedModuleIds): void
    {
        foreach ($orderedModuleIds as $moduleId) {
            if ($this->registryRepo->findByModuleId($moduleId) !== null) {
                continue;
            }

            $version = '0.0.0';
            $manifestPath = $this->modulesDir . '/' . $moduleId . '/module.json';
            if (file_exists($manifestPath)) {
                try {
                    $version = ModuleManifest::fromFile($manifestPath)->version;
                } catch (ModuleException) {
                    // Invalid manifest — still give it a registry row so its
                    // position persists, just with a placeholder version.
                }
            }

            $this->registryRepo->upsert($moduleId, false, $version, null);
        }

        $this->registryRepo->reorder($orderedModuleIds);
    }

    /**
     * Is every hard dependency declared in this module's manifest
     * ("requires") present on disk, free of validation errors, and enabled?
     *
     * The check is transitive: a dependency that is itself missing one of
     * its own dependencies cannot function, so it cannot satisfy anyone
     * either. A dependency cycle makes every module in it unsatisfiable
     * (rather than recursing forever) — a module in a cycle can never be
     * reached from a fully-satisfied root anyway.
     *
     * $modules is the already-discovered set (discoverModules()), so the
     * answer never depends on the order modules are visited in, and
     * resolution costs no database query of its own.
     *
     * @param ModuleInfo[] $modules
     */
    public function areRequirementsSatisfied(string $moduleId, array $modules): bool
    {
        $byId = self::indexById($modules);
        $resolved = [];

        return self::resolveRequirements($moduleId, $byId, [], $resolved);
    }

    /**
     * Which currently-enabled modules declare $moduleId as a hard
     * dependency — i.e. which ones would stop working if it were
     * deactivated. Direct dependents only: an indirect dependent is always
     * held back by its own direct dependency, so there is never anything to
     * cascade.
     *
     * A dependent that is enabled but missing from disk or carrying a
     * validation error is ignored: it is not loaded on any request, so it
     * has nothing to lose here and must not wedge the registry page.
     *
     * @param ModuleInfo[] $modules
     * @return ModuleInfo[]
     */
    public function findEnabledDependents(string $moduleId, array $modules): array
    {
        $dependents = [];
        foreach ($modules as $module) {
            if (!$module->enabled || !$module->presentOnDisk || $module->validationError !== null) {
                continue;
            }
            if (in_array($moduleId, $module->manifest->requires, true)) {
                $dependents[] = $module;
            }
        }

        return $dependents;
    }

    /**
     * Load all enabled modules: register routes, settings, cookies, menu pages, task handlers.
     */
    public function loadEnabledModules(): void
    {
        $modules = $this->discoverModules();

        // A module declaring "enabled_by_default" is auto-activated the
        // very first time it is discovered (no registry row yet). An
        // admin's later explicit deactivation is always respected — this
        // never re-activates a module that already has a registry row.
        //
        // Done in its own pass, before anything is loaded, so the
        // dependency resolution below sees the final enabled set whatever
        // the admin's sort order is. (Within this pass itself an
        // auto-activated module only satisfies a dependent that is
        // discovered after it; a dependent discovered earlier is simply
        // auto-activated on the next request instead — never a broken load.)
        foreach ($modules as $index => $module) {
            if (!$module->presentOnDisk || $module->validationError !== null) {
                continue;
            }
            if ($module->enabled || !$module->manifest->enabledByDefault) {
                continue;
            }
            if ($this->registryRepo->findByModuleId($module->manifest->id) !== null) {
                continue;
            }
            if (!$this->areRequirementsSatisfied($module->manifest->id, $modules)) {
                continue;
            }

            $this->activate($module->manifest->id, null);
            $modules[$index] = new ModuleInfo($module->manifest, true, $module->manifest->version, true, null);
        }

        $modulePosition = 0;

        foreach ($modules as $module) {
            if (!$module->presentOnDisk || $module->validationError !== null) {
                continue;
            }

            if (!$module->enabled) {
                continue;
            }

            // A module whose hard dependencies are not all present, valid
            // and enabled is simply not loaded — no routes, no settings, no
            // menu entries, no task handlers, and never a fatal error. The
            // check runs against the full discovered set rather than
            // $this->enabledModuleIds, which only fills progressively inside
            // this loop and would report a dependency sorted after its
            // dependent as absent.
            if (!$this->areRequirementsSatisfied($module->manifest->id, $modules)) {
                $this->journalService->log(
                    'core',
                    'module_requirements_unmet',
                    // event_log.level is a MySQL ENUM('info', 'security') —
                    // any other value makes the INSERT itself throw, which
                    // would turn "this module is quietly skipped" into a
                    // fatal on every request. Same reason every other
                    // non-security failure log in the codebase uses 'info'
                    // (see Core\Http\Controller\RgpdConfigController).
                    'info',
                    "Module « {$module->manifest->id} » non chargé : un module requis est absent, invalide ou désactivé",
                    ['module_id' => $module->manifest->id, 'requires' => $module->manifest->requires]
                );
                continue;
            }

            // Auto-migrate when module version is newer than installed version
            if ($module->installedVersion !== null
                && version_compare($module->manifest->version, $module->installedVersion, '>')
            ) {
                $schemaPath = $this->modulesDir . '/' . $module->manifest->id . '/schema.sql';
                $migrationComplete = true;
                if (file_exists($schemaPath)) {
                    $migrationComplete = $this->migrationRunner->migrate([$schemaPath])->complete;
                }

                // Only record the new version once its migration actually
                // finished — MigrationRunner::migrate() can return early
                // (its own time budget) with the schema not yet fully
                // caught up. Bumping the registry version anyway would make
                // this version_compare() check stop matching, permanently
                // stranding the module mid-migration since nothing would
                // ever call migrate() for it again.
                if ($migrationComplete) {
                    $this->registryRepo->upsert($module->manifest->id, true, $module->manifest->version, null);
                }
            }

            $this->enabledModuleIds[] = $module->manifest->id;
            $this->loadModule($module->manifest, $modulePosition);
            $modulePosition++;
        }
    }

    /**
     * Activate a module. $activatedBy is null for system-initiated activation
     * (e.g. auto-activation of an "enabled_by_default" module on first
     * discovery — there is no admin user to attribute it to).
     *
     * @throws ModuleException on validation failure or migration error
     */
    public function activate(string $moduleId, ?int $activatedBy): void
    {
        $manifestPath = $this->modulesDir . '/' . $moduleId . '/module.json';
        $manifest = ModuleManifest::fromFile($manifestPath);

        if ($manifest->id !== $moduleId) {
            throw new ModuleException("Module id '{$manifest->id}' does not match directory name '{$moduleId}'");
        }

        // Hard dependencies are checked here, before anything else this
        // method does — the schema migration below is not something to run
        // for a module that is then refused activation.
        $unmet = $this->unmetRequirementNames($manifest, $this->discoverModules());
        if ($unmet !== []) {
            $quoted = implode(', ', array_map(fn(string $name) => "« {$name} »", $unmet));
            throw new ModuleException(count($unmet) > 1
                ? "Le module « {$manifest->name} » nécessite les modules {$quoted}. Activez-les d'abord."
                : "Le module « {$manifest->name} » nécessite le module {$quoted}. Activez-le d'abord.");
        }

        // Run schema migration if schema.sql exists. A caller retrying
        // after this exception resumes automatically — MigrationRunner
        // persists its own progress, keyed by the schema file, independent
        // of this method ever being called again.
        $schemaPath = $this->modulesDir . '/' . $moduleId . '/schema.sql';
        if (file_exists($schemaPath)) {
            $migrationResult = $this->migrationRunner->migrate([$schemaPath]);
            if (!$migrationResult->complete) {
                throw new ModuleException("La migration du schéma du module '{$moduleId}' n'a pas pu se terminer dans le temps imparti — réessayez l'activation dans un instant, elle reprendra automatiquement là où elle s'est arrêtée.");
            }
        }

        // Register default settings
        foreach ($manifest->settings as $setting) {
            $this->settingService->register(
                $setting['key'],
                $setting['default_value'],
                $setting['type'],
                $setting['label'],
                $setting['description'],
                $moduleId,
                null,
                null,
                $setting['editable']
            );
        }

        // Create/update registry entry
        $this->registryRepo->upsert($moduleId, true, $manifest->version, $activatedBy);

        $this->journalService->log(
            'core',
            'module_activated',
            'info',
            "Module « {$moduleId} » activé (v{$manifest->version})",
            ['module_id' => $moduleId, 'version' => $manifest->version],
            $activatedBy
        );
    }

    /**
     * Deactivate a module. Never drops tables or deletes data.
     *
     * @throws ModuleException when another enabled module hard-depends on it
     */
    public function deactivate(string $moduleId, int $deactivatedBy): void
    {
        // Refused rather than cascaded: which of the dependent modules the
        // admin is willing to lose is their decision, never ours.
        $dependents = $this->findEnabledDependents($moduleId, $this->discoverModules());
        if ($dependents !== []) {
            $quoted = implode(', ', array_map(fn(ModuleInfo $m) => "« {$m->manifest->name} »", $dependents));
            throw new ModuleException(count($dependents) > 1
                ? "Ce module est requis par les modules {$quoted}. Désactivez-les d'abord."
                : "Ce module est requis par le module {$quoted}. Désactivez-le d'abord.");
        }

        $this->registryRepo->setEnabled($moduleId, false);

        $this->journalService->log(
            'core',
            'module_deactivated',
            'info',
            "Module « {$moduleId} » désactivé",
            ['module_id' => $moduleId],
            $deactivatedBy
        );
    }

    /**
     * Get the handler class for a scheduled task.
     */
    public function getTaskHandler(string $moduleId, string $taskKey): ?string
    {
        return $this->taskHandlers[$moduleId . '::' . $taskKey] ?? null;
    }

    /**
     * @return string[]
     */
    public function getEnabledModuleIds(): array
    {
        return $this->enabledModuleIds;
    }

    /**
     * Load a single module: register its routes, settings, cookies, menu pages, task handlers.
     * $modulePosition is this module's index among enabled modules in their
     * current admin-defined order (see reorder()) — it only affects routes
     * using the default menu_order (see ModuleManifest::validateRoute()).
     */
    private function loadModule(ModuleManifest $manifest, int $modulePosition): void
    {
        // Register routes
        foreach ($manifest->routes as $route) {
            $this->router->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min'],
                $route['breadcrumb']
            );

            // Register menu page if route has a label
            if ($route['label'] !== '') {
                $menuOrder = $route['menu_order_explicit']
                    ? $route['menu_order']
                    : self::MODULE_ORDER_BASE + ($modulePosition * self::MODULE_ORDER_STEP) + $route['menu_order'];

                $this->menuBuilder->addPage(
                    $route['menu'],
                    $route['label'],
                    $route['path'],
                    $route['role_min'],
                    $menuOrder,
                    false,
                    null,
                    MenuBuilder::GROUP_MODULE,
                    // module.json's own `menu_icon` — null when the
                    // module declares none, which renders the neutral
                    // fallback rather than nothing (partials/nav.html.twig).
                    $route['menu_icon'] ?? null
                );
            }
        }

        // Register settings
        foreach ($manifest->settings as $setting) {
            $this->settingService->register(
                $setting['key'],
                $setting['default_value'],
                $setting['type'],
                $setting['label'],
                $setting['description'],
                $manifest->id,
                null,
                null,
                $setting['editable']
            );
        }

        // Register cookies
        if (!empty($manifest->cookies)) {
            $this->cookieConsentService->registerModuleCookies($manifest->id, $manifest->cookies);
        }

        // Register notification types
        if (!empty($manifest->notifications)) {
            $this->notificationService?->registerModuleTypes($manifest->id, $manifest->notifications);
        }

        // Register offline-cacheable pages (Core\Offline\OfflineWhitelist)
        if (!empty($manifest->offline)) {
            $this->offlineWhitelist->registerModuleEntries($manifest->id, $manifest->offline);
        }

        // Register scheduled task handlers
        foreach ($manifest->scheduledTasks as $task) {
            $this->taskHandlers[$manifest->id . '::' . $task['key']] = $task['handler'];
        }
    }

    /**
     * Display names of the hard dependencies this manifest declares that
     * cannot currently satisfy it — used to tell the admin, in French,
     * which module(s) to install or activate first. A dependency missing
     * from disk has no manifest to read a name from, so its id is the only
     * thing left to name it by.
     *
     * @param ModuleInfo[] $modules
     * @return string[]
     */
    private function unmetRequirementNames(ModuleManifest $manifest, array $modules): array
    {
        $byId = self::indexById($modules);
        $resolved = [];
        $names = [];

        foreach ($manifest->requires as $requiredId) {
            $required = $byId[$requiredId] ?? null;
            if (self::canSatisfy($required)
                && self::resolveRequirements($requiredId, $byId, [$manifest->id => true], $resolved)
            ) {
                continue;
            }

            $names[] = $required !== null && $required->presentOnDisk ? $required->manifest->name : $requiredId;
        }

        return $names;
    }

    /**
     * Recursive core of areRequirementsSatisfied(). $visiting carries the
     * current resolution path so a cycle is answered "unsatisfiable"
     * instead of recursing until the stack blows; $resolved memoizes
     * already-answered modules so a diamond-shaped dependency graph stays
     * linear in the number of modules.
     *
     * @param array<string, ModuleInfo> $byId
     * @param array<string, bool> $visiting
     * @param array<string, bool> $resolved
     */
    private static function resolveRequirements(string $moduleId, array $byId, array $visiting, array &$resolved): bool
    {
        if (isset($resolved[$moduleId])) {
            return $resolved[$moduleId];
        }
        if (isset($visiting[$moduleId])) {
            return false;
        }

        $module = $byId[$moduleId] ?? null;
        if ($module === null) {
            return false;
        }

        $visiting[$moduleId] = true;
        foreach ($module->manifest->requires as $requiredId) {
            $required = $byId[$requiredId] ?? null;
            if (!self::canSatisfy($required)
                || !self::resolveRequirements($requiredId, $byId, $visiting, $resolved)
            ) {
                return $resolved[$moduleId] = false;
            }
        }

        return $resolved[$moduleId] = true;
    }

    /**
     * A module can only satisfy someone else's requirement when it is
     * actually usable: on disk, with a manifest that parses and validates,
     * and enabled in the registry.
     */
    private static function canSatisfy(?ModuleInfo $module): bool
    {
        return $module !== null
            && $module->presentOnDisk
            && $module->validationError === null
            && $module->enabled;
    }

    /**
     * @param ModuleInfo[] $modules
     * @return array<string, ModuleInfo>
     */
    private static function indexById(array $modules): array
    {
        $byId = [];
        foreach ($modules as $module) {
            $byId[$module->manifest->id] = $module;
        }

        return $byId;
    }
}
