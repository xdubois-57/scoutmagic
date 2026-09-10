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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The role boundary of `POST /config/maintenance/backup/{id}/delete`:
 * allowed at `admin`, refused one level below.
 *
 * `admin` and not `superadmin`, deliberately: it is the same floor as
 * every other write in the « Sauvegardes » section — the person who can
 * create a backup and download the archive can also remove one. The
 * dangerous case here is not a role at all, it is deleting the safety net
 * of an operation that is running right now, and `Core\Maintenance\
 * BackupSafetyNet` refuses that whatever the caller's role
 * (`MaintenanceBackupDeleteRbacTest` covers the first half,
 * `BackupSafetyNetTest` the second).
 *
 * The guard is the Router's, never the controller's, so a stub is what
 * proves it.
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

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addExtension(new \Core\View\CompactHtmlExtension());
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnAdminIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->assertSame(200, $this->handle()->getStatusCode());
    }

    public function testASuperAdminIsAllowedToo(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'root@test.be', 'superadmin');

        $this->assertSame(200, $this->handle()->getStatusCode());
    }

    /**
     * One level below the floor. `chief` is a unit leader who reads the
     * same pages an admin does in several places — which is exactly why
     * the boundary has to be pinned rather than assumed: a backup is the
     * installation's only way back, and losing one is not a mistake a
     * chief should be able to make from a list of dates.
     */
    public function testAChiefIsRefused(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chef@test.be', 'chief');

        $response = $this->handle();

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('supprimée', $response->getBody());
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
            'admin'
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
