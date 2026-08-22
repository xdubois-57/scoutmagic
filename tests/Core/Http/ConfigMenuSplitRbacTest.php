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
 * RBAC boundaries introduced by the "Configuration générale" split: the
 * Modules/Badges pages stay superadmin-only (unchanged), while the
 * remaining configuration-mode toggle page (still /config/general, moved
 * to "Espace chefs d'U" in the menu) and the /config-mode/* routes it
 * drives are deliberately widened from superadmin to admin (Chef d'Unité)
 * — the one real access-scope change in this lot.
 */
class ConfigMenuSplitRbacTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    /** @return array<int, array{string, string}> method + path */
    private const SUPERADMIN_ROUTES = [
        ['GET', '/config/modules'],
        ['POST', '/config/modules/toggle'],
        ['POST', '/config/modules/reorder'],
        ['GET', '/config/badges'],
        ['POST', '/config/badges/add'],
        ['POST', '/config/badges/update'],
        ['POST', '/config/badges/toggle-active'],
        ['POST', '/config/badges/delete'],
    ];

    /** @return array<int, array{string, string}> method + path */
    private const ADMIN_ROUTES = [
        ['GET', '/config/general'],
        ['POST', '/config-mode/activate'],
        ['POST', '/config-mode/deactivate'],
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
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $this->twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));
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
        foreach (self::SUPERADMIN_ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, ConfigSplitStubController::class, 'index', 'superadmin');
        }
        foreach (self::ADMIN_ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, ConfigSplitStubController::class, 'index', 'admin');
        }
        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(ConfigSplitStubController::class, new ConfigSplitStubController($this->twig));

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

    public function testModulesAndBadgesRoutesAllowedForSuperadmin(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'super@test.com', 'superadmin');
        $fc = $this->buildFrontController();

        foreach (self::SUPERADMIN_ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for superadmin");
        }
    }

    public function testModulesAndBadgesRoutesDeniedForAdmin(): void
    {
        // The whole point of the split: Modules/Badges stay superadmin-only
        // even though the Configuration menu's other former resident
        // (the config-mode toggle) widened to admin — no accidental
        // widening of the module registry or badges themselves.
        $this->startTestSession();
        AuthSession::login(1, 'chief-unite@test.com', 'admin');
        $fc = $this->buildFrontController();

        foreach (self::SUPERADMIN_ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for admin (Chef d'Unité)");
        }
    }

    public function testConfigModeRoutesAllowedForAdmin(): void
    {
        // The deliberate, analyzed widening: any chief d'unité (admin) can
        // now reach the configuration-mode toggle page and its two routes,
        // not just a superadmin.
        $this->startTestSession();
        AuthSession::login(1, 'chief-unite@test.com', 'admin');
        $fc = $this->buildFrontController();

        foreach (self::ADMIN_ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for admin (Chef d'Unité)");
        }
    }

    public function testConfigModeRoutesDeniedForChief(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');
        $fc = $this->buildFrontController();

        foreach (self::ADMIN_ROUTES as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for chief, one level below admin");
        }
    }

    public function testAllRoutesRedirectToLoginWhenUnauthenticated(): void
    {
        $this->startTestSession();
        $fc = $this->buildFrontController();

        foreach ([...self::SUPERADMIN_ROUTES, ...self::ADMIN_ROUTES] as [$method, $path]) {
            $response = $fc->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(302, $response->getStatusCode(), "{$method} {$path} should redirect when unauthenticated");
            $this->assertSame('/login', $response->getHeaders()['Location']);
        }
    }
}

class ConfigSplitStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
