<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\View\MenuBuilder;

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

    private const VALID_CHANNEL_VALUES = ['on', 'off', 'default_on', 'default_off'];

    private const VALID_OFFLINE_MATCH_VALUES = ['exact', 'child'];

    /**
     * @param array<int, array{path: string, method: string, controller: string, action: string, menu: string, role_min: string, label: string, menu_order: int, menu_order_explicit: bool, menu_icon: ?string, menu_group: ?string, breadcrumb: ?array{label: string, parents: array<string>}}> $routes
     * @param array<int, array{key: string, default_value: string, type: string, label: string, description: string, validation_regex: ?string, editable: bool}> $settings
     * @param array<int, array{name: string, category: string, purpose: string, duration: string}> $cookies
     * @param array<int, array{key: string, handler: string}> $scheduledTasks
     * @param array<string, array{role_min: string}> $storage
     * @param array<int, array{id: string, label: string, description: string, group: string, role_min: string, channels: array{in_app: string, push: string, email: string}, default_on_role_min: ?string}> $notifications
     * @param array<int, array{path: string, label: string, match: string, role_min: string}> $offline
     * @param array<int, string> $requires
     * @param array<int, string> $visibleWhen
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly array $routes,
        public readonly array $settings,
        public readonly array $cookies,
        public readonly array $scheduledTasks,
        public readonly array $storage,
        public readonly bool $enabledByDefault = false,
        public readonly string $description = '',
        public readonly array $notifications = [],
        public readonly array $offline = [],
        // Hard dependencies: module ids this module cannot function
        // without (Core\Module\ModuleManager resolves them). Deliberately
        // the LAST parameter with a default — ModuleManager::discoverModules()
        // builds placeholder manifests positionally (new ModuleManifest($dir,
        // $dir, '0.0.0', [], [], [], [], [])), so inserting a parameter
        // anywhere earlier would silently shift those arguments.
        public readonly array $requires = [],
        // Installation flags (Core\Module\InstallationProfile) gating this
        // module's visibility (ARCHITECTURE.md §8.49). Empty means "always
        // visible", which is every module's default.
        //
        // **Semantics are OR**: the module is visible as soon as ANY listed
        // flag holds for this installation, never only when all of them do.
        // A module listing ["reference_installation", "local_installation"]
        // is visible on the reference installation AND on a developer's
        // machine — that is the whole point of a list.
        //
        // A module whose flags do not hold is filtered out of
        // discoverModules(), so it never appears in the module registry
        // page, a menu, a route table or the scheduler. Same "last
        // parameter with a default" rule as $requires above, and for the
        // same reason.
        public readonly array $visibleWhen = [],
        // Optional override of the module's help-topics directory name
        // (module.json's `help.dir`, ARCHITECTURE.md §8.64). Null means
        // the default: ModuleManager scans `help/` when it exists. Same
        // "last parameter with a default" rule as the two above.
        public readonly ?string $helpDirectory = null
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
            self::validateNoShadowedRoutes($id, $routes);
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

        // Validate notifications
        $notifications = [];
        if (isset($data['notifications'])) {
            if (!is_array($data['notifications'])) {
                throw new ModuleException("Module '{$id}' notifications must be an array");
            }
            foreach ($data['notifications'] as $i => $notification) {
                $notifications[] = self::validateNotification($id, $notification, $i);
            }
        }

        // Validate offline (Core\Offline\OfflineWhitelist aggregation)
        $offline = [];
        if (isset($data['offline'])) {
            if (!is_array($data['offline'])) {
                throw new ModuleException("Module '{$id}' offline must be an array");
            }
            foreach ($data['offline'] as $i => $entry) {
                $offline[] = self::validateOfflineEntry($id, $entry, $i);
            }
        }

        // Validate requires (hard dependencies, Core\Module\ModuleManager)
        $requires = [];
        if (isset($data['requires'])) {
            if (!is_array($data['requires'])) {
                throw new ModuleException("Module '{$id}' requires must be an array");
            }
            foreach (array_values($data['requires']) as $i => $requiredId) {
                if (!is_string($requiredId) || $requiredId === '') {
                    throw new ModuleException("Module '{$id}' requires[{$i}] must be a non-empty string");
                }
                if ($requiredId === $id) {
                    throw new ModuleException("Module '{$id}' requires itself");
                }
                if (in_array($requiredId, $requires, true)) {
                    throw new ModuleException("Module '{$id}' requires[{$i}] duplicates module '{$requiredId}'");
                }
                $requires[] = $requiredId;
            }
        }

        $enabledByDefault = (bool) ($data['enabled_by_default'] ?? false);
        $description = (string) ($data['description'] ?? '');

        // Validate visible_when (Core\Module\InstallationProfile flags).
        //
        // Strict on purpose, and this is the whole reason the field replaced
        // a plain boolean: the failure mode of getting it wrong is a module
        // that silently does not exist anywhere, which ARCHITECTURE.md §8.49
        // itself calls close to undebuggable. So a non-list, a non-string
        // element and an unknown flag name are all load-time errors, and the
        // message names both the offending value and the known set rather
        // than leaving the author to guess the spelling.
        $visibleWhen = [];
        if (isset($data['visible_when'])) {
            if (!is_array($data['visible_when']) || !array_is_list($data['visible_when'])) {
                throw new ModuleException("Module '{$id}' visible_when must be a list of flag names");
            }
            foreach ($data['visible_when'] as $i => $flag) {
                if (!is_string($flag)) {
                    throw new ModuleException("Module '{$id}' visible_when[{$i}] must be a string");
                }
                if (!in_array($flag, InstallationProfile::KNOWN_FLAGS, true)) {
                    $known = implode(', ', InstallationProfile::KNOWN_FLAGS);
                    throw new ModuleException("Module '{$id}' visible_when[{$i}] is not a known installation flag: '{$flag}' (known: {$known})");
                }
                if (in_array($flag, $visibleWhen, true)) {
                    throw new ModuleException("Module '{$id}' visible_when[{$i}] duplicates flag '{$flag}'");
                }
                $visibleWhen[] = $flag;
            }
        }

        // Validate help (Core\Help\HelpRegistry aggregation, ARCHITECTURE.md
        // §8.64). The section is OPTIONAL and only ever overrides the topics
        // directory name — a module that simply ships a help/ directory needs
        // no manifest section at all (ModuleManager scans the default name),
        // so adding a topic never requires touching code or JSON.
        $helpDirectory = null;
        if (isset($data['help'])) {
            // json_decode turns an empty JSON object into an empty PHP
            // array, which array_is_list() reports as a list — accept it
            // (it just selects the default directory name).
            if (!is_array($data['help']) || ($data['help'] !== [] && array_is_list($data['help']))) {
                throw new ModuleException("Module '{$id}' help must be an object");
            }
            foreach (array_keys($data['help']) as $key) {
                if ($key !== 'dir') {
                    throw new ModuleException("Module '{$id}' help declares an unknown key '{$key}' (only 'dir' is supported)");
                }
            }
            $dir = $data['help']['dir'] ?? 'help';
            // A bare directory name, never a path: the topics always live
            // inside the module's own tree.
            if (!is_string($dir) || $dir === '' || preg_match('#^[A-Za-z0-9_-]+$#', $dir) !== 1) {
                throw new ModuleException("Module '{$id}' help.dir must be a plain directory name");
            }
            $helpDirectory = $dir;
        }

        return new self($id, $data['name'], $data['version'], $routes, $settings, $cookies, $scheduledTasks, $storage, $enabledByDefault, $description, $notifications, $offline, $requires, $visibleWhen, $helpDirectory);
    }

    /**
     * @param array<string, mixed>|mixed $route
     * @return array{path: string, method: string, controller: string, action: string, menu: string, role_min: string, label: string, menu_order: int, menu_order_explicit: bool, menu_icon: ?string, menu_group: ?string, breadcrumb: ?array{label: string, parents: array<string>}}
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

        // Optional: where this route's menu entry sorts relative to other
        // pages in its menu (lower = earlier). Defaults to 100, after core
        // pages and dynamic entries (e.g. Espace membres member pages,
        // which use order 10+). A module can set a lower value to appear
        // before those, e.g. the trombinoscope page before per-member pages.
        $menuOrder = 100;
        $menuOrderExplicit = isset($route['menu_order']);
        if ($menuOrderExplicit) {
            if (!is_int($route['menu_order'])) {
                throw new ModuleException("Module '{$moduleId}' route[{$index}] 'menu_order' must be an integer");
            }
            $menuOrder = $route['menu_order'];
        }

        // Optional: the Bootstrap Icons class shown in front of this
        // entry in the mobile menu, so a module's page lines its label up
        // with the per-member entries' avatars instead of starting
        // further left (partials/nav.html.twig). Declared rather than
        // derived: only the module knows what its page is about, and a
        // core-side map keyed on paths would be exactly the kind of
        // module knowledge that does not belong in core. An entry that
        // declares none gets a neutral fallback at render time.
        $menuIcon = null;
        if (isset($route['menu_icon'])) {
            if (!is_string($route['menu_icon'])) {
                throw new ModuleException("Module '{$moduleId}' route[{$index}] 'menu_icon' must be a string");
            }
            // A class name, never markup: it is interpolated into a
            // class attribute, so anything outside the Bootstrap Icons
            // vocabulary's own shape is refused rather than escaped.
            if ($route['menu_icon'] !== '' && preg_match('/^bi-[a-z0-9-]+$/', $route['menu_icon']) !== 1) {
                throw new ModuleException(
                    "Module '{$moduleId}' route[{$index}] 'menu_icon' must be a Bootstrap Icons class, e.g. 'bi-calendar3'"
                );
            }
            $menuIcon = $route['menu_icon'] !== '' ? $route['menu_icon'] : null;
        }

        // Optional: which named column of the menu this entry is drawn in
        // on the desktop mega-menu (Core\View\MenuBuilder::MENU_GROUPS).
        // A closed vocabulary per menu, exactly like `menu` itself above,
        // and for the same reason: a free string would let two modules
        // write "Gestion" and "gestion" and produce two columns meaning
        // the same thing. Absent is fine — the entry then lands in that
        // menu's last declared group (see MenuBuilder::addPage()).
        $menuGroup = null;
        if (isset($route['menu_group'])) {
            if (!is_string($route['menu_group'])) {
                throw new ModuleException("Module '{$moduleId}' route[{$index}] 'menu_group' must be a string");
            }

            $validGroups = MenuBuilder::groupIdsFor($route['menu']);
            if (!in_array($route['menu_group'], $validGroups, true)) {
                throw new ModuleException(
                    "Module '{$moduleId}' route[{$index}] invalid menu_group value '{$route['menu_group']}' for menu '{$route['menu']}'"
                );
            }

            $menuGroup = $route['menu_group'];
        }

        $breadcrumb = self::validateBreadcrumb($moduleId, $route['breadcrumb'] ?? null, $index);

        return [
            'menu_icon' => $menuIcon,
            'menu_group' => $menuGroup,
            'path' => $route['path'],
            'method' => $method,
            'controller' => $route['controller'],
            'action' => $route['action'],
            'menu' => $route['menu'],
            'role_min' => $route['role_min'],
            'label' => $label,
            'menu_order' => $menuOrder,
            // An explicit menu_order (e.g. gallery/trombinoscope sorting
            // their espace_animes page before dynamically-injected
            // per-member pages) is a deliberate placement the module author
            // chose relative to specific other entries in that menu, and is
            // left completely untouched by module reordering below — only
            // routes using the plain default get shifted by the module's
            // position on the general configuration page (see
            // ModuleManager::loadModule()).
            'menu_order_explicit' => $menuOrderExplicit,
            'breadcrumb' => $breadcrumb,
        ];
    }

    /**
     * Core\Http\Router::resolve() matches routes in registration order and
     * stops at the first pattern match — a `{param}` segment matches ANY
     * literal, including one that a later, differently-shaped route
     * declares as a fixed word at that same position. This only rejects a
     * genuine, always-triggered shadow: the earlier route's pattern must
     * fully SUBSUME the later one's — wildcard (or an identical literal)
     * at every single position, with a wildcard specifically where the
     * later route has a fixed word — so that literally every real request
     * meant for the later route matches the earlier one too, no matter
     * what real value the later route's own wildcard segments carry (e.g.
     * "/x/{id}/{token}" registered before "/x/demande/{id}" swallows
     * "/x/demande/42" as id="demande", token="42" for ANY id — the real
     * showLinked() route is never reached, the id cast to int becomes 0,
     * and the visitor sees a plain 404 with no clue why).
     *
     * Deliberately NOT flagged: the earlier route having its OWN literal
     * where the later route has a wildcard (e.g. "/mass-mail/unsubscribe/
     * {id}" vs "/mass-mail/{id}/status" — colliding only for the
     * essentially impossible case of a mass-mail id that is literally the
     * string "unsubscribe", never a real auto-increment id) — that's a
     * coincidental overlap for one contrived value, not a real one always
     * hit by genuine traffic, and reordering can't remove it anyway (the
     * same coincidental overlap just swaps which of the two routes it
     * would hit). The reverse-shaped case is also fine and common on
     * purpose (e.g. "/news/manage" declared before "/news/{id}" so the
     * literal action isn't itself swallowed as an id). Different literals
     * at the same position (e.g. "/a/edit/{id}" vs "/a/view/{id}") can
     * never both match the same request path and are never flagged.
     *
     * @param array<int, array{path: string, method: string}> $routes
     * @throws ModuleException
     */
    private static function validateNoShadowedRoutes(string $moduleId, array $routes): void
    {
        $count = count($routes);
        for ($earlier = 0; $earlier < $count; $earlier++) {
            for ($later = $earlier + 1; $later < $count; $later++) {
                if ($routes[$earlier]['method'] !== $routes[$later]['method']) {
                    continue;
                }

                $earlierSegments = explode('/', trim($routes[$earlier]['path'], '/'));
                $laterSegments = explode('/', trim($routes[$later]['path'], '/'));
                if (count($earlierSegments) !== count($laterSegments)) {
                    continue;
                }

                $earlierSubsumesLater = true;
                $hasAWildcardWhereLaterIsLiteral = false;
                foreach ($earlierSegments as $index => $earlierSegment) {
                    $laterSegment = $laterSegments[$index];
                    $earlierIsWildcard = str_starts_with($earlierSegment, '{');
                    $laterIsWildcard = str_starts_with($laterSegment, '{');

                    if ($earlierIsWildcard) {
                        if (!$laterIsWildcard) {
                            $hasAWildcardWhereLaterIsLiteral = true;
                        }
                        continue;
                    }
                    // Earlier is literal here: only a genuine subsumption
                    // if the later route has that exact same literal too —
                    // a later wildcard, or a different literal, both mean
                    // the earlier route does NOT match every real request
                    // the later one would receive.
                    if ($laterIsWildcard || $earlierSegment !== $laterSegment) {
                        $earlierSubsumesLater = false;
                        break;
                    }
                }

                if ($earlierSubsumesLater && $hasAWildcardWhereLaterIsLiteral) {
                    throw new ModuleException(
                        "Module '{$moduleId}' route '{$routes[$earlier]['path']}' ({$routes[$earlier]['method']}, "
                        . "declared first) shadows '{$routes[$later]['path']}' — a wildcard segment in the earlier "
                        . "route matches anywhere the later route has a fixed word, so the later route can never "
                        . "be reached. Declare the more specific (literal-segment) route first."
                    );
                }
            }
        }
    }

    /**
     * Optional per-route breadcrumb declaration (partials/breadcrumb_bar.html.twig).
     * Absent entirely → the breadcrumb simply stops at the home icon for this page,
     * which is not an error.
     *
     * @param mixed $breadcrumb
     * @return ?array{label: string, parents: array<string>}
     */
    private static function validateBreadcrumb(string $moduleId, mixed $breadcrumb, int $index): ?array
    {
        if ($breadcrumb === null) {
            return null;
        }

        if (!is_array($breadcrumb)) {
            throw new ModuleException("Module '{$moduleId}' route[{$index}] 'breadcrumb' must be an object");
        }

        if (empty($breadcrumb['label']) || !is_string($breadcrumb['label'])) {
            throw new ModuleException("Module '{$moduleId}' route[{$index}] breadcrumb missing or invalid 'label'");
        }

        $parents = [];
        if (isset($breadcrumb['parents'])) {
            if (!is_array($breadcrumb['parents'])) {
                throw new ModuleException("Module '{$moduleId}' route[{$index}] breadcrumb 'parents' must be an array");
            }
            foreach ($breadcrumb['parents'] as $parent) {
                if (!is_string($parent) || $parent === '') {
                    throw new ModuleException("Module '{$moduleId}' route[{$index}] breadcrumb 'parents' must be an array of non-empty strings");
                }
                $parents[] = $parent;
            }
        }

        return [
            'label' => $breadcrumb['label'],
            'parents' => $parents,
        ];
    }

    /**
     * @param array<string, mixed>|mixed $setting
     * @return array{key: string, default_value: string, type: string, label: string, description: string, validation_regex: ?string, editable: bool}
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

        // Optional `editable` (bool, default true). A false setting is
        // still registered, still readable and still writable in code — it
        // simply never renders as an editable row on Configuration >
        // Paramètres (core/View/templates/config/settings.html.twig), so a
        // switch with its own dedicated UI and its own consequences is
        // toggled there and only there. Typed strictly for the same reason
        // visible_when is: a truthy "false" would quietly publish a switch
        // that was meant to stay off that page.
        if (isset($setting['editable']) && !is_bool($setting['editable'])) {
            throw new ModuleException("Module '{$moduleId}' settings[{$index}] editable must be a boolean");
        }

        return [
            'key' => $setting['key'],
            'default_value' => (string) ($setting['default_value'] ?? ''),
            'type' => $setting['type'],
            'label' => $setting['label'],
            'description' => $setting['description'],
            'validation_regex' => isset($setting['validation_regex']) && is_string($setting['validation_regex']) && $setting['validation_regex'] !== ''
                ? $setting['validation_regex'] : null,
            // "editable": false declares a setting the Paramètres page does
            // not render at all (core/View/templates/config/settings.html.twig
            // gates the whole row on it) and SettingService::set() refuses —
            // e.g. mass_mail's merge_retention_months, or test_tools' mail
            // capture switch, which is toggled only from its own page.
            'editable' => $setting['editable'] ?? true,
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
     * @param array<string, mixed>|mixed $notification
     * @return array{id: string, label: string, description: string, group: string, role_min: string, channels: array{in_app: string, push: string, email: string}, default_on_role_min: ?string}
     */
    private static function validateNotification(string $moduleId, mixed $notification, int $index): array
    {
        if (!is_array($notification)) {
            throw new ModuleException("Module '{$moduleId}' notifications[{$index}] must be an object");
        }

        $required = ['id', 'label', 'description', 'group', 'role_min'];
        foreach ($required as $field) {
            if (empty($notification[$field]) || !is_string($notification[$field])) {
                throw new ModuleException("Module '{$moduleId}' notifications[{$index}] missing or invalid '{$field}'");
            }
        }

        if (!in_array($notification['role_min'], self::VALID_ROLES, true)) {
            throw new ModuleException("Module '{$moduleId}' notifications[{$index}] invalid role_min '{$notification['role_min']}'");
        }

        if (!str_starts_with($notification['id'], $moduleId . '.')) {
            throw new ModuleException("Module '{$moduleId}' notifications[{$index}] id '{$notification['id']}' must be prefixed '{$moduleId}.'");
        }

        if (!isset($notification['channels']) || !is_array($notification['channels'])) {
            throw new ModuleException("Module '{$moduleId}' notifications[{$index}] missing 'channels'");
        }

        $channels = [];
        foreach (['in_app', 'push', 'email'] as $channel) {
            $value = $notification['channels'][$channel] ?? null;
            if (!is_string($value) || !in_array($value, self::VALID_CHANNEL_VALUES, true)) {
                throw new ModuleException("Module '{$moduleId}' notifications[{$index}] channels.{$channel} must be one of: " . implode(', ', self::VALID_CHANNEL_VALUES));
            }
            $channels[$channel] = $value;
        }

        // Optional: the role at or above which this type's "default_on"
        // channels actually start on (Core\Notification\NotificationType::
        // defaultsOnForRole()) — a type offered to a wide role_min but
        // only switched on for a narrower one. Absent means "on for
        // everybody the type is offered to", which is every existing type.
        $defaultOnRoleMin = $notification['default_on_role_min'] ?? null;
        if ($defaultOnRoleMin !== null) {
            if (!is_string($defaultOnRoleMin) || !in_array($defaultOnRoleMin, self::VALID_ROLES, true)) {
                throw new ModuleException("Module '{$moduleId}' notifications[{$index}] invalid default_on_role_min");
            }
            if (!self::roleAtLeast($defaultOnRoleMin, $notification['role_min'])) {
                throw new ModuleException(
                    "Module '{$moduleId}' notifications[{$index}] default_on_role_min '{$defaultOnRoleMin}' must be at or above role_min '{$notification['role_min']}'"
                );
            }
        }

        return [
            'id' => $notification['id'],
            'label' => $notification['label'],
            'description' => $notification['description'],
            'group' => $notification['group'],
            'role_min' => $notification['role_min'],
            'channels' => $channels,
            'default_on_role_min' => $defaultOnRoleMin,
        ];
    }

    /**
     * Whether $role is at least $minimum in the role hierarchy — reading
     * this class's own ROLE_LEVELS rather than reaching for
     * Core\Security\Role, because a manifest is validated before
     * anything is instantiated: one bad string must produce a
     * ModuleException, never a ValueError out of an enum.
     */
    private static function roleAtLeast(string $role, string $minimum): bool
    {
        return (self::ROLE_LEVELS[$role] ?? -1) >= (self::ROLE_LEVELS[$minimum] ?? -1);
    }

    /**
     * A module's own offline-cacheable page(s) — aggregated by
     * Core\Module\ModuleManager into Core\Offline\OfflineWhitelist. See
     * docs/module-development.md for the module-author-facing contract.
     *
     * @param array<string, mixed>|mixed $entry
     * @return array{path: string, label: string, match: string, role_min: string}
     */
    private static function validateOfflineEntry(string $moduleId, mixed $entry, int $index): array
    {
        if (!is_array($entry)) {
            throw new ModuleException("Module '{$moduleId}' offline[{$index}] must be an object");
        }

        $required = ['path', 'label', 'role_min'];
        foreach ($required as $field) {
            if (empty($entry[$field]) || !is_string($entry[$field])) {
                throw new ModuleException("Module '{$moduleId}' offline[{$index}] missing or invalid '{$field}'");
            }
        }

        if (!str_starts_with($entry['path'], '/')) {
            throw new ModuleException("Module '{$moduleId}' offline[{$index}] path must start with '/'");
        }

        if (!in_array($entry['role_min'], self::VALID_ROLES, true)) {
            throw new ModuleException("Module '{$moduleId}' offline[{$index}] invalid role_min '{$entry['role_min']}'");
        }

        $match = (string) ($entry['match'] ?? 'exact');
        if (!in_array($match, self::VALID_OFFLINE_MATCH_VALUES, true)) {
            throw new ModuleException("Module '{$moduleId}' offline[{$index}] invalid match '{$match}' — must be 'exact' or 'child'");
        }

        return [
            'path' => $entry['path'],
            'label' => $entry['label'],
            'match' => $match,
            'role_min' => $entry['role_min'],
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
