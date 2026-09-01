<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Config\AppConfig;
use Core\Database\ConstraintViolation;
use Core\Help\HelpException;
use Core\Help\HelpPageLinkResolver;
use Core\Help\HelpService;
use Core\Http\Controller\AbstractController;
use Core\Http\Controller\HelpController;
use Core\Maintenance\MaintenanceGate;
use Core\Maintenance\UpdateHistory;
use Core\Offline\OfflineWhitelist;
use Core\Security\AuthSession;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\Service\DateInput;
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
        private ?HelpService $helpService = null,
        // The « aller sur la page » link a panel topic carries
        // (Core\Help\HelpPageLinkResolver). Optional and trailing for the
        // same reason as $helpService above; null simply means the panel
        // renders as it did before the link existed.
        private ?HelpPageLinkResolver $helpPageLinks = null
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

        // The real ancestor PAGE(s) this route declares, resolved for the
        // role this visitor actually holds — a step they could not reach
        // disappears rather than becoming a link to a 403, exactly like a
        // menu entry (SECURITY.md §3). Set here, alongside the trail
        // above and after the guard for the same never-leak reason.
        // A controller may still pass its own `breadcrumb_trail` for an
        // ancestor that is genuinely dynamic — a booking under ITS asset
        // — and the partial renders the static steps ahead of it.
        $this->twig->addGlobal(
            'route_breadcrumb_ancestors',
            $this->router->ancestorTrailFor($resolvedRoute->breadcrumb, Role::fromString(AuthSession::getRole()))
        );

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

        try {
            /** @var Response $response */
            $response = $controller->$action($request, $resolvedRoute->params);
        } catch (\Throwable $e) {
            $verdict = ConstraintViolation::classify($e);
            if ($verdict === null) {
                throw $e;
            }

            return $this->renderConstraintViolation($verdict, $e, $request);
        }

        return $this->applyEtagIfEligible($request, $response);
    }

    /**
     * The floor underneath input validation: a write the schema refused
     * because of the values this request carried is answered as the
     * client error it is, not as "the application has crashed".
     *
     * This is caught here, at the one place every controller action is
     * invoked, rather than around each of the ~13 statements a dynamic
     * scan managed to reach. Not because per-site handling would be
     * wrong — where a boundary check can refuse the value with a French
     * sentence beside the field, it should, and Core\Service\DateInput
     * and Core\Service\IntegerInput exist so that it can — but because a
     * per-site net is only ever as complete as the last audit. The
     * remaining case is genuinely unpreventable at a boundary: between
     * checking that a row exists and inserting the row that references
     * it, somebody else can delete it.
     *
     * ConstraintViolation decides what counts, by driver code, and is
     * deliberately narrow: a NOT NULL column this application forgot to
     * populate shares its SQLSTATE with a foreign key a visitor got
     * wrong, and only the first is a bug that must keep shouting.
     * Anything it cannot place is rethrown and reaches ErrorHandler as a
     * 500, unchanged.
     *
     * Logged with error_log rather than the journal on purpose: the
     * journal is a table, and the one thing just established about this
     * request is that a write to that database did not go through.
     */
    private function renderConstraintViolation(string $verdict, \Throwable $e, Request $request): Response
    {
        error_log(
            'Constraint violation (' . $verdict . ') answered as '
            . ConstraintViolation::statusCode($verdict) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine()
        );

        $status = ConstraintViolation::statusCode($verdict);
        $message = ConstraintViolation::message($verdict);

        // A page gets the page; a fetch() gets JSON. Handing an HTML
        // document to caller that asked for JSON — the duty grid, the
        // gallery uploader — produces a parse error in the browser
        // instead of the sentence written above, which is the one part
        // of this the visitor was supposed to see.
        if ($this->expectsJson($request)) {
            return (new Response(json_encode(['success' => false, 'error' => $message]), $status))
                ->setHeader('Content-Type', 'application/json');
        }

        $html = $this->twig->render('errors/constraint.html.twig', [
            'constraint_message' => $message,
        ]);

        return (new Response($html))->setStatusCode($status);
    }

    /**
     * Whether this request came from a script rather than a navigation.
     * Both signals are the caller's own: the body it sent, and what it
     * said it would accept.
     */
    private function expectsJson(Request $request): bool
    {
        if (str_contains((string) $request->getServer('CONTENT_TYPE', ''), 'application/json')) {
            return true;
        }

        $accept = (string) $request->getServer('HTTP_ACCEPT', '');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
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
     * @return array<int, array{id: string, title: string, summary: string, html: string, page_link: ?array{path: string, label: string}}>
     */
    private function buildRouteHelp(Request $request): array
    {
        if ($this->helpService === null || $request->getMethod() !== 'GET') {
            return [];
        }

        $role = Role::fromString(AuthSession::getRole());

        // Core\Help\HelpRegistry already contains a topic it cannot parse
        // (it drops that one and carries on), so what is left to fail here
        // is the body: HelpTopic::body() re-reads the file, and a file that
        // parsed at the start of the request can be gone or truncated by
        // the time the panel is rendered — an update copying over the live
        // install is exactly that race. A help panel is never worth the
        // page it sits on, so the page wins.
        try {
            $entries = [];
            foreach ($this->helpService->findForPath($request->getPath(), $role) as $topic) {
                $entries[] = [
                    'id' => $topic->id,
                    'title' => $topic->title,
                    'summary' => $topic->summary,
                    'html' => MarkdownRenderer::toHtml($topic->body(), HelpController::RENDER_OPTIONS),
                    // Almost always null here, and that is the point: the
                    // panel usually opens ON the page the topic documents,
                    // and HelpPageLinkResolver drops the link to the page
                    // you are already reading. It surfaces on the pages a
                    // topic covers as a neighbour rather than as its
                    // subject.
                    'page_link' => $this->helpPageLinks?->resolve($topic, $role, $request->getPath()),
                ];
            }
        } catch (HelpException $e) {
            error_log('ScoutMagic contextual help unavailable for ' . $request->getPath() . ': ' . $e->getMessage());

            return [];
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
        // mod_deflate (DeflateAlterETag AddSuffix, the default) rewrites
        // the ETag it compressed to `…-gzip"`, and the browser replays
        // exactly that — HTML is deflated, so a strict comparison would
        // never revalidate.
        if (is_string($ifNoneMatch)) {
            $ifNoneMatch = str_replace('-gzip"', '"', $ifNoneMatch);
        }
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
        // Naive DATETIME, same convention as Core\Security\
        // MagicLinkRepository etc. — compared directly against the
        // current time in PHP's own default timezone, no explicit UTC
        // conversion (MySQL's CURRENT_TIMESTAMP and PHP's `now` are
        // assumed to agree, as they already are everywhere else this
        // app diffs a DB timestamp).
        $started = DateInput::fromStorage($startedAt);
        if ($started === null) {
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
