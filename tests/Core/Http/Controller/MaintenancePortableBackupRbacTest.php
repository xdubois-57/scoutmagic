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

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 4) . '/scripts/authz-support.php';

/**
 * The role boundary of `POST /config/maintenance/backup/portable`: allowed
 * at `superadmin`, refused one level below.
 *
 * **The floor of every maintenance route, no higher.** This is the one
 * endpoint that packages `storage/keys/master.key` into a downloadable
 * file. It was `admin` like its neighbours, on the reasoning that raising
 * it alone would buy nothing and suggest the others were safe; issue #619
 * raised them all together, so it follows them rather than standing above
 * them (Tests\Core\Http\MaintenanceRbacTest holds the whole set).
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
     * One level below the floor, and the level that matters most here.
     *
     * An `admin` is a chef d'unité, who never sees the Configuration menu
     * but used to reach this route by its address. Reaching it would hand
     * them an archive containing the site's master key — every member's
     * address, date of birth and telephone number, readable on any
     * machine.
     */
    public function testAnAdminIsRefused(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->handle();

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('demandée', $response->getBody());
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

    /**
     * **The half a stub cannot prove: what `public/index.php` actually
     * registers.**
     *
     * The cases above build their own Router, so they prove that the
     * Router enforces a floor of `superadmin` — and they would go on passing if
     * this route were registered as `public`, as `chief`, or not at all.
     * That gap is real, and it is closed here rather than argued away:
     * `authzCoreRoutes()` parses the production registrations (the same
     * reading `scripts/authz-support.php` gives the authorization matrix,
     * which replays every route as every role in CI), so this asserts the
     * floor where it is really written.
     *
     * Reading rather than booting, because booting `public/index.php`
     * inside a test would run an entire application — connections,
     * migrations, session — for one question about one line.
     */
    public function testTheProductionRegistrationIsTheFloorTheseTestsAssume(): void
    {
        $matching = array_values(array_filter(
            \authzCoreRoutes(),
            static fn (array $route): bool => $route['path'] === self::PATH && $route['method'] === 'POST'
        ));

        $this->assertCount(
            1,
            $matching,
            'public/index.php does not register POST ' . self::PATH . ' — the tests above prove a floor on a '
            . 'route nobody can reach.'
        );
        $this->assertSame(
            'superadmin',
            $matching[0]['role_min'],
            'The production route no longer carries the floor these tests assert.'
        );
    }

    private function handle(): Response
    {
        $router = new Router();
        $router->addRoute(
            'POST',
            self::PATH,
            MaintenancePortableStubController::class,
            'createPortableBackup',
            'superadmin'
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
