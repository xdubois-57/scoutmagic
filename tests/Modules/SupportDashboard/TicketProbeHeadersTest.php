<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\SupportDashboard\Controller\SupportTicketController;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMailProbeRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\MailProbeService;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportTicketService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * « SPF, DKIM et DMARC absents » is a claim about what a server wrote
 * down, and a maintainer had no way to check it: the reading was shown
 * and the block it was read from was thrown away. Both halves of the
 * answer are pinned here — the headers are on the ticket page, and they
 * are in a file that can be kept and forwarded.
 */
class TicketProbeHeadersTest extends TestCase
{
    private const HEADERS = "Return-Path: <unite@example.be>\r\n"
        . "Received: from mail.example.be (mail.example.be [203.0.113.7])\r\n"
        . "\tby mx.receveur.be with ESMTPS id abc123\r\n"
        . "Authentication-Results: mx.receveur.be; spf=pass smtp.mailfrom=example.be\r\n"
        . "Authentication-Results: mx.receveur.be; dkim=pass header.d=example.be\r\n";

    private \PDO $pdo;
    private Environment $twig;
    private SupportTicketController $controller;
    private int $ticketId;
    private string $reference;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $tickets = new SupportTicketRepository($this->pdo, $encryption);
        $installations = new SupportInstallationRepository($this->pdo);

        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => 'unite-de-test',
            'instance_url' => 'https://unite-de-test.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'usage' => ['active_members' => 118, 'active_sections' => 6],
        ];
        $installationId = $installations->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );

        $this->reference = $tickets->create(
            $installationId,
            TicketCategory::of('email'),
            'Mes e-mails ne partent pas.',
            'chef@unite.be',
            '1.0.33',
            '8.4.0'
        );
        $this->ticketId = (int) $tickets->findByReference($this->reference)['id'];

        $probeRepository = new SupportMailProbeRepository($this->pdo, $encryption);
        $now = new \DateTimeImmutable('2026-09-01 10:00:00');
        $probeRepository->issue(
            $installationId,
            'SMP-ABCDEFGHJK',
            ['support@scoutmagic.be'],
            $now,
            $now->modify('+48 hours')
        );
        $probes = new MailProbeService($probeRepository, $installations, $journal);
        $probes->claim(
            '[25SV] Sonde de diagnostic SMP-ABCDEFGHJK',
            self::HEADERS,
            $now->modify('+30 seconds'),
            $now->modify('+30 seconds')
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
        $this->twig->addGlobal('current_path', '/support-dashboard/tickets');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new SupportTicketController(
            $this->twig,
            new SupportTicketService($tickets, $journal, $probes)
        );
    }

    private function detailBody(): string
    {
        return $this->controller
            ->detail(new Request('GET', '/support-dashboard/tickets/' . $this->ticketId, [], [], [], []), [
                'id' => (string) $this->ticketId,
            ])
            ->getBody();
    }

    public function testTheTicketPageShowsTheHeadersTheProbeArrivedWith(): void
    {
        $body = $this->detailBody();

        $this->assertStringContainsString('<summary>En-têtes reçus</summary>', $body);
        $this->assertStringContainsString('Authentication-Results: mx.receveur.be; spf=pass', $body);
        $this->assertStringContainsString('203.0.113.7', $body);
    }

    /**
     * The ticket page used to render the verdicts raw — « absent » where
     * the installation dialog said « non renseigné ». It is the page
     * somebody lands on from a ticket, and « absent » reads as a failure
     * to everybody who is not the person who wrote the parser.
     */
    public function testTheTicketPageSpellsTheVerdictsOutTheWayTheOtherPageDoes(): void
    {
        $body = $this->detailBody();

        $this->assertStringContainsString('réussi', $body);
        $this->assertStringContainsString('non renseigné', $body);
        $this->assertStringNotContainsString('<td>absent</td>', $body);
    }

    public function testTheProbesCanBeDownloadedWithTheirHeaders(): void
    {
        $response = $this->controller->probes(
            new Request('GET', '/support-dashboard/tickets/' . $this->ticketId . '/sondes', [], [], [], []),
            ['id' => (string) $this->ticketId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', (string) $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString(
            'sondes-' . $this->reference,
            (string) $response->getHeaders()['Content-Disposition']
        );

        $body = $response->getBody();
        $this->assertStringContainsString('SMP-ABCDEFGHJK', $body);
        $this->assertStringContainsString('support@scoutmagic.be', $body);
        // The words the page uses, not the parser's own vocabulary: this
        // file gets forwarded to a hosting provider.
        $this->assertStringContainsString('réussi', $body);
        $this->assertStringContainsString('non renseigné', $body);
        $this->assertStringContainsString('Authentication-Results: mx.receveur.be; dkim=pass', $body);
    }

    /**
     * The first thing the maintainer will see after this ships: the
     * probes already in the table were received before any header block
     * was kept, and there is nothing to back-fill them from. The row has
     * to say WHY it shows three « non renseigné » rather than leaving
     * them looking like a verdict — « aucun en-tête conservé » is itself
     * the explanation, and the answer is to send a fresh probe.
     */
    public function testAProbeReceivedBeforeHeadersWereKeptSaysSoRatherThanShowingNothing(): void
    {
        $this->pdo->exec('UPDATE support_mail_probes SET raw_headers_encrypted = NULL');

        $body = $this->detailBody();

        // The explanatory paragraph under the table names « En-têtes
        // reçus » whatever happens; what must be gone is the disclosure
        // itself.
        $this->assertStringNotContainsString('<summary>En-têtes reçus</summary>', $body);
        $this->assertStringContainsString('Aucun en-tête conservé pour cette sonde', $body);
    }

    public function testDownloadingTheProbesOfATicketThatDoesNotExistIsANotFound(): void
    {
        $response = $this->controller->probes(
            new Request('GET', '/support-dashboard/tickets/9999/sondes', [], [], [], []),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
