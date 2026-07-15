<?php

declare(strict_types=1);

namespace Tests\Core\Member\Controller;

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
 * RBAC boundary for /admin/members (Espace admin): requires `admin`
 * (Chef d'Unité). Allowed at admin, denied (403) one level below (chief),
 * redirect (302 /login) when unauthenticated.
 */
class MemberSearchRbacTest extends TestCase
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
        $router->addRoute('GET', '/admin/members', MembersStubController::class, 'index', 'admin');
        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(MembersStubController::class, new MembersStubController($this->twig));

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
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/members', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSuperAdminAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@test.com', 'superadmin');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/members', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testChiefDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'c@test.com', 'chief');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/members', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testIdentifiedDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'i@test.com', 'identified');
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/members', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->startTestSession();
        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/members', [], [], [], []));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }
}

class MembersStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }
}
