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

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/authz-support.php';

/**
 * The RBAC boundary of the Staffs page's writes: allowed at the floor
 * public/index.php declares (chief), refused one level below (intendant).
 *
 * The floor only. Which SECTION a chief may write is the controller's own
 * narrowing, pinned in StaffsControllerTest.
 */
final class StaffsRbacTest extends TestCase
{
    private const ROUTES = [
        ['POST', '/chefs/staffs/badge-toggle'],
        // A section's own text (#725).
        ['POST', '/chefs/staffs/text'],
        // A section totem (#722).
        ['POST', '/chefs/staffs/totem-de-section'],
    ];

    private Environment $twig;
    private AppConfig $config;

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

        $configFile = sys_get_temp_dir() . '/staffs_rbac_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testEveryRouteIsDeclaredAtTheChiefFloorInTheApplicationItself(): void
    {
        $declared = $this->declaredRoutes();

        foreach (self::ROUTES as [$method, $path]) {
            $key = $method . ' ' . $path;
            $this->assertArrayHasKey($key, $declared, "{$key} is not registered in public/index.php at all");
            $this->assertSame('chief', $declared[$key], "{$key} is not behind the chief floor");
        }
    }

    public function testAChiefIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');
        $frontController = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for chief");
        }
    }

    public function testAnIntendantIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'intendant@test.com', 'intendant');
        $frontController = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for intendant");
        }
    }

    /**
     * @return array<string, string> "METHOD /path" => role_min
     */
    private function declaredRoutes(): array
    {
        $declared = [];
        foreach (\authzCoreRoutes() as $route) {
            $declared[$route['method'] . ' ' . $route['path']] = $route['role_min'];
        }

        return $declared;
    }

    /**
     * Each route at the floor public/index.php declares for it — the
     * boundary under test is the one that ships.
     */
    private function buildFrontController(): FrontController
    {
        $declared = $this->declaredRoutes();
        $router = new Router();
        foreach (self::ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, StaffsStubController::class, 'index', $declared[$method . ' ' . $path] ?? 'superadmin');
        }
        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(StaffsStubController::class, new StaffsStubController($this->twig));

        return $frontController;
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }
}

final class StaffsStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
