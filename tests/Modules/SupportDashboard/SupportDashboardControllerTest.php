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
    private SupportInstallationRepository $installations;
    private Environment $twig;
    private SupportDashboardController $controller;
    private int $installationId;

    /**
     * Distinctive on purpose: the page legitimately contains the French
     * word "secret" (the deletion confirmation mentions l'empreinte du
     * secret), so asserting on that word would test the prose rather than
     * the credential.
     */
    private const SENDER_SECRET = 'f4d1c0ffee5eca11ab1e0ddba11deadbeef00042';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $installations = $this->installations = new SupportInstallationRepository($this->pdo, $this->encryption);
        $this->installationId = $installations->register(
            'aaaabbbbccccdddd',
            password_hash(self::SENDER_SECRET, PASSWORD_DEFAULT),
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
        // « Supervision » is the menu entry — it was « Support », the same
        // label the unit's own /config/support page carries, so a receiver
        // showed two identical lines under Configuration. « Tableau de
        // bord » is this screen inside it, the way finance names its own.
        $this->assertStringContainsString('Tableau de bord', $response->getBody());
        $this->assertStringContainsString('Supervision', $response->getBody());
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

    /**
     * The adoption block renders, and it renders the honest branch: this
     * installation's report carries no `module_usage` at all, so the page
     * must say it cannot tell rather than draw a column of amber zeros
     * (ARCHITECTURE.md §8.51bis).
     */
    public function testTheAdoptionBlockRefusesToReadSilenceAsZero(): void
    {
        // A second installation that DOES declare a module but reports no
        // usage at all — the exact case the block must refuse to read as
        // « personne ne s'en sert ».
        $silent = $this->payload();
        $silent['installation_id'] = 'eeeeffff00001111';
        $silent['modules'] = [['id' => 'calendar', 'enabled' => true, 'version' => '1.2.0']];
        (new SupportInstallationRepository($this->pdo))->register(
            'eeeeffff00001111',
            password_hash('another-secret', PASSWORD_DEFAULT),
            (string) json_encode($silent),
            StatisticsIntakeService::denormalize($silent)
        );

        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard', 'index')
            ->handle(new Request('GET', '/support-dashboard', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('Adoption des modules', $body);
        $this->assertStringContainsString('ne mesure sa fréquentation', $body);
        // Not a single amber « 0 utilisés » badge: nothing was measured,
        // so nothing is claimed.
        $this->assertStringNotContainsString('0 utilisé', $body);
    }

    public function testTheSecretNeverAppearsInAnyResponse(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $index = $this->frontController('/support-dashboard', 'index')
            ->handle(new Request('GET', '/support-dashboard', [], [], [], []))->getBody();
        $detail = $this->frontController('/support-dashboard/installations/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/installations/' . $this->installationId, [], [], [], []))->getBody();

        foreach ([$index, $detail] as $body) {
            // The secret itself, and the bcrypt hash it is stored as.
            $this->assertStringNotContainsString(self::SENDER_SECRET, $body);
            $this->assertStringNotContainsString('$2y$', $body);
            $this->assertStringNotContainsString('secret_hash', $body);
        }
    }

    // ── The domain registration (roadmap: le WHOIS du domaine) ──────────

    /**
     * An installation cannot report who registered its own name, so the
     * receiver asks the registry. « Ce domaine expire dans trois
     * semaines » reaches support as « le site ne marche plus ».
     */
    public function testTheDialogShowsWhoHoldsTheDomain(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $this->recordWhois('found', ['registrar' => 'Example Hosting SA', 'expires_at' => '2027-03-04']);

        $body = $this->detailBody();

        $this->assertStringContainsString('Enregistrement du domaine', $body);
        $this->assertStringContainsString('Example Hosting SA', $body);
        $this->assertStringContainsString('2027-03-04', $body);
    }

    /**
     * **The verbatim response never renders.** It routinely names the
     * volunteer who registered the domain, with an address and a
     * telephone number; it belongs in the ticket's support dossier, a file
     * somebody downloads on purpose.
     */
    public function testTheRawResponseNeverReachesTheScreen(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $this->recordWhois(
            'found',
            ['registrar' => 'Example Hosting SA'],
            "Registrar: Example Hosting SA\nRegistrant Name: Marie Dupont\nRegistrant Phone: +32.81000000\n"
        );

        $body = $this->detailBody();

        $this->assertStringNotContainsString('Marie Dupont', $body);
        $this->assertStringNotContainsString('+32.81000000', $body);
    }

    /**
     * The three states, said in three different sentences. « Le registre
     * n'a pas répondu » read as « ce domaine n'est pas enregistré » would
     * be confidently wrong about somebody whose domain is fine.
     */
    public function testTheThreeOutcomesReadDifferently(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $this->assertStringContainsString('Pas encore consulté', $this->detailBody());

        $this->recordWhois('unavailable', null);
        $this->assertStringContainsString('n\'a pas répondu', $this->detailBody());

        $this->recordWhois('not_found', null);
        $this->assertStringContainsString('n\'est enregistré nulle part', $this->detailBody());
    }

    /**
     * @param array<string, mixed>|null $registration
     */
    private function recordWhois(string $status, ?array $registration, ?string $raw = null): void
    {
        $this->installations->recordWhois(
            $this->installationId,
            'unite-exemple.be',
            'whois.dnsbelgium.be',
            $status,
            $registration,
            $raw,
            new \DateTimeImmutable('2026-09-01 03:00:00')
        );
    }

    private function detailBody(): string
    {
        return $this->frontController('/support-dashboard/installations/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/installations/' . $this->installationId, [], [], [], []))
            ->getBody();
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
        $this->assertSame(302, $withoutToken->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );

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
