<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature\Controller;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * « Ma signature » (issue #926, lot B) at its floor: every route opens at
 * `identified`, whatever the role above it, and to nobody below — an
 * anonymous visitor reaches none of them, the image included.
 *
 * The floors are read from `public/index.php` itself rather than copied,
 * so a route declared there at another level fails here.
 */
final class AccountSignatureRbacTest extends TestCase
{
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

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'the page' => ['GET', '/account/signature'],
            'saving' => ['POST', '/account/signature'],
            'deleting' => ['POST', '/account/signature/delete'],
            'the image' => ['GET', '/account/signature/image'],
        ];
    }

    /**
     * The role each of these routes declares in the composition root.
     */
    private static function declaredRole(string $method, string $path): ?string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        $pattern = '/addRoute\(\s*\'' . $method . '\',\s*\'' . preg_quote($path, '/')
            . '\',\s*\\\\Core\\\\Signature\\\\Controller\\\\AccountSignatureController::class,\s*\'\w+\',\s*\'(\w+)\'/';

        return preg_match($pattern, $source, $match) === 1 ? $match[1] : null;
    }

    private function statusFor(string $method, string $path, ?string $role): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];
        if ($role !== null) {
            AuthSession::login(1, $role . '@test.com', $role);
        }

        $router = new Router();
        $router->addRoute($method, $path, AccountSignatureStubController::class, 'index', (string) self::declaredRole($method, $path));
        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(AccountSignatureStubController::class, new AccountSignatureStubController($this->twig));

        return $frontController->handle(new Request($method, $path, [], [], [], []))->getStatusCode();
    }

    #[DataProvider('routes')]
    public function testEveryRouteIsDeclaredAtIdentified(string $method, string $path): void
    {
        $this->assertSame('identified', self::declaredRole($method, $path));
    }

    #[DataProvider('routes')]
    public function testAnIdentifiedAccountReachesItAndAnAnonymousVisitorDoesNot(string $method, string $path): void
    {
        $this->assertSame(200, $this->statusFor($method, $path, 'identified'));
        $this->assertSame(200, $this->statusFor($method, $path, 'superadmin'));
        $this->assertNotSame(200, $this->statusFor($method, $path, null));
    }
}

/**
 * Answers 200 to whatever reaches it: the guard in front is the subject.
 */
final class AccountSignatureStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('ok');
    }
}
