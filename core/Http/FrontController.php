<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Offline\OfflineWhitelist;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\View\ConfigurationMode;
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
        private AppConfig $config, // @phpstan-ignore property.onlyWritten
        private ?OfflineWhitelist $offlineWhitelist = null
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

        // Fil d'Ariane (breadcrumb_bar.html.twig) — set only once the visitor
        // is actually allowed to reach this route, so a 403 page never leaks
        // the trail of a page the visitor can't access. Purely a navigation
        // convenience (SECURITY §3), never a security boundary.
        $this->twig->addGlobal('route_breadcrumb', $resolvedRoute->breadcrumb);

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

        return $this->applyEtagIfEligible($request, $response);
    }

    /**
     * Part 3.3 of the offline-mode work (ARCHITECTURE §8.25): an ETag on
     * a whitelisted page lets the pre-download script (public/assets/js/
     * offline-prefetch.js) re-validate a cached copy with `If-None-Match`
     * instead of re-downloading it wholesale on every app launch — a
     * derivative-URL-style "cheap to confirm still current" story for
     * HTML the way Core\Photo\ImageVariantService's `immutable`
     * Cache-Control already is for image derivatives. Deliberately narrow:
     * only 200 GET responses, only on a whitelisted path, never in
     * configuration mode (its overlay markup is session-specific and must
     * never be revalidated away), never on POST — nothing here makes
     * `/files/{id}` or any other route cacheable.
     */
    private function applyEtagIfEligible(Request $request, Response $response): Response
    {
        if ($this->offlineWhitelist === null
            || $request->getMethod() !== 'GET'
            || $response->getStatusCode() !== 200
            || ConfigurationMode::isActive()
            || !$this->offlineWhitelist->isPathWhitelisted($request->getPath())
        ) {
            return $response;
        }

        $etag = '"' . md5($response->getBody()) . '"';
        $ifNoneMatch = $request->getServer('HTTP_IF_NONE_MATCH');
        if (is_string($ifNoneMatch) && $ifNoneMatch === $etag) {
            return (new Response('', 304))->setHeader('ETag', $etag);
        }

        return $response->setHeader('ETag', $etag);
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
