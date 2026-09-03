<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\SupportDashboard\Controller\SupportTicketController;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketAnalysisRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportTicketService;
use Modules\SupportDashboard\Service\TicketAnalysisService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * The ticket queue's RBAC boundary through the REAL Router/RbacGuard
 * pipeline, and the degradation that matters most: **without
 * `llm_connector` the page works and the analysis block is simply not
 * there** (ARCHITECTURE.md §7.5). A feature a unit does not have must not
 * be mentioned to it, and a page that half-renders one is worse than a
 * page without it.
 */
class SupportTicketControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private SupportTicketRepository $tickets;
    private int $ticketId;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);

        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => 'unite-de-test',
            'instance_url' => 'https://unite-de-test.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'usage' => ['active_members' => 118, 'active_sections' => 6],
        ];
        $installationId = (new SupportInstallationRepository($this->pdo))->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );

        $reference = $this->tickets->create(
            $installationId,
            TicketCategory::of('desk_import'),
            "L'import Desk s'arrête à mi-parcours.",
            'chef@unite.be',
            '1.0.33',
            '8.4.0'
        );
        $this->ticketId = (int) $this->tickets->findByReference($reference)['id'];

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
        $this->twig->addGlobal('current_path', '/support-dashboard/tickets');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testSuperadminReachesTheList(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/tickets', 'index')
            ->handle(new Request('GET', '/support-dashboard/tickets', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Tickets de support', $response->getBody());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'the list' => ['/support-dashboard/tickets', 'index', 'GET'],
            'a ticket' => ['/support-dashboard/tickets/{id}', 'detail', 'GET'],
            'closing one' => ['/support-dashboard/tickets/{id}/close', 'close', 'POST'],
            'reopening one' => ['/support-dashboard/tickets/{id}/reopen', 'reopen', 'POST'],
            'the analysis' => ['/support-dashboard/tickets/analyse', 'analyse', 'POST'],
        ];
    }

    /**
     * Every route of this controller, not just the list: a ticket carries
     * a description, a contact address and sometimes an archive of
     * somebody's server logs.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testEveryRouteRefusesAnAdmin(string $path, string $action, string $method): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->frontController($path, $action, $method)->handle(new Request(
            $method,
            str_replace('{id}', (string) $this->ticketId, $path),
            [],
            [],
            [],
            []
        ));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheDetailShowsTheDescriptionAndTheInstallation(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard/tickets/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/tickets/' . $this->ticketId, [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('mi-parcours', $body);
        $this->assertStringContainsString('unite-de-test.example.be', $body);
        $this->assertStringContainsString('118', $body);
    }

    /**
     * A ticket description is PROSE, and the page must read it as prose
     * whatever else fails to load.
     *
     * The complaint: « la description est entièrement sur une ligne et
     * donc illisible avec un long scrolling horizontal ». It was a `pre`
     * element, and a `pre` wraps only when a stylesheet tells it to — the
     * one that did is components.css, which base.html.twig deliberately
     * does not load, so every page has to ask for it. Nothing in this
     * suite could see that: the markup was valid, the sentence was there,
     * and the page was unreadable.
     *
     * So the guarantee moved into the markup itself. The line breaks are
     * `<br>` tags rather than a `white-space` property, and the wrapping
     * of a pasted URL comes from Bootstrap's `text-break`, which
     * base.html.twig loads unconditionally.
     */
    public function testTheDescriptionReadsAsProseAndNotAsACodeBlock(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard/tickets/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/tickets/' . $this->ticketId, [], [], [], []))
            ->getBody();

        $this->assertStringNotContainsString(
            '<pre class="border rounded p-3 bg-body-tertiary small mb-0',
            $body,
            'the description is back in a <pre>: it then wraps only if components.css loads, and '
            . 'a page that renders without it shows the whole sentence on one line'
        );
        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*text-break[^"]*support-ticket-description"/',
            $body,
            'the description block lost text-break — a pasted URL would widen the card again, and '
            . 'that utility is the only one here that does not depend on an optional stylesheet'
        );
    }

    /**
     * The breaks somebody typed survive as markup.
     *
     * `nl2br` escapes before it inserts anything, so this also pins that a
     * description is never rendered as markup: what a stranger wrote in a
     * support form reaches this page as text, always.
     */
    public function testTheLineBreaksSomebodyTypedAreKeptAndTheirMarkupIsNot(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $reference = $this->tickets->create(
            (int) $this->pdo->query('SELECT id FROM support_installations LIMIT 1')->fetchColumn(),
            TicketCategory::of('desk_import'),
            "Première ligne.\nSeconde ligne.\n\n<b>pas du balisage</b>",
            'chef@unite.be',
            '1.0.33',
            '8.4.0'
        );
        $id = (int) $this->tickets->findByReference($reference)['id'];

        $body = $this->frontController('/support-dashboard/tickets/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/tickets/' . $id, [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('Première ligne.<br />', $body);
        $this->assertStringContainsString('Seconde ligne.', $body);
        // Escaped first, then broken into lines: the sender's angle
        // brackets are text on this page and can never be anything else.
        $this->assertStringNotContainsString('<b>pas du balisage</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;pas du balisage&lt;/b&gt;', $body);
    }

    public function testAnUnknownTicketIs404AndNotAnEmptyPage(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/tickets/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/tickets/999999', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The degradation this file exists for: no connector, no block, and
     * no mention anywhere of a feature this installation does not have.
     */
    public function testWithoutTheLlmConnectorTheListWorksAndTheAnalysisBlockIsAbsent(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard/tickets', 'index', 'GET', withAnalysis: false)
            ->handle(new Request('GET', '/support-dashboard/tickets', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('Tickets de support', $body);
        $this->assertStringContainsString('mi-parcours', $body);
        $this->assertStringNotContainsString('Analyse transversale', $body);
        $this->assertStringNotContainsString('/support-dashboard/tickets/analyse', $body);
    }

    /**
     * Same absence when the module IS wired but has no active provider —
     * the two cases must be indistinguishable to a reader.
     */
    public function testAConnectorWithNoActiveProviderAlsoHidesTheBlock(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard/tickets', 'index')
            ->handle(new Request('GET', '/support-dashboard/tickets', [], [], [], []))
            ->getBody();

        $this->assertStringNotContainsString('Analyse transversale', $body);
    }

    private function frontController(
        string $path,
        string $action,
        string $method = 'GET',
        bool $withAnalysis = true
    ): FrontController {
        $router = new Router();
        $router->addRoute($method, $path, SupportTicketController::class, $action, 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_support_tickets_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $journal = new JournalService(new JournalRepository($this->pdo));
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $controller = new SupportTicketController(
            $this->twig,
            new SupportTicketService($this->tickets, $journal),
            // With `$withAnalysis` false there is no service at all — the
            // shape a receiver without `llm_connector` actually has.
            $withAnalysis
                ? new TicketAnalysisService(
                    $this->tickets,
                    new SupportTicketAnalysisRepository($this->pdo, $encryption),
                    $journal,
                    null
                )
                : null,
            // The dossier the ticket page offers. Built with no file
            // reader: the receiver's own knowledge is what these tests
            // are about, and an uploaded archive is covered by
            // TicketDossierBuilderTest.
            new \Modules\SupportDashboard\Service\TicketDossierBuilder(
                new \Modules\SupportDashboard\Repository\SupportInstallationRepository($this->pdo)
            )
        );

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(SupportTicketController::class, $controller);

        return $frontController;
    }

    // ── Le dossier complet, depuis la page du ticket ────────────────────

    /**
     * One download holding everything this receiver knows.
     *
     * The complaint: the probes were a second download and the two
     * readings of the statistics were on the screen and nowhere else, so
     * the one file a maintainer takes away had a third of the story.
     */
    public function testTheDossierRouteAnswersWithAZipNamedAfterTheTicket(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/tickets/{id}/dossier', 'dossier')
            ->handle(new Request('GET', '/support-dashboard/tickets/' . $this->ticketId . '/dossier', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertStringContainsString(
            'dossier-support-',
            (string) ($response->getHeaders()['Content-Disposition'] ?? '')
        );
        // A real zip, not an error page with a zip content type.
        $this->assertStringStartsWith("PK\x03\x04", $response->getBody());
    }

    public function testTheDossierIsOfferedOnThePageAndSaysWhatItHolds(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $body = $this->frontController('/support-dashboard/tickets/{id}', 'detail')
            ->handle(new Request('GET', '/support-dashboard/tickets/' . $this->ticketId, [], [], [], []))
            ->getBody();

        $this->assertStringContainsString(
            '/support-dashboard/tickets/' . $this->ticketId . '/dossier',
            $body
        );
        $this->assertStringContainsString('sondes e-mail', $body);
        $this->assertStringContainsString('statistiques au moment du ticket', $body);
    }

    public function testAnUnknownTicketHasNoDossier(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/tickets/{id}/dossier', 'dossier')
            ->handle(new Request('GET', '/support-dashboard/tickets/999999/dossier', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }

}
