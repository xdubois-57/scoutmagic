<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

class Router
{
    /**
     * The only accepted role_min values — same vocabulary as
     * Core\Security\Role and Core\Module\ModuleManifest::VALID_ROLES.
     *
     * @var string[]
     */
    private const VALID_ROLES = ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'];

    /** @var array<array{method: string, path: string, controllerClass: string, action: string, roleMin: string, breadcrumb: ?array{label: string, parents: array<string>}}> */
    private array $routes = [];

    /** @var string[] Module IDs for routes that belong to modules */
    private array $moduleRoutes = [];

    /**
     * @param ?array{label: string, parents: array<string>} $breadcrumb Optional breadcrumb declaration for this
     *   route (see partials/breadcrumb_bar.html.twig) — null when the route doesn't declare one, which is valid:
     *   the breadcrumb simply stops at the home icon for that page.
     */
    public function addRoute(string $method, string $path, string $controllerClass, string $action, string $roleMin, ?array $breadcrumb = null): void
    {
        // Required, not defaulted. SECURITY.md §3 promises "a route without
        // role_min is rejected at load time", and ModuleManifest enforces
        // exactly that for module routes — but a core route registered here
        // used to fall back to 'public', so forgetting the argument silently
        // published the route to anonymous visitors instead of failing. The
        // parameter is now mandatory (a missing one is a TypeError at boot),
        // and an unrecognised value is rejected below rather than being
        // quietly downgraded to 'public' by Role::fromString().
        if (!in_array($roleMin, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                "Route {$method} {$path} declares an unknown role_min '{$roleMin}'."
            );
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controllerClass' => $controllerClass,
            'action' => $action,
            'roleMin' => $roleMin,
            'breadcrumb' => $breadcrumb,
        ];
    }

    /**
     * Check if a resolved route belongs to a module.
     */
    public function getModuleForPath(string $path): ?string
    {
        return $this->moduleRoutes[$path] ?? null;
    }

    /**
     * Register routes from a module manifest, marking them as module routes.
     *
     * @param \Core\Module\ModuleManifest $manifest
     */
    public function registerModuleRoutes(\Core\Module\ModuleManifest $manifest): void
    {
        foreach ($manifest->routes as $route) {
            $this->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min'],
                $route['breadcrumb'] ?? null
            );
            $this->moduleRoutes[$route['path']] = $manifest->id;
        }
    }

    public function resolve(Request $request): ?ResolvedRoute
    {
        $requestMethod = $request->getMethod();
        $requestPath = $request->getPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $params = $this->matchPath($route['path'], $requestPath);

            if ($params !== null) {
                return new ResolvedRoute(
                    controllerClass: $route['controllerClass'],
                    action: $route['action'],
                    roleMin: $route['roleMin'],
                    params: $params,
                    breadcrumb: $route['breadcrumb']
                );
            }
        }

        return null;
    }

    /**
     * Match a route pattern against a request path, extracting parameters.
     *
     * @return array<string, string>|null Parameters if match, null otherwise
     */
    private function matchPath(string $pattern, string $path): ?array
    {
        // Convert route pattern to regex
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', function (array $matches): string {
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            // Extract only named parameters
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return null;
    }
}
