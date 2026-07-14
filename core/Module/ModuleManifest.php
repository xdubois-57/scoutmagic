<?php

declare(strict_types=1);

namespace Core\Module;

class ModuleManifest
{
    private const VALID_MENUS = [
        'notre_unite',
        'espace_animes',
        'espace_chefs',
        'espace_admin',
        'configuration',
    ];

    private const MENU_MIN_ROLES = [
        'notre_unite' => 'public',
        'espace_animes' => 'identified',
        'espace_chefs' => 'intendant',
        'espace_admin' => 'admin',
        'configuration' => 'superadmin',
    ];

    private const VALID_ROLES = ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'];

    private const ROLE_LEVELS = [
        'public' => 0,
        'identified' => 1,
        'intendant' => 2,
        'chief' => 3,
        'admin' => 4,
        'superadmin' => 5,
    ];

    private const VALID_COOKIE_CATEGORIES = ['necessary', 'functional', 'analytics'];

    /**
     * @param array<int, array{path: string, method: string, controller: string, action: string, menu: string, role_min: string, label: string}> $routes
     * @param array<int, array{key: string, default_value: string, type: string, label: string, description: string}> $settings
     * @param array<int, array{name: string, category: string, purpose: string, duration: string}> $cookies
     * @param array<int, array{key: string, handler: string}> $scheduledTasks
     * @param array<string, array{role_min: string}> $storage
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly array $routes,
        public readonly array $settings,
        public readonly array $cookies,
        public readonly array $scheduledTasks,
        public readonly array $storage
    ) {
    }

    /**
     * Parse and validate a module.json file.
     *
     * @throws ModuleException on validation failure
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new ModuleException("Module manifest not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ModuleException("Cannot read module manifest: {$path}");
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new ModuleException("Invalid JSON in module manifest: {$path}");
        }

        return self::fromArray($data, $path);
    }

    /**
     * Parse and validate from an already-decoded array.
     *
     * @param array<string, mixed> $data
     * @throws ModuleException on validation failure
     */
    public static function fromArray(array $data, string $sourcePath = ''): self
    {
        // Validate id
        if (empty($data['id']) || !is_string($data['id'])) {
            throw new ModuleException("Module manifest missing or invalid 'id'" . ($sourcePath ? " in {$sourcePath}" : ''));
        }
        $id = $data['id'];

        // Validate name
        if (empty($data['name']) || !is_string($data['name'])) {
            throw new ModuleException("Module '{$id}' manifest missing or invalid 'name'");
        }

        // Validate version (semver-like)
        if (empty($data['version']) || !is_string($data['version'])) {
            throw new ModuleException("Module '{$id}' manifest missing or invalid 'version'");
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
            throw new ModuleException("Module '{$id}' version must be semver format (x.y.z)");
        }

        // Validate routes
        $routes = [];
        if (isset($data['routes'])) {
            if (!is_array($data['routes'])) {
                throw new ModuleException("Module '{$id}' routes must be an array");
            }
            foreach ($data['routes'] as $i => $route) {
                $routes[] = self::validateRoute($id, $route, $i);
            }
        }

        // Validate settings
        $settings = [];
        if (isset($data['settings'])) {
            if (!is_array($data['settings'])) {
                throw new ModuleException("Module '{$id}' settings must be an array");
            }
            foreach ($data['settings'] as $i => $setting) {
                $settings[] = self::validateSetting($id, $setting, $i);
            }
        }

        // Validate cookies
        $cookies = [];
        if (isset($data['cookies'])) {
            if (!is_array($data['cookies'])) {
                throw new ModuleException("Module '{$id}' cookies must be an array");
            }
            foreach ($data['cookies'] as $i => $cookie) {
                $cookies[] = self::validateCookie($id, $cookie, $i);
            }
        }

        // Validate scheduled_tasks
        $scheduledTasks = [];
        if (isset($data['scheduled_tasks'])) {
            if (!is_array($data['scheduled_tasks'])) {
                throw new ModuleException("Module '{$id}' scheduled_tasks must be an array");
            }
            foreach ($data['scheduled_tasks'] as $i => $task) {
                $scheduledTasks[] = self::validateScheduledTask($id, $task, $i);
            }
        }

        // Validate storage
        $storage = [];
        if (isset($data['storage'])) {
            if (!is_array($data['storage'])) {
                throw new ModuleException("Module '{$id}' storage must be an object");
            }
            foreach ($data['storage'] as $dir => $config) {
                if (!is_string($dir) || !is_array($config) || empty($config['role_min'])) {
                    throw new ModuleException("Module '{$id}' storage entry '{$dir}' must have 'role_min'");
                }
                if (!in_array($config['role_min'], self::VALID_ROLES, true)) {
                    throw new ModuleException("Module '{$id}' storage entry '{$dir}' has invalid role_min");
                }
                $storage[$dir] = ['role_min' => $config['role_min']];
            }
        }

        return new self($id, $data['name'], $data['version'], $routes, $settings, $cookies, $scheduledTasks, $storage);
    }

