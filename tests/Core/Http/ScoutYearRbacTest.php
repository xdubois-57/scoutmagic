<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * RBAC boundary for the scout-year routes (Espace chefs d'U): all require `admin`
 * (displayed as "Chef d'Unité"). Allowed at admin, denied (403) one level below
 * (chief), redirect (302 /login) when unauthenticated.
 */
class ScoutYearRbacTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    /** @return array<int, array{string, string}> method + path */
    private const ROUTES = [
        ['GET', '/admin/scout-year'],
        ['POST', '/admin/scout-year/preview'],
        ['POST', '/admin/scout-year/clear-preview'],
        ['POST', '/admin/scout-year/activate-staff'],
        ['POST', '/admin/scout-year/deactivate-staff'],
        ['POST', '/admin/scout-year/activate-public'],
        ['POST', '/admin/scout-year/step'],
    ];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->twig = TestTwig::create();
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);

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
        foreach (self::ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, ScoutYearStubController::class, 'index', 'admin');
        }
        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(ScoutYearStubController::class, new ScoutYearStubController($this->twig));

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

    public function testAdminIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'unitchief@test.com', 'admin');
        $fc = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for admin (« Chef d'Unité »)");
        }
    }

    public function testChiefIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');
        $fc = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for chief");
        }
    }

    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->startTestSession();
        $fc = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(302, $response->getStatusCode(), "{$method} {$path} should redirect when unauthenticated");
            $this->assertSame('/login', $response->getHeaders()['Location']);
        }
    }

    /**
     * The JSON variant of each action is the same route, so the same
     * boundary: a script's request is refused one level below exactly
     * like a form's (CHANTIER-annee-scoute.md §2.8).
     */
    public function testTheJsonVariantKeepsTheSameBoundary(): void
    {
        $this->startTestSession();
        $json = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        AuthSession::login(1, 'unitchief@test.com', 'admin');
        $fc = $this->buildFrontController();
        foreach (self::ROUTES as [$method, $path]) {
            if ($method === 'POST') {
                $this->assertSame(200, $fc->handle(new Request($method, $path, [], [], [], $json))->getStatusCode(), "{$path} as JSON, admin");
            }
        }

        AuthSession::login(1, 'chief@test.com', 'chief');
        $fc = $this->buildFrontController();
        foreach (self::ROUTES as [$method, $path]) {
            if ($method === 'POST') {
                $response = $fc->handle(new Request($method, $path, [], [], [], $json));
                $this->assertSame(403, $response->getStatusCode(), "{$path} as JSON should be denied for chief");
                $this->assertStringNotContainsString('stub-ok', $response->getBody());
            }
        }
    }
}

class ScoutYearStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
