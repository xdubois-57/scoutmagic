<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Api\LlmException;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketAnalysisRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TicketAnalysisOutcome;
use Modules\SupportDashboard\Service\TicketAnalysisService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\Support\FakeLlmConnector;

/**
 * Why an analysis produced nothing — four answers, not one.
 *
 * The complaint that produced this file: « L'analyse n'a pas abouti : le
 * fournisseur n'a rien renvoyé d'exploitable », shown on the Maintenance
 * page, after asking for a ticket analysis somewhere else entirely.
 *
 * Two defects in one sentence. It named a third party for a request that,
 * in one of the four cases, was never made — a receiver with no
 * describable tickets contacted nobody. And it named no subject, so when
 * it surfaced on another page (a flash lives until some page renders it,
 * and the answer to a request nobody waited for lands on whatever comes
 * next) it read as the maintenance update having failed.
 */
class TicketAnalysisOutcomeTest extends TestCase
{
    private \PDO $pdo;
    private SupportTicketRepository $tickets;
    private JournalService $journal;
    private EncryptionService $encryption;
    private int $installationId;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $this->encryption);
        $this->journal = new JournalService(new JournalRepository($this->pdo));

        $this->installationId = (new SupportInstallationRepository($this->pdo))->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            '{}',
            []
        );
    }

    private function seedTicket(string $description = "L'import Desk s'arrête à mi-parcours."): void
    {
        $this->tickets->create(
            $this->installationId,
            TicketCategory::of('desk_import'),
            $description,
            'chef@unite.be',
            '1.0.33',
            '8.4.0'
        );
    }

    private function service(?FakeLlmConnector $llm): TicketAnalysisService
    {
        return new TicketAnalysisService(
            $this->tickets,
            new SupportTicketAnalysisRepository($this->pdo, $this->encryption),
            $this->journal,
            $llm
        );
    }

    private function analyse(?FakeLlmConnector $llm): TicketAnalysisOutcome
    {
        return $this->service($llm)->run(new \DateTimeImmutable('2026-09-03 08:00:00'));
    }

    /** @return list<array<string, mixed>> */
    private function journalEntries(): array
    {
        return (new JournalRepository($this->pdo))->search();
    }

    private function journalTypes(): array
    {
        return array_map(static fn(array $e): string => (string) $e['event_type'], $this->journalEntries());
    }

    // ── The four outcomes ───────────────────────────────────────────────

    public function testNothingToAnalyseIsNotTheProvidersFault(): void
    {
        // The defect, exactly: no ticket carries a description, so no
        // provider is contacted — and the screen used to say the provider
        // returned nothing usable.
        $llm = new FakeLlmConnector(true, '### Groupe');

        $outcome = $this->analyse($llm);

        $this->assertSame(TicketAnalysisOutcome::NO_TICKETS, $outcome);
        $this->assertSame(0, $llm->calls, 'nothing may leave this installation when there is nothing to send');
        $this->assertStringContainsString("Aucun des tickets de support n'est analysable", $outcome->message());
        $this->assertStringContainsString("rien n'a été envoyé", $outcome->message());
    }

    public function testATicketWithNoDescriptionIsNotAnalysable(): void
    {
        $this->seedTicket('   ');

        $this->assertSame(TicketAnalysisOutcome::NO_TICKETS, $this->analyse(new FakeLlmConnector(true, 'x')));
    }

    public function testAnEmptyAnswerSaysTheTransmissionHappened(): void
    {
        // The other half of the distinction: here the descriptions DID
        // leave the installation, and the answer was empty. That is the
        // only case the old sentence described correctly.
        $this->seedTicket();
        $llm = new FakeLlmConnector(true, "   \n  ");

        $outcome = $this->analyse($llm);

        $this->assertSame(TicketAnalysisOutcome::EMPTY_ANSWER, $outcome);
        $this->assertSame(1, $llm->calls);
        $this->assertStringContainsString("n'a rien renvoyé d'exploitable", $outcome->message());
    }

    public function testAFailedCallIsItsOwnOutcome(): void
    {
        $this->seedTicket();

        $outcome = $this->analyse(new FakeLlmConnector(true, '', new LlmException('boom')));

        $this->assertSame(TicketAnalysisOutcome::PROVIDER_FAILED, $outcome);
        $this->assertStringContainsString("l'appel au fournisseur IA a échoué", $outcome->message());
    }

    public function testNoConnectorIsSaidAsSuchAndSendsNothing(): void
    {
        $this->seedTicket();

        $this->assertSame(TicketAnalysisOutcome::UNAVAILABLE, $this->analyse(null));
    }

    public function testAResultIsStored(): void
    {
        $this->seedTicket();

        $service = $this->service(new FakeLlmConnector(true, "### Import Desk\n2 tickets."));
        $outcome = $service->run(new \DateTimeImmutable('2026-09-03 08:00:00'));

        $this->assertSame(TicketAnalysisOutcome::STORED, $outcome);
        $this->assertTrue($outcome->isSuccess());
        $this->assertStringContainsString('Import Desk', (string) $service->latest()['result']);
    }

    // ── Every sentence names its own subject ────────────────────────────

    /**
     * A flash lives until some page renders it, so one of these can and
     * does surface on an unrelated page. That makes a message which does
     * not say what it is about actively misleading — « L'analyse n'a pas
     * abouti » on the Maintenance page reads as the update having failed.
     */
    public function testEveryMessageNamesWhatItIsAbout(): void
    {
        foreach (TicketAnalysisOutcome::cases() as $outcome) {
            $this->assertStringContainsString(
                'tickets de support',
                $outcome->message(),
                $outcome->value . ' does not say what it is about: on another page it reads as '
                . 'something else having failed'
            );
            $this->assertNotSame('', $outcome->flashType());
        }
    }

    public function testNothingToAnalyseIsNotShownAsAFailure(): void
    {
        // A receiver with no open complaints is the ordinary state, and
        // colouring it red teaches people to ignore red.
        $this->assertSame('warning', TicketAnalysisOutcome::NO_TICKETS->flashType());
        $this->assertSame('success', TicketAnalysisOutcome::STORED->flashType());
        $this->assertSame('error', TicketAnalysisOutcome::EMPTY_ANSWER->flashType());
    }

    // ── The journal: no outcome answers with silence ────────────────────

    public function testEveryRefusalReachesTheJournalAndNotJustTheOneThatUsedTo(): void
    {
        $this->analyse(new FakeLlmConnector(true, 'x'));
        $this->assertContains('support_ticket_analysis_skipped', $this->journalTypes());

        $this->seedTicket();
        $this->analyse(new FakeLlmConnector(true, ' '));
        $this->assertContains('support_ticket_analysis_empty', $this->journalTypes());

        $this->analyse(new FakeLlmConnector(true, '', new LlmException('boom')));
        $this->assertContains('support_ticket_analysis_failed', $this->journalTypes());

        $this->analyse(new FakeLlmConnector(true, '### Groupe'));
        $this->assertContains('support_ticket_analysis_run', $this->journalTypes());
    }

    public function testTheJournalCarriesCountsAndNeverATicketsText(): void
    {
        // §7.9: a journal entry travels in the diagnostic archive, where
        // what somebody wrote about their own installation has no
        // business being.
        $this->seedTicket('Mon adresse est chef@unite.be et le site plante.');
        $this->analyse(new FakeLlmConnector(true, '### Groupe'));

        foreach ($this->journalEntries() as $entry) {
            $whole = (string) $entry['description'] . (string) $entry['context'];
            $this->assertStringNotContainsString('chef@unite.be', $whole);
            $this->assertStringNotContainsString('le site plante', $whole);
        }

        $entry = $this->journalEntries()[0];
        $this->assertStringContainsString('"tickets":1', (string) $entry['context']);
    }
}
