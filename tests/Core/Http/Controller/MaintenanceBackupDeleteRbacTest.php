<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
use Tests\TestTwig;
use Twig\Environment;

/**
 * The role boundary of `POST /config/maintenance/backup/{id}/delete`:
 * allowed at `superadmin`, refused one level below.
 *
 * The floor of every maintenance route since issue #619, and the same as
 * every other write in the « Sauvegardes » section — the person who can
 * create a backup and download the archive can also remove one. The
 * dangerous case here is not a role at all, it is deleting the safety net
 * of an operation that is running right now, and `Core\Maintenance\
 * BackupSafetyNet` refuses that whatever the caller's role
 * (`MaintenanceBackupDeleteRbacTest` covers the first half,
 * `BackupSafetyNetTest` the second).
 *
 * The guard is the Router's, never the controller's, so a stub is what
 * proves it; the floor production declares is Tests\Core\Http\
 * MaintenanceRbacTest's to read.
 */
final class MaintenanceBackupDeleteRbacTest extends TestCase
{
    private const PATH = '/config/maintenance/backup/1/delete';

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

    public function testASuperAdminIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'root@test.be', 'superadmin');

        $this->assertSame(200, $this->handle()->getStatusCode());
    }

    /**
     * One level below the floor. An `admin` is a chef d'unité, who used to
     * reach this route by its address without ever seeing the menu that
     * leads to it — which is exactly why the boundary has to be pinned
     * rather than assumed: a backup is the installation's only way back,
     * and losing one is not a mistake to make from a list of dates.
     */
    public function testAnAdminIsRefused(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->handle();

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('supprimée', $response->getBody());
    }

    public function testAChiefIsRefused(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chef@test.be', 'chief');

        $this->assertNotSame(200, $this->handle()->getStatusCode());
    }

    public function testAVisitorWithNoAccountIsRefused(): void
    {
        $this->startTestSession();

        $response = $this->handle();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? null);
    }

    private function handle(): Response
    {
        $router = new Router();
        $router->addRoute(
            'POST',
            '/config/maintenance/backup/{id}/delete',
            MaintenanceDeleteStubController::class,
            'deleteBackup',
            'superadmin'
        );

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(
            MaintenanceDeleteStubController::class,
            new MaintenanceDeleteStubController($this->twig)
        );

        return $frontController->handle(new Request('POST', self::PATH, [], [], [], []));
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

class MaintenanceDeleteStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function deleteBackup(Request $request, array $params): Response
    {
        return new Response('supprimée', 200);
    }
}
