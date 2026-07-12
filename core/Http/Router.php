<?php

declare(strict_types=1);

namespace Core\Http;

class Router
{
    /** @var array<array{method: string, path: string, controllerClass: string, action: string, roleMin: string}> */
    private array $routes = [];

    /** @var string[] Module IDs for routes that belong to modules */
    private array $moduleRoutes = [];

    public function addRoute(string $method, string $path, string $controllerClass, string $action, string $roleMin = 'public'): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controllerClass' => $controllerClass,
            'action' => $action,
            'roleMin' => $roleMin,
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
                $route['role_min']
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
                    params: $params
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
