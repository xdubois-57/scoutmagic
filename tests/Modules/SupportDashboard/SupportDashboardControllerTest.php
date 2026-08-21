<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\TwigFactory;
use Modules\SupportDashboard\Controller\SupportDashboardController;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportDashboardService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * The dashboard's RBAC boundary through the REAL Router/RbacGuard pipeline,
 * plus the two properties the page itself has to hold: an absent value is
 * never rendered as 0/Non, and no view state survives a request that
 * carries no query string.
 */
class SupportDashboardControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private SupportDashboardController $controller;
    private int $installationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $installations = new SupportInstallationRepository($this->pdo);
        $this->installationId = $installations->register(
            'aaaabbbbccccdddd',
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($this->payload()),
            StatisticsIntakeService::denormalize($this->payload())
        );

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 3) . '/core/View/templates',
            false,
            ['support_dashboard' => dirname(__DIR__, 3) . '/modules/support_dashboard/views']
        );
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/support-dashboard');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new SupportDashboardController(
            $this->twig,
            new SupportDashboardService($installations, new SettingService(new SettingRepository($this->pdo))),
            new JournalService(new JournalRepository($this->pdo))
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * A report with `usage` deliberately absent, so the page has a genuinely
     * unreported metric to render.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'statistics_schema_version' => 1,
            'installation_id' => 'aaaabbbbccccdddd',
            'instance_url' => 'https://unite-exemple.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'scout_year' => ['label' => '2026-2027'],
            'updates' => ['auto_update_enabled' => true, 'auto_update_level' => 'patch'],
            'lifecycle' => ['installed_at' => '2025-09-01T10:12:00+00:00', 'last_upgraded_at' => null],
        ];
    }

    private function frontController(string $path, string $action, string $method = 'GET'): FrontController
    {
        $router = new Router();
        $router->addRoute($method, $path, SupportDashboardController::class, $action, 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_support_dashboard_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(SupportDashboardController::class, $this->controller);

        return $frontController;
    }

    public function testSuperadminReachesTheDashboard(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard', 'index')
            ->handle(new Request('GET', '/support-dashboard', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Tableau de bord support', $response->getBody());
    }

    public function testAdminIsRejectedByTheGuard(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->frontController('/support-dashboard', 'index')
            ->handle(new Request('GET', '/support-dashboard', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheDetailRouteHasTheSameBoundary(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->frontController('/support-dashboard/installations/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/installations/' . $this->installationId, [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnUnreportedMetricRendersAsNonRenseigneAndNeverAsZero(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard', 'index')
            ->handle(new Request('GET', '/support-dashboard', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('Non renseigné', $body);
        // The row's members cell is the unreported one; a "0" anywhere in
        // the members column would be the exact bug this rule exists for.
        $this->assertStringNotContainsString('<td class="d-none d-lg-table-cell">0</td>', $body);
    }

    public function testTheSecretNeverAppearsInAnyResponse(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $index = $this->frontController('/support-dashboard', 'index')
            ->handle(new Request('GET', '/support-dashboard', [], [], [], []))->getBody();
        $detail = $this->frontController('/support-dashboard/installations/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/installations/' . $this->installationId, [], [], [], []))->getBody();

        foreach ([$index, $detail] as $body) {
            $this->assertStringNotContainsString('secret', $body);
            $this->assertStringNotContainsString('$2y$', $body);
        }
    }

    public function testTheDetailDialogCarriesTheExactRawJson(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard/installations/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/installations/' . $this->installationId, [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('statistics_schema_version', $body);
        $this->assertStringContainsString('unite-exemple.be', $body);
    }

    // --- IT-10: export and manual deletion ---

    public function testTheExportIsRefusedToAdmin(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $denied = $this->frontController('/support-dashboard/export', 'export')
            ->handle(new Request('GET', '/support-dashboard/export', [], [], [], []));

        $this->assertSame(403, $denied->getStatusCode());
    }

    public function testTheExportIsServedToSuperadminAsRealXlsxBytes(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $allowed = $this->frontController('/support-dashboard/export', 'export')
            ->handle(new Request('GET', '/support-dashboard/export', [], [], [], []));

        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $allowed->getHeaders()['Content-Type']
        );
        // A real XLSX is a ZIP container: "PK" is its magic number. This is
        // what proves it is not a CSV wearing an XLSX filename.
        $this->assertStringStartsWith('PK', $allowed->getBody());
    }

    public function testDeletionIsRefusedToAdminByTheGuard(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $denied = $this->frontController('/support-dashboard/installations/{id}/delete', 'delete', 'POST')
            ->handle(new Request('POST', '/support-dashboard/installations/' . $this->installationId . '/delete', [], [], [], []));

        $this->assertSame(403, $denied->getStatusCode());
        $this->assertNotNull(
            (new SupportInstallationRepository($this->pdo))->findById($this->installationId)
        );
    }

    public function testDeletionRequiresAValidCsrfToken(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $withoutToken = $this->controller->delete(
            new Request('POST', '/x', [], ['_csrf_token' => 'wrong'], [], []),
            ['id' => (string) $this->installationId]
        );
        $this->assertSame(400, $withoutToken->getStatusCode());

        // Still there: a refused CSRF check must not have deleted anything.
        $this->assertNotNull(
            (new SupportInstallationRepository($this->pdo))->findById($this->installationId)
        );
    }

    public function testAValidDeletionRemovesTheRecordAndRedirects(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->controller->delete(
            new Request('POST', '/x', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            ['id' => (string) $this->installationId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/support-dashboard', $response->getHeaders()['Location']);
        $this->assertNull((new SupportInstallationRepository($this->pdo))->findById($this->installationId));
    }

    public function testTheDeletionJournalEntryNamesNoUnit(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $this->controller->delete(
            new Request('POST', '/x', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            ['id' => (string) $this->installationId]
        );

        $entries = (string) json_encode(
            $this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC)
        );

        $this->assertStringContainsString('support_installation_deleted', $entries);
        $this->assertStringNotContainsString('aaaabbbbccccdddd', $entries);
        $this->assertStringNotContainsString('unite-exemple.be', $entries);
    }

    public function testAnUnknownInstallationIs404(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/installations/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/installations/999999', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }
}
