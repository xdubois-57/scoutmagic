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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * RBAC boundary for the scout-year routes: all require `chief`.
 * Allowed at chief, denied (403) one level below (intendant),
 * redirect (302 /login) when unauthenticated.
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
    ];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
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
        foreach (self::ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, ScoutYearStubController::class, 'index', 'chief');
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

    public function testChiefIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');
        $fc = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for chief");
        }
    }

    public function testIntendantIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'intendant@test.com', 'intendant');
        $fc = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for intendant");
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
