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
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);

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

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
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
