<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Config\AppConfig;
use Core\Help\HelpService;
use Core\Http\Controller\AbstractController;
use Core\Http\Controller\HelpController;
use Core\Maintenance\MaintenanceGate;
use Core\Maintenance\UpdateHistory;
use Core\Offline\OfflineWhitelist;
use Core\Security\AuthSession;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\View\ConfigurationMode;
use Core\View\MarkdownRenderer;
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
        private ?OfflineWhitelist $offlineWhitelist = null,
        private ?MaintenanceGate $maintenanceGate = null,
        // Contextual help (ARCHITECTURE.md §8.64) — optional trailing
        // parameter for the same backward-compatibility reason as
        // $offlineWhitelist above: many test call sites build a
        // FrontController with three arguments. Null means no help
        // button/panel (route_help stays an empty list).
        private ?HelpService $helpService = null
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
        // Checked before route resolution, deliberately covering every
        // path (including ones that would otherwise 404) — see
        // Core\Maintenance\MaintenanceGate's own docblock for why. Null
        // means no gate was wired up for this entry point (tests, or any
        // future non-web entry point) — never blocking in that case.
        if ($this->maintenanceGate !== null) {
            $bypassRequested = $request->getQuery(MaintenanceGate::BYPASS_QUERY_PARAM) !== null;
            $blockingUpdate = $this->maintenanceGate->checkBlocking($bypassRequested);
            if ($blockingUpdate !== null) {
                return $this->renderMaintenanceInProgress($blockingUpdate);
            }
        }

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

        // Contextual help (partials/help_button.html.twig / help_panel.
        // html.twig) — the topics covering this path, filtered by the
        // caller's CURRENT role (Core\Help\HelpService is the single role
        // gate), each with its body already rendered so the panel works
        // offline with zero network calls. Set after the RBAC guard for
        // the same never-leak reason as route_breadcrumb above, and only
        // the topics that matched are ever read past their front matter.
        $this->twig->addGlobal('route_help', $this->buildRouteHelp($request));

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
     * The `route_help` Twig global: the topics covering the current path
     * for the current role, as ready-to-render arrays. Empty when no
     * HelpService is wired (tests, non-web entry points), on non-GET
     * requests (their responses are redirects or API payloads — no page
     * to put a panel on), and on pages no topic covers (the help button
     * then links to /aide instead of opening the panel).
     *
     * Bodies are rendered here, server-side, with the same options as
     * /aide/{id} (HelpController::RENDER_OPTIONS) — the panel must work
     * offline, so its content ships inside the page rather than being
     * fetched on open.
     *
     * @return array<int, array{id: string, title: string, summary: string, html: string}>
     */
    private function buildRouteHelp(Request $request): array
    {
        if ($this->helpService === null || $request->getMethod() !== 'GET') {
            return [];
        }

        $role = Role::fromString(AuthSession::getRole());
        $entries = [];
        foreach ($this->helpService->findForPath($request->getPath(), $role) as $topic) {
            $entries[] = [
                'id' => $topic->id,
                'title' => $topic->title,
                'summary' => $topic->summary,
                'html' => MarkdownRenderer::toHtml($topic->body(), HelpController::RENDER_OPTIONS),
            ];
        }

        return $entries;
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

    private function renderMaintenanceInProgress(UpdateHistory $history): Response
    {
        $html = $this->twig->render('maintenance/in_progress.html.twig', [
            'status_label' => self::STATUS_LABELS[$history->status] ?? ('Étape : ' . $history->status),
            'elapsed_label' => $this->elapsedLabel($history->startedAt),
        ]);

        // 503 (not 200): a search engine or uptime monitor hitting the
        // site mid-update must not index or treat this page as the real
        // one. Retry-After is a hint, not a promise — an update's total
        // duration depends on database size (MigrationRunner can span
        // several invocations on a large one) — but 60s is a reasonable
        // "check back soon" for the common case.
        return (new Response($html))
            ->setStatusCode(503)
            ->setHeader('Retry-After', '60');
    }

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'backing_up' => 'Sauvegarde de sécurité en cours…',
        'downloading' => 'Téléchargement de la mise à jour…',
        'installing' => 'Installation des fichiers…',
        'migrating' => 'Mise à jour de la base de données…',
    ];

    private function elapsedLabel(string $startedAt): string
    {
        try {
            // Naive DATETIME, same convention as Core\Security\
            // MagicLinkRepository etc. — compared directly against the
            // current time in PHP's own default timezone, no explicit UTC
            // conversion (MySQL's CURRENT_TIMESTAMP and PHP's `now` are
            // assumed to agree, as they already are everywhere else this
            // app diffs a DB timestamp).
            $started = new \DateTimeImmutable($startedAt);
        } catch (\Exception) {
            return '';
        }

        $elapsedSeconds = max(0, (new \DateTimeImmutable())->getTimestamp() - $started->getTimestamp());
        if ($elapsedSeconds < 60) {
            return 'En cours depuis moins d\'une minute.';
        }

        $minutes = intdiv($elapsedSeconds, 60);
        return 'En cours depuis ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '') . '.';
    }
}
