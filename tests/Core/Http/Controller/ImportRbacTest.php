<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * RBAC boundary for /admin/import ("Import Desk"), on both verbs:
 * requires `admin`. Allowed at admin and superadmin, denied one level
 * below (chief), redirect (302 /login) when unauthenticated.
 *
 * The POST matters as much as the GET here. A Desk import replaces the
 * whole roster and, with it, everybody's role — the barrier
 * (Core\Import\RosterReplacementGuard) is what stops a bad file, and this
 * is what stops a visitor who was never entitled to send one.
 */
class ImportRbacTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new \Twig\TwigFunction('editable', fn() => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('editable_image', fn() => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/import', ImportStubController::class, 'index', 'admin');
        $router->addRoute('POST', '/admin/import', ImportStubController::class, 'import', 'admin');
        $router->addRoute('GET', '/admin/import/historique', ImportStubController::class, 'index', 'admin');
        $router->addRoute('GET', '/admin/points-attention', ImportStubController::class, 'index', 'admin');
        $router->addRoute('GET', '/admin/doublons', ImportStubController::class, 'index', 'admin');
        $router->addRoute('POST', '/admin/doublons/{id}/fusionner', ImportStubController::class, 'import', 'admin');
        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(ImportStubController::class, new ImportStubController($this->twig));

        return $fc;
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    public function testAdminAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'admin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/import', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSuperAdminAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 's@test.com', 'superadmin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/import', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testChiefDeniedOneLevelBelowAdmin(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/import', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testChiefDeniedOnThePostToo(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('POST', '/admin/import', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminAllowedOnThePost(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'admin');
        $response = $this->buildFrontController()->handle(new Request('POST', '/admin/import', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The history lists every kept CSV of the season, each one a download
     * of the whole unit's personal data — the same floor as the import
     * itself, never one level below.
     */
    public function testTheHistoryDeniesChief(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/import/historique', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheHistoryAllowsAdmin(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'admin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/import/historique', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The attention page names members and states what a section is
     * missing — chefs d'unité only, like the import surface it sits
     * beside in the same menu.
     */
    public function testTheAttentionPageDeniesChief(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/points-attention', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheAttentionPageAllowsAdmin(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'admin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/points-attention', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Merging two member records rewrites who owns a whole history, files
     * and private documents included — chefs d'unité only, on the read as
     * on the write.
     */
    public function testTheDuplicatesPageDeniesChief(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/doublons', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheMergeIsDeniedToChief(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('POST', '/admin/doublons/1/fusionner', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheDuplicatesPageAllowsAdmin(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'admin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/doublons', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->startTestSession();
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/import', [], [], [], []));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }
}

class ImportStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function import(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }
}
