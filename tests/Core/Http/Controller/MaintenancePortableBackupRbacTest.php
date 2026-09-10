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
 * The role boundary of `POST /config/maintenance/backup/portable`: allowed
 * at `admin`, refused one level below.
 *
 * **`admin`, and the reasoning is worth writing down because the instinct
 * says `superadmin`.** This is the one endpoint that packages
 * `storage/keys/master.key` into a downloadable file, so it looks like it
 * deserves the higher floor. It does not: the role that can reach it can
 * already take a full backup, download it, read every member's file, and
 * reset the site. Raising this one alone would buy nothing and would
 * suggest the others are safe.
 *
 * What actually guards this route is what it produces — a passphrase long
 * enough to be the only lock on that key
 * (`Core\Maintenance\Portable\PortablePassphrase`), a second AES-256-GCM
 * envelope on the secrets, a retention of one, an alert when the archive
 * lingers, and a `security` journal entry naming what was asked for.
 *
 * The guard under test is the Router's, never the controller's, so a stub
 * is what proves it.
 */
final class MaintenancePortableBackupRbacTest extends TestCase
{
    private const PATH = '/config/maintenance/backup/portable';

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
     * One level below the floor, and the level that matters most here.
     *
     * A `chief` is a unit leader who legitimately reads many of the same
     * pages an admin does. Reaching this route would hand them an archive
     * containing the site's master key — every member's address, date of
     * birth and telephone number, readable on any machine — from a page
     * they can otherwise mostly see.
     */
    public function testAChiefIsRefused(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chef@test.be', 'chief');

        $response = $this->handle();

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('demandée', $response->getBody());
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
            self::PATH,
            MaintenancePortableStubController::class,
            'createPortableBackup',
            'admin'
        );

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(
            MaintenancePortableStubController::class,
            new MaintenancePortableStubController($this->twig)
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

class MaintenancePortableStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function createPortableBackup(Request $request, array $params): Response
    {
        return new Response('demandée', 200);
    }
}
