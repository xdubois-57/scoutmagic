<?php

declare(strict_types=1);

namespace Core\Module;

use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\View\MenuBuilder;

class ModuleManager
{
    // Effective menu_order for a module's default-order (non-explicit)
    // routes is BASE + position*STEP + the route's own (always-100) value —
    // 1000 apart is far more than any single module will ever declare
    // routes in one menu, and BASE (1000) is always above every hardcoded
    // core page order (all ≤ ~50 today), so core pages sort first without
    // needing any structural guarantee beyond that convention.
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
        private ?NotificationService $notificationService = null
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
     * Load all enabled modules: register routes, settings, cookies, menu pages, task handlers.
     */
    public function loadEnabledModules(): void
    {
        $modules = $this->discoverModules();
        $modulePosition = 0;

        foreach ($modules as $module) {
            if (!$module->presentOnDisk || $module->validationError !== null) {
                continue;
            }

            // A module declaring "enabled_by_default" is auto-activated the
            // very first time it is discovered (no registry row yet). An
            // admin's later explicit deactivation is always respected — this
            // never re-activates a module that already has a registry row.
            if (!$module->enabled && $module->manifest->enabledByDefault
                && $this->registryRepo->findByModuleId($module->manifest->id) === null) {
                $this->activate($module->manifest->id, null);
                $module = new ModuleInfo($module->manifest, true, $module->manifest->version, true, null);
            }

            if (!$module->enabled) {
                continue;
            }

            // Auto-migrate when module version is newer than installed version
            if ($module->installedVersion !== null
                && version_compare($module->manifest->version, $module->installedVersion, '>')
            ) {
                $schemaPath = $this->modulesDir . '/' . $module->manifest->id . '/schema.sql';
                if (file_exists($schemaPath)) {
                    $this->migrationRunner->migrate([$schemaPath]);
                }
                $this->registryRepo->upsert($module->manifest->id, true, $module->manifest->version, null);
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

        // Run schema migration if schema.sql exists
        $schemaPath = $this->modulesDir . '/' . $moduleId . '/schema.sql';
        if (file_exists($schemaPath)) {
            $this->migrationRunner->migrate([$schemaPath]);
        }

        // Register default settings
        foreach ($manifest->settings as $setting) {
            $this->settingService->register(
                $setting['key'],
                $setting['default_value'],
                $setting['type'],
                $setting['label'],
                $setting['description'],
                $moduleId
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
     */
    public function deactivate(string $moduleId, int $deactivatedBy): void
    {
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
                    $menuOrder
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
                $manifest->id
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

        // Register scheduled task handlers
        foreach ($manifest->scheduledTasks as $task) {
            $this->taskHandlers[$manifest->id . '::' . $task['key']] = $task['handler'];
        }
    }
}
