<?php

declare(strict_types=1);

namespace Core\Module;

use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\View\MenuBuilder;

class ModuleManager
{
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
        private Router $router
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
                    unset($registryMap[$dir]);
                }
            }
        }

        // Modules in registry but missing from disk
        foreach ($registryMap as $moduleId => $entry) {
            $manifest = new ModuleManifest($moduleId, $moduleId, $entry['installed_version'], [], [], [], [], []);
            $modules[$moduleId] = new ModuleInfo($manifest, $entry['enabled'], $entry['installed_version'], false, null);
        }

        ksort($modules);
        return array_values($modules);
    }

    /**
     * Load all enabled modules: register routes, settings, cookies, menu pages, task handlers.
     */
    public function loadEnabledModules(): void
    {
        $modules = $this->discoverModules();

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
            $this->loadModule($module->manifest);
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
     */
    private function loadModule(ModuleManifest $manifest): void
    {
        // Register routes
        foreach ($manifest->routes as $route) {
            $this->router->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min']
            );

            // Register menu page if route has a label
            if ($route['label'] !== '') {
                $this->menuBuilder->addPage(
                    $route['menu'],
                    $route['label'],
                    $route['path'],
                    $route['role_min'],
                    $route['menu_order']
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

        // Register scheduled task handlers
        foreach ($manifest->scheduledTasks as $task) {
            $this->taskHandlers[$manifest->id . '::' . $task['key']] = $task['handler'];
        }
    }
}
