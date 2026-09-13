<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/authz-support.php';

/**
 * Who may reach the off-site destination's routes.
 *
 * **The callback is the one worth spelling out.** It is the only route
 * here that a browser arrives at from somewhere else — Google sends it —
 * and it is also the one that writes a refresh token. Left open, it would
 * be a route where anybody able to compose a URL decides which Google
 * account this site backs up to, and where a stranger's account could be
 * grafted onto a unit's site without anyone logging in. The `state`
 * parameter checked against the session sits on top of the role floor; it
 * does not replace it, and the second half of this class is what stops a
 * future change from believing that it does.
 */
final class RemoteBackupRbacTest extends TestCase
{
    /** @var array<int, array{string, string}> */
    private const ROUTES = [
        ['POST', '/config/maintenance/remote/credentials'],
        ['GET', '/config/maintenance/remote/connect'],
        ['GET', '/config/maintenance/remote/callback'],
        ['POST', '/config/maintenance/remote/test'],
        ['POST', '/config/maintenance/remote/disconnect'],
    ];

    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );
        // The globals and functions `base.html.twig` reaches for, exactly
        // as `ScoutYearRbacTest` registers them: the 403 page is a real
        // render, so the layout has to have what it asks for.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn () => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn () => 'test'));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn () => null));
        $this->twig->addFunction(new \Twig\TwigFunction('editable', fn () => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('editable_image', fn () => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', fn () => ''));
        $this->twig->addFunction(new \Twig\TwigFunction(
            'person_avatar',
            fn (string $name, array $options = []): string
                => \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40)),
            ['is_safe' => ['html']]
        ));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);

        $configFile = sys_get_temp_dir() . '/remote_rbac_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * **What `public/index.php` actually declares**, read from the file
     * rather than restated here.
     *
     * The behaviour cases below register their own routes, so on their own
     * they would prove that a route declared `admin` behaves like one —
     * which is true of any role somebody chose to type into the test. This
     * is the case that fails when the declaration in the application
     * changes.
     */
    public function testEveryRouteIsDeclaredAtTheAdministratorFloorInTheApplicationItself(): void
    {
        $declared = [];
        foreach (\authzCoreRoutes() as $route) {
            $declared[$route['method'] . ' ' . $route['path']] = $route['role_min'];
        }

        foreach (self::ROUTES as [$method, $path]) {
            $key = $method . ' ' . $path;
            $this->assertArrayHasKey($key, $declared, "{$key} is not registered in public/index.php at all");
            $this->assertSame('admin', $declared[$key], "{$key} is not behind the administrator floor");
        }
    }

    public function testAnAdministratorIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'unitchief@test.com', 'admin');
        $frontController = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for admin");
        }
    }

    /** One level below the floor, including on the callback. */
    public function testAChiefIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');
        $frontController = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for chief");
        }
    }

    public function testAVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->startTestSession();
        $frontController = $this->buildFrontController();

        foreach (self::ROUTES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(302, $response->getStatusCode(), "{$method} {$path} should redirect when unauthenticated");
            $this->assertSame('/login', $response->getHeaders()['Location']);
        }
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        foreach (self::ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, RemoteBackupStubController::class, 'index', 'admin');
        }
        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(
            RemoteBackupStubController::class,
            new RemoteBackupStubController($this->twig)
        );

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

final class RemoteBackupStubController extends AbstractController
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
