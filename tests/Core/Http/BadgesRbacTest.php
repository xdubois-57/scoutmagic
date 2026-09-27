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
use Tests\TestTwig;
use Twig\Environment;

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/authz-support.php';

/**
 * Who may reach the Badges pages of the Espace chefs d'U.
 *
 * They lived in the Configuration menu at `superadmin`; they moved to the
 * Espace chefs d'U at `admin` (issue #621) — a chief d'unité manages the
 * unit's badges. **The POSTs count as much as the GET**: a page opened to
 * the chief d'unité whose four writes stayed at `superadmin` would show
 * every control and refuse every one of them.
 *
 * `admin` exactly, not merely « at most superadmin »: one level lower, a
 * section chief would be deciding the unit's transversal roles — the
 * attribution of a badge is theirs, on /chefs/staffs, but not the list.
 */
final class BadgesRbacTest extends TestCase
{
    /** @var array<int, array{string, string}> */
    private const ROUTES = [
        // The holders page: read only, but it names who carries which role.
        ['GET', '/admin/badges'],
        ['GET', '/admin/badges/configuration'],
        ['POST', '/admin/badges/add'],
        ['POST', '/admin/badges/update'],
        ['POST', '/admin/badges/toggle-active'],
        ['POST', '/admin/badges/delete'],
        // The address before the move, which only redirects: same floor as
        // where it leads, so it never answers a visitor something the
        // destination would refuse.
        ['GET', '/config/badges'],
    ];

    /** The addresses the page used to write to: gone, not left behind. */
    private const REMOVED_ROUTES = [
        ['POST', '/config/badges/add'],
        ['POST', '/config/badges/update'],
        ['POST', '/config/badges/toggle-active'],
        ['POST', '/config/badges/delete'],
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

        $configFile = sys_get_temp_dir() . '/badges_rbac_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * **What `public/index.php` actually declares**, read from the file
     * rather than restated here: the behaviour cases below register their
     * own routes, so on their own they prove nothing about the
     * application's.
     */
    public function testEveryRouteIsDeclaredAtTheAdministratorFloorInTheApplicationItself(): void
    {
        $declared = $this->declaredRoutes();

        foreach (self::ROUTES as [$method, $path]) {
            $key = $method . ' ' . $path;
            $this->assertArrayHasKey($key, $declared, "{$key} is not registered in public/index.php at all");
            $this->assertSame('admin', $declared[$key], "{$key} is not behind the administrator floor");
        }
    }

    public function testTheOldWriteAddressesAreGone(): void
    {
        $declared = $this->declaredRoutes();

        foreach (self::REMOVED_ROUTES as [$method, $path]) {
            $this->assertArrayNotHasKey($method . ' ' . $path, $declared);
        }
    }

    public function testTheMenuEntryIsInTheEspaceChefsDuNoLongerInConfiguration(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');

        $this->assertMatchesRegularExpression(
            "~addPage\(\s*MenuBuilder::MENU_ESPACE_ADMIN,\s*'Badges',\s*'/admin/badges',\s*'admin',~",
            $source
        );
        $this->assertDoesNotMatchRegularExpression("~addPage\(\s*MenuBuilder::MENU_CONFIGURATION,\s*'Badges',~", $source);
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

    /** One level below the floor. */
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

    /** @return array<string, string> "METHOD path" => role_min */
    private function declaredRoutes(): array
    {
        $declared = [];
        foreach (\authzCoreRoutes() as $route) {
            $declared[$route['method'] . ' ' . $route['path']] = $route['role_min'];
        }

        return $declared;
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        foreach (self::ROUTES as [$method, $path]) {
            $router->addRoute($method, $path, BadgesStubController::class, 'index', 'admin');
        }
        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(BadgesStubController::class, new BadgesStubController($this->twig));

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

final class BadgesStubController extends AbstractController
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
