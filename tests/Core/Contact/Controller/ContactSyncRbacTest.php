<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Controller;

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
 * The two role boundaries contact synchronisation adds.
 *
 * « Synchroniser mes contacts » sits under Mon compte, which every signed-in
 * member reaches — but its own routes are `admin`, because the address
 * book those credentials open is the staff's and
 * `Core\Contact\Device\DeviceAuthenticator` refuses anything below that
 * floor on every request anyway. A page offering an `identified` member a
 * device that would never be allowed to synchronise would be a broken
 * promise, and a page letting them register one would be a boundary
 * quietly a rung too low.
 *
 * The cut-out and the list of everybody's devices are `superadmin`, like
 * every other page of the Configuration menu.
 */
class ContactSyncRbacTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn(string $path): string => $path));
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
        // The same floors public/index.php declares.
        $router->addRoute('GET', '/account/devices', ContactSyncStubController::class, 'index', 'admin');
        $router->addRoute('POST', '/api/account/devices', ContactSyncStubController::class, 'index', 'admin');
        $router->addRoute('POST', '/account/devices/{id}/revoke', ContactSyncStubController::class, 'index', 'admin');
        $router->addRoute('GET', '/config/synchronisation-contacts', ContactSyncStubController::class, 'index', 'superadmin');
        $router->addRoute('POST', '/config/synchronisation-contacts/switch', ContactSyncStubController::class, 'index', 'superadmin');
        $router->addRoute('POST', '/config/synchronisation-contacts/{id}/revoke', ContactSyncStubController::class, 'index', 'superadmin');

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(ContactSyncStubController::class, new ContactSyncStubController($this->twig));

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

    private function statusFor(string $method, string $path, string $role): int
    {
        $this->startTestSession();
        AuthSession::login(1, $role . '@test.com', $role);

        return $this->buildFrontController()->handle(new Request($method, $path, [], [], [], []))->getStatusCode();
    }

    /**
     * @dataProvider deviceRoutes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('deviceRoutes')]
    public function testMyDevicesRequireAdminAndRefuseOneLevelBelow(string $method, string $path): void
    {
        $this->assertSame(200, $this->statusFor($method, $path, 'admin'));
        $this->assertSame(200, $this->statusFor($method, $path, 'superadmin'));
        $this->assertSame(403, $this->statusFor($method, $path, 'chief'));
        $this->assertSame(403, $this->statusFor($method, $path, 'identified'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deviceRoutes(): array
    {
        return [
            'list' => ['GET', '/account/devices'],
            'create' => ['POST', '/api/account/devices'],
            'revoke' => ['POST', '/account/devices/42/revoke'],
        ];
    }

    /**
     * @dataProvider configRoutes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configRoutes')]
    public function testTheCutOutRequiresSuperAdminAndRefusesOneLevelBelow(string $method, string $path): void
    {
        $this->assertSame(200, $this->statusFor($method, $path, 'superadmin'));
        $this->assertSame(403, $this->statusFor($method, $path, 'admin'));
        $this->assertSame(403, $this->statusFor($method, $path, 'chief'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function configRoutes(): array
    {
        return [
            'page' => ['GET', '/config/synchronisation-contacts'],
            'switch' => ['POST', '/config/synchronisation-contacts/switch'],
            'revoke' => ['POST', '/config/synchronisation-contacts/42/revoke'],
        ];
    }

    public function testAnAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->startTestSession();
        $response = $this->buildFrontController()->handle(new Request('GET', '/account/devices', [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }
}

class ContactSyncStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }
}
