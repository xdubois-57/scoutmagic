<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Maintenance\MaintenanceGate;
use Core\Maintenance\UpdateHistory;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerContinuationRoute;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class FrontControllerTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);

        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test-csrf-token';
        }));
        $this->twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $this->twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAdminRouteReturns403ForIdentifiedUser(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');

        $router = new Router();
        $router->addRoute('GET', '/admin/test', StubController::class, 'index', 'admin');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/admin/test', [], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminRouteRedirectsToLoginForUnauthenticated(): void
    {
        $this->startTestSession();
        // Not logged in

        $router = new Router();
        $router->addRoute('GET', '/admin/test', StubController::class, 'index', 'admin');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/admin/test', [], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testPublicRouteAccessibleWithoutAuth(): void
    {
        $this->startTestSession();

        $router = new Router();
        $router->addRoute('GET', '/public-page', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/public-page', [], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stub-ok', $response->getBody());
    }

    public function testSetupRoutesBypassRbacWhenBypassSet(): void
    {
        $this->startTestSession();
        // Not logged in, admin route

        $router = new Router();
        $router->addRoute('GET', '/setup', StubController::class, 'index', 'admin');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));
        $fc->setRbacBypassPrefix('/setup');

        $request = new Request('GET', '/setup', [], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stub-ok', $response->getBody());
    }

    public function testSetupRoutesEnforceRbacWithoutBypass(): void
    {
        $this->startTestSession();
        // Not logged in, no bypass

        $router = new Router();
        $router->addRoute('GET', '/setup', StubController::class, 'index', 'admin');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/setup', [], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $router = new Router();
        $fc = new FrontController($router, $this->twig, $this->config);

        $request = new Request('GET', '/nonexistent', [], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testBreadcrumbGlobalSetForAllowedRouteWithBreadcrumb(): void
    {
        $this->startTestSession();

        $router = new Router();
        $router->addRoute('GET', '/public-page', StubController::class, 'index', 'public', [
            'label' => 'Staffs',
            'parents' => ['Espace animateurs'],
        ]);

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/public-page', [], [], [], []);
        $response = $fc->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        $html = $this->twig->createTemplate(
            '{{ route_breadcrumb.label }}|{{ route_breadcrumb.parents|join(",") }}'
        )->render();
        $this->assertSame('Staffs|Espace animateurs', $html);
    }

    public function testBreadcrumbGlobalIsNullForRouteWithoutBreadcrumb(): void
    {
        $this->startTestSession();

        $router = new Router();
        $router->addRoute('GET', '/public-page', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/public-page', [], [], [], []);
        $fc->handle($request);

        $html = $this->twig->createTemplate('{{ route_breadcrumb is null ? "null" : "set" }}')->render();
        $this->assertSame('null', $html);
    }

    /**
     * The route's declared ancestor pages reach the template already
     * resolved for the visitor's own role — the partial renders what it
     * is handed and makes no decision of its own.
     */
    public function testAncestorGlobalCarriesTheDeclaredAncestorPage(): void
    {
        $this->startTestSession();

        $router = new Router();
        $router->addRoute('GET', '/news', StubController::class, 'index', 'public', ['label' => 'Actualités', 'parents' => []]);
        $router->addRoute('GET', '/news/{id}', StubController::class, 'index', 'public', [
            'label' => 'Actualité',
            'parents' => ['Notre unité'],
            'ancestors' => [['label' => 'Actualités', 'path' => '/news']],
        ]);

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/news/12', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());

        $html = $this->twig->createTemplate(
            '{% for c in route_breadcrumb_ancestors %}{{ c.label }}={{ c.url }}{% endfor %}'
        )->render();
        $this->assertSame('Actualités=/news', $html);
    }

    /**
     * Visibility is a convenience, never a boundary (SECURITY.md §3): a
     * visitor below the ancestor's own floor loses the STEP, not the
     * page. A link to a 403 would be worse than no link at all.
     */
    public function testAncestorTheVisitorCannotReachIsDroppedBeforeTheTemplateSeesIt(): void
    {
        $this->startTestSession();

        $router = new Router();
        $router->addRoute('GET', '/news/manage', StubController::class, 'index', 'chief', ['label' => 'Gestion', 'parents' => []]);
        $router->addRoute('GET', '/news/{id}', StubController::class, 'index', 'public', [
            'label' => 'Actualité',
            'parents' => [],
            'ancestors' => [['label' => 'Gestion', 'path' => '/news/manage']],
        ]);

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $fc->handle(new Request('GET', '/news/12', [], [], [], []));

        $html = $this->twig->createTemplate('{{ route_breadcrumb_ancestors|length }}')->render();
        $this->assertSame('0', $html);
    }

    public function testAncestorGlobalIsAnEmptyListForARouteDeclaringNone(): void
    {
        $this->startTestSession();

        $router = new Router();
        $router->addRoute('GET', '/public-page', StubController::class, 'index', 'public', ['label' => 'Page', 'parents' => []]);

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $fc->handle(new Request('GET', '/public-page', [], [], [], []));

        $html = $this->twig->createTemplate('{{ route_breadcrumb_ancestors|length }}')->render();
        $this->assertSame('0', $html);
    }

    public function testBreadcrumbGlobalNeverSetWhenRbacDenies(): void
    {
        $this->startTestSession();
        // Not logged in, admin route with a breadcrumb declared

        $router = new Router();
        $router->addRoute('GET', '/admin/test', StubController::class, 'index', 'admin', [
            'label' => 'Admin secret',
            'parents' => [],
        ]);

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/admin/test', [], [], [], []);
        $response = $fc->handle($request);
        $this->assertSame(302, $response->getStatusCode());

        // A 403/redirect must never leak the breadcrumb of a page the
        // visitor couldn't reach.
        $html = $this->twig->createTemplate('{{ route_breadcrumb is null ? "null" : "set" }}')->render();
        $this->assertSame('null', $html);
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    // --- ETag on whitelisted pages (Part 3.3) ---

    public function testEtagHeaderIsSetOnAWhitelistedPath(): void
    {
        $router = new Router();
        // '/' is a core OfflineWhitelist entry, role_min public.
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, new \Core\Offline\OfflineWhitelist());
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('ETag', $response->getHeaders());
    }

    public function testIfNoneMatchReturns304WhenEtagMatches(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, new \Core\Offline\OfflineWhitelist());
        $fc->registerController(StubController::class, new StubController($this->twig));

        $first = $fc->handle(new Request('GET', '/', [], [], [], []));
        $etag = $first->getHeaders()['ETag'];

        $second = $fc->handle(new Request('GET', '/', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag]));

        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame('', $second->getBody());
        $this->assertSame($etag, $second->getHeaders()['ETag']);
    }

    public function testNoEtagOnANonWhitelistedPath(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/test', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, new \Core\Offline\OfflineWhitelist());
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/admin/test', [], [], [], []));

        $this->assertArrayNotHasKey('ETag', $response->getHeaders());
    }

    public function testNoEtagOnAPostRequestEvenToAWhitelistedPath(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, new \Core\Offline\OfflineWhitelist());
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('POST', '/', [], [], [], []));

        $this->assertArrayNotHasKey('ETag', $response->getHeaders());
    }

    public function testNoEtagInConfigurationMode(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');
        \Core\View\ConfigurationMode::activate('admin');

        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, new \Core\Offline\OfflineWhitelist());
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertArrayNotHasKey('ETag', $response->getHeaders());

        \Core\View\ConfigurationMode::deactivate();
    }

    public function testNoEtagWhenOfflineWhitelistIsNotProvided(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        // Backward-compatible default — most FrontController call sites
        // in this test suite never pass a 4th argument at all.
        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('ETag', $response->getHeaders());
    }

    // --- Maintenance gate (update in progress) ---

    private function inProgressHistory(): UpdateHistory
    {
        return new UpdateHistory(
            id: 1,
            versionFrom: '1.0.0',
            versionTo: 'dev-abc1234',
            status: 'installing',
            dependenciesChanged: false,
            errorMessage: null,
            backupId: null,
            requestedBy: null,
            startedAt: date('Y-m-d H:i:s'),
            completedAt: null
        );
    }

    private function gateReturning(?UpdateHistory $history): MaintenanceGate
    {
        $repository = $this->createMock(UpdateHistoryRepository::class);
        $repository->method('findInProgress')->willReturn($history);
        return new MaintenanceGate($repository);
    }

    public function testServesTheNormalSiteWhenNoUpdateIsInProgress(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, null, $this->gateReturning(null));
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testServesTheMaintenancePageInsteadOfTheNormalSiteDuringAnUpdate(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, null, $this->gateReturning($this->inProgressHistory()));
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('Mise à jour en cours', $response->getBody());
        $this->assertSame('60', $response->getHeaders()['Retry-After']);
    }

    public function testMaintenancePageIsServedEvenForAnOtherwiseUnknownRoute(): void
    {
        // Deliberately checked before route resolution — an update in
        // progress must gate everything, not just routes that exist.
        $router = new Router();

        $fc = new FrontController($router, $this->twig, $this->config, null, $this->gateReturning($this->inProgressHistory()));

        $response = $fc->handle(new Request('GET', '/this-route-does-not-exist', [], [], [], []));

        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * The gate covers every path except one, and the exception has to hold
     * end to end: FrontController is what hands the path over, and a
     * FrontController that forgot to would reinstate the whole defect
     * while MaintenanceGateTest stayed green.
     */
    public function testTheSchedulerContinuationRouteIsReachableDuringAnUpdate(): void
    {
        $router = new Router();
        $router->addRoute('POST', SchedulerContinuationRoute::PATH, StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, null, $this->gateReturning($this->inProgressHistory()));
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('POST', SchedulerContinuationRoute::PATH, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheBypassQueryParamServesTheNormalSiteDuringAnUpdate(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, null, $this->gateReturning($this->inProgressHistory()));
        $fc->registerController(StubController::class, new StubController($this->twig));

        $request = new Request('GET', '/', [MaintenanceGate::BYPASS_QUERY_PARAM => '1'], [], [], []);
        $response = $fc->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnAlreadyLoggedInSuperadminSeesTheNormalSiteDuringAnUpdate(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'root@test.com', 'superadmin');

        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config, null, $this->gateReturning($this->inProgressHistory()));
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoMaintenanceGateMeansNeverBlocking(): void
    {
        // Backward-compatible default — most FrontController call sites in
        // this test suite never pass a 5th argument at all.
        $router = new Router();
        $router->addRoute('GET', '/', StubController::class, 'index', 'public');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(StubController::class, new StubController($this->twig));

        $response = $fc->handle(new Request('GET', '/', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}

class StubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
