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
 * RBAC boundary for /chefs/membres ("Membres par section"): requires
 * `intendant`. Allowed at intendant/chief/admin, denied one level below
 * (identified), redirect (302 /login) when unauthenticated.
 */
class SectionRosterRbacTest extends TestCase
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
        $router->addRoute('GET', '/chefs/membres', SectionRosterStubController::class, 'index', 'intendant');
        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(SectionRosterStubController::class, new SectionRosterStubController($this->twig));

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

    public function testIntendantAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'i@test.com', 'intendant');
        $response = $this->buildFrontController()->handle(new Request('GET', '/chefs/membres', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testChiefAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('GET', '/chefs/membres', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'admin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/chefs/membres', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIdentifiedDeniedOneLevelBelowIntendant(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'id@test.com', 'identified');
        $response = $this->buildFrontController()->handle(new Request('GET', '/chefs/membres', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->startTestSession();
        $response = $this->buildFrontController()->handle(new Request('GET', '/chefs/membres', [], [], [], []));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }
}

class SectionRosterStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }
}
