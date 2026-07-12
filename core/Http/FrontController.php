<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Twig\Environment;

class FrontController
{
    /** @var array<string, AbstractController> */
    private array $controllerInstances = [];

    private RbacGuard $rbacGuard;
    private bool $rbacBypass = false;

    public function __construct(
        private Router $router,
        private Environment $twig,
        private AppConfig $config // @phpstan-ignore property.onlyWritten
    ) {
        $this->rbacGuard = new RbacGuard();
    }

    /**
     * Register a pre-built controller instance for a specific class.
     */
    public function registerController(string $className, AbstractController $instance): void
    {
        $this->controllerInstances[$className] = $instance;
    }

    /**
     * Set a path prefix that should bypass RBAC enforcement.
     * Used for /setup routes when the site is not initialized.
     */
    public function setRbacBypassPrefix(string $prefix): void
    {
        $this->rbacBypass = true;
        $this->rbacBypassPrefix = $prefix;
    }

    /** @var string */
    private string $rbacBypassPrefix = '';

    public function handle(Request $request): Response
    {
        $resolvedRoute = $this->router->resolve($request);

        if ($resolvedRoute === null) {
            return $this->renderNotFound();
        }

        // RBAC guard — enforced on EVERY route, no exceptions
        $requiredRole = Role::fromString($resolvedRoute->roleMin);

        // Special case: bypass prefix skips RBAC (e.g. /setup when not initialized)
        $skipRbac = $this->rbacBypass
            && str_starts_with($request->getPath(), $this->rbacBypassPrefix);

        if (!$skipRbac) {
            $guardResponse = $this->rbacGuard->enforce($requiredRole);
            if ($guardResponse !== null) {
                if ($guardResponse->getStatusCode() === 403) {
                    return $this->renderForbidden();
                }
                return $guardResponse;
            }
        }

        $controllerClass = $resolvedRoute->controllerClass;
        $action = $resolvedRoute->action;

        // Use pre-registered instance if available, otherwise create with Twig only
        if (isset($this->controllerInstances[$controllerClass])) {
            $controller = $this->controllerInstances[$controllerClass];
        } else {
            $controller = new $controllerClass($this->twig);
        }

        /** @var Response $response */
        $response = $controller->$action($request, $resolvedRoute->params);

        return $response;
    }

    private function renderNotFound(): Response
    {
        $html = $this->twig->render('errors/404.html.twig');

        return (new Response($html))->setStatusCode(404);
    }

    private function renderForbidden(): Response
    {
        $html = $this->twig->render('errors/403.html.twig');

        return (new Response($html))->setStatusCode(403);
    }
}
