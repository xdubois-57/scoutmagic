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
 * Who may reach Maintenance: a super-administrator, and nobody below.
 *
 * The Configuration menu has always had that floor, but sixteen of its
 * maintenance routes declared `admin` — a chef d'unité who never saw the
 * entry reached backups, the off-site phrase and the update installer by
 * typing their address. Issue #619 moved every one of them to
 * `superadmin`, **the POSTs and the /api/ routes as much as the pages**:
 * it is the verb that acts, and a hardened page whose form stays at
 * `admin` protects nothing.
 *
 * The six sub-pages are listed on their own, as the chantier asks: each
 * is a page somebody can bookmark, and each is covered by name.
 */
final class MaintenanceRbacTest extends TestCase
{
    /** @var array<int, array{string, string}> */
    private const PAGES = [
        ['GET', '/config/maintenance'],
        ['GET', '/config/maintenance/mise-a-jour'],
        ['GET', '/config/maintenance/sauvegarde-manuelle'],
        ['GET', '/config/maintenance/sauvegarde-automatique'],
        ['GET', '/config/maintenance/sauvegardes-recentes'],
        ['GET', '/config/maintenance/reinitialisation'],
    ];

    /**
     * The two routes that hand a password out (issue #619, IT-03): each
     * behind the same floor, and each denied one level below it.
     *
     * @var array<int, array{string, string}>
     */
    private const PASSWORD_REVEALS = [
        ['POST', '/config/maintenance/backup/7/password'],
        ['POST', '/config/maintenance/reset/full/password'],
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

        $configFile = sys_get_temp_dir() . '/maintenance_rbac_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * **What `public/index.php` actually declares**, read from the file:
     * every route under /config/maintenance and /api/maintenance, not a
     * list restated here that a new route would slip past.
     */
    public function testEveryMaintenanceRouteIsDeclaredAtTheSuperAdministratorFloor(): void
    {
        $found = 0;
        foreach (\authzCoreRoutes() as $route) {
            if (preg_match('#^/(config|api)/maintenance(/|$)#', $route['path']) !== 1) {
                continue;
            }
            $found++;
            $this->assertSame(
                'superadmin',
                $route['role_min'],
                "{$route['method']} {$route['path']} is not behind the super-administrator floor"
            );
        }
        // 21 routes before the split, plus five sub-pages.
        $this->assertGreaterThanOrEqual(26, $found, 'The maintenance routes were not found at all.');
    }

    public function testEachOfTheSixSubPagesIsRegistered(): void
    {
        $declared = [];
        foreach (\authzCoreRoutes() as $route) {
            $declared[$route['method'] . ' ' . $route['path']] = $route['role_min'];
        }

        foreach (self::PAGES as [$method, $path]) {
            $this->assertSame('superadmin', $declared[$method . ' ' . $path] ?? null, "{$method} {$path}");
        }
    }

    public function testTheMenuEntryHasTheSameFloor(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');

        $this->assertMatchesRegularExpression(
            "~addPage\(\s*MenuBuilder::MENU_CONFIGURATION,\s*'Maintenance',\s*'/config/maintenance',\s*'superadmin',~",
            $source
        );
    }

    public function testASuperAdministratorReachesEverySubPage(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'root@test.com', 'superadmin');
        $frontController = $this->buildFrontController();

        foreach (self::PAGES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for superadmin");
        }
    }

    /** One level below the floor: the chef d'unité who used to get in. */
    public function testAnAdministratorIsDeniedEverySubPage(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'unitchief@test.com', 'admin');
        $frontController = $this->buildFrontController();

        foreach (self::PAGES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for admin");
        }
    }

    public function testAPasswordIsRevealedToASuperAdministratorAndToNobodyBelow(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'root@test.com', 'superadmin');
        foreach (self::PASSWORD_REVEALS as [$method, $path]) {
            $response = $this->buildFrontController()->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path} should be allowed for superadmin");
        }

        AuthSession::login(2, 'unitchief@test.com', 'admin');
        foreach (self::PASSWORD_REVEALS as [$method, $path]) {
            $response = $this->buildFrontController()->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should be denied for admin");
        }
    }

    public function testAVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->startTestSession();
        $frontController = $this->buildFrontController();

        foreach (self::PAGES as [$method, $path]) {
            $response = $frontController->handle(new Request($method, $path, [], [], [], []));
            $this->assertSame(302, $response->getStatusCode(), "{$method} {$path} should redirect when unauthenticated");
            $this->assertSame('/login', $response->getHeaders()['Location']);
        }
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        foreach (self::PAGES as [$method, $path]) {
            $router->addRoute($method, $path, MaintenanceRbacStubController::class, 'index', 'superadmin');
        }
        foreach (['/config/maintenance/backup/{id}/password', '/config/maintenance/reset/full/password'] as $path) {
            $router->addRoute('POST', $path, MaintenanceRbacStubController::class, 'index', 'superadmin');
        }
        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(MaintenanceRbacStubController::class, new MaintenanceRbacStubController($this->twig));

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

final class MaintenanceRbacStubController extends AbstractController
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