    /**
     * @param array<string, mixed>|mixed $route
     * @return array{path: string, method: string, controller: string, action: string, menu: string, role_min: string, label: string}
     */
    private static function validateRoute(string $moduleId, mixed $route, int $index): array
    {
        if (!is_array($route)) {
            throw new ModuleException("Module '{$moduleId}' route[{$index}] must be an object");
        }

        $required = ['path', 'controller', 'action', 'menu', 'role_min'];
        foreach ($required as $field) {
            if (empty($route[$field]) || !is_string($route[$field])) {
                throw new ModuleException("Module '{$moduleId}' route[{$index}] missing or invalid '{$field}'");
            }
        }

        if (!in_array($route['menu'], self::VALID_MENUS, true)) {
            throw new ModuleException("Module '{$moduleId}' route[{$index}] invalid menu value '{$route['menu']}'");
        }

        if (!in_array($route['role_min'], self::VALID_ROLES, true)) {
            throw new ModuleException("Module '{$moduleId}' route[{$index}] invalid role_min '{$route['role_min']}'");
        }

        // Check role_min is not more permissive than menu minimum
        $menuMinRole = self::MENU_MIN_ROLES[$route['menu']];
        $menuLevel = self::ROLE_LEVELS[$menuMinRole];
        $routeLevel = self::ROLE_LEVELS[$route['role_min']];

        if ($routeLevel < $menuLevel) {
            throw new ModuleException(
                "Module '{$moduleId}' route[{$index}] role_min '{$route['role_min']}' is more permissive than menu '{$route['menu']}' minimum '{$menuMinRole}'"
            );
        }

        $method = strtoupper((string) ($route['method'] ?? 'GET'));
        $label = (string) ($route['label'] ?? '');

        return [
            'path' => $route['path'],
            'method' => $method,
            'controller' => $route['controller'],
            'action' => $route['action'],
            'menu' => $route['menu'],
            'role_min' => $route['role_min'],
            'label' => $label,
        ];
    }

    /**
     * @param array<string, mixed>|mixed $setting
     * @return array{key: string, default_value: string, type: string, label: string, description: string}
     */
    private static function validateSetting(string $moduleId, mixed $setting, int $index): array
    {
        if (!is_array($setting)) {
            throw new ModuleException("Module '{$moduleId}' settings[{$index}] must be an object");
        }

        $required = ['key', 'type', 'label', 'description'];
        foreach ($required as $field) {
            if (!isset($setting[$field]) || !is_string($setting[$field]) || $setting[$field] === '') {
                throw new ModuleException("Module '{$moduleId}' settings[{$index}] missing or invalid '{$field}'");
            }
        }

        return [
            'key' => $setting['key'],
            'default_value' => (string) ($setting['default_value'] ?? ''),
            'type' => $setting['type'],
            'label' => $setting['label'],
            'description' => $setting['description'],
        ];
    }

    /**
     * @param array<string, mixed>|mixed $cookie
     * @return array{name: string, category: string, purpose: string, duration: string}
     */
    private static function validateCookie(string $moduleId, mixed $cookie, int $index): array
    {
        if (!is_array($cookie)) {
            throw new ModuleException("Module '{$moduleId}' cookies[{$index}] must be an object");
        }

        $required = ['name', 'category', 'purpose', 'duration'];
        foreach ($required as $field) {
            if (empty($cookie[$field]) || !is_string($cookie[$field])) {
                throw new ModuleException("Module '{$moduleId}' cookies[{$index}] missing or invalid '{$field}'");
            }
        }

        if (!in_array($cookie['category'], self::VALID_COOKIE_CATEGORIES, true)) {
            throw new ModuleException("Module '{$moduleId}' cookies[{$index}] invalid category '{$cookie['category']}'");
        }

        return [
            'name' => $cookie['name'],
            'category' => $cookie['category'],
            'purpose' => $cookie['purpose'],
            'duration' => $cookie['duration'],
        ];
    }

    /**
     * @param array<string, mixed>|mixed $task
     * @return array{key: string, handler: string}
     */
    private static function validateScheduledTask(string $moduleId, mixed $task, int $index): array
    {
        if (!is_array($task)) {
            throw new ModuleException("Module '{$moduleId}' scheduled_tasks[{$index}] must be an object");
        }

        if (empty($task['key']) || !is_string($task['key'])) {
            throw new ModuleException("Module '{$moduleId}' scheduled_tasks[{$index}] missing or invalid 'key'");
        }

        if (empty($task['handler']) || !is_string($task['handler'])) {
            throw new ModuleException("Module '{$moduleId}' scheduled_tasks[{$index}] missing or invalid 'handler'");
        }

        return [
            'key' => $task['key'],
            'handler' => $task['handler'],
        ];
    }
}
