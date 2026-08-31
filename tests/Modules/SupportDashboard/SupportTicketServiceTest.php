<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportTicketService;
use Modules\SupportDashboard\Service\TicketListFilters;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The ticket queue's reading half (roadmap IT-28).
 *
 * Two properties carry this file. The **search runs in PHP after
 * decryption** — a `WHERE … LIKE` on `description_encrypted` matches
 * ciphertext and finds nothing, so a test that only checked "the search
 * works" against a plaintext column would prove the wrong thing. And the
 * **installation is joined onto the ticket**, which is the whole reason
 * the design reuses the statistics identity rather than minting a second
 * credential.
 */
class SupportTicketServiceTest extends TestCase
{
    private \PDO $pdo;
    private SupportTicketRepository $tickets;
    private SupportTicketService $service;
    private int $installationId;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);
        $this->service = new SupportTicketService(
            $this->tickets,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->installationId = $this->registerInstallation('unite-de-test', [
            'statistics_schema_version' => 1,
            'installation_id' => 'unite-de-test',
            'instance_url' => 'https://unite-de-test.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'scoutmagic_version' => '1.0.34',
            'active_members' => 118,
            'active_sections' => 6,
            'installation_method' => 'archive',
            'php_version' => '8.4.1',
        ]);
    }

    /**
     * The join that justified reusing the statistics identity: a ticket
     * carries a category and a sentence, and « quelle version, combien de
     * membres » is the other half of any answer.
     */
    public function testTheDetailCarriesTheInstallationsLatestKnownStatistics(): void
    {
        $id = $this->ticket('Rien ne se passe après le clic.');

        $detail = $this->service->detail($id);

        $this->assertNotNull($detail);
        $this->assertSame('unite-de-test', $detail['installation']['public_id']);
        $this->assertSame('https://unite-de-test.example.be', $detail['installation']['instance_url']);
        $this->assertSame('1.0.34', $detail['installation']['scoutmagic_version']);
        $this->assertSame(118, $detail['installation']['active_members']);
        $this->assertSame(6, $detail['installation']['active_sections']);
        $this->assertSame('archive', $detail['installation']['installation_method']);
    }

    /**
     * The ticket outlives its installation record: retention deletes the
     * installation after six months and the ticket after two years, so
     * this is an ordinary state and not a broken row.
     */
    public function testATicketWhoseInstallationIsGoneStillReads(): void
    {
        $id = $this->ticket('Un souci ancien.');
        $this->pdo->prepare('DELETE FROM support_installations WHERE id = ?')->execute([$this->installationId]);

        $detail = $this->service->detail($id);

        $this->assertNotNull($detail);
        $this->assertNull($detail['installation']['public_id']);
        $this->assertNull($detail['installation']['instance_url']);
        $this->assertSame('Un souci ancien.', $detail['description']);
    }

    /**
     * The description is a ciphertext in the column, so this search cannot
     * be a SQL `LIKE` — and a test that passed against a plaintext column
     * would be proving the wrong thing.
     */
    public function testTheSearchReadsAnEncryptedDescription(): void
    {
        $this->ticket("L'import Desk s'arrête à mi-parcours.");
        $this->ticket('Les e-mails ne partent pas.');

        // Nothing readable is in the column itself.
        $stored = (string) $this->pdo->query('SELECT description_encrypted FROM support_tickets LIMIT 1')->fetchColumn();
        $this->assertStringNotContainsString('import Desk', $stored);

        $found = $this->service->list($this->filters(['q' => 'import desk', 'status' => 'all']));

        $this->assertCount(1, $found);
        $this->assertStringContainsString('import Desk', $found[0]['description']);
    }

    public function testTheSearchIgnoresCaseAndAccents(): void
    {
        $this->ticket("L'installation s'arrête à l'étape 3.");

        $this->assertCount(1, $this->service->list($this->filters(['q' => 'ARRETE', 'status' => 'all'])));
        $this->assertCount(1, $this->service->list($this->filters(['q' => 'arrête', 'status' => 'all'])));
        $this->assertCount(0, $this->service->list($this->filters(['q' => 'introuvable', 'status' => 'all'])));
    }

    public function testTheSearchAlsoFindsAReference(): void
    {
        $id = $this->ticket('Un message quelconque.');
        $reference = (string) $this->tickets->find($id)['reference'];

        $found = $this->service->list($this->filters(['q' => strtolower($reference), 'status' => 'all']));

        $this->assertCount(1, $found);
        $this->assertSame($reference, $found[0]['reference']);
    }

    public function testTheDefaultListShowsOpenTicketsOnly(): void
    {
        $open = $this->ticket('Toujours ouvert.');
        $closed = $this->ticket('Déjà réglé.');
        $this->service->close($closed, 'Réglé.', new \DateTimeImmutable());

        $default = $this->service->list($this->filters([]));

        $this->assertCount(1, $default);
        $this->assertSame($open, $default[0]['id']);
        $this->assertCount(2, $this->service->list($this->filters(['status' => 'all'])));
        $this->assertCount(1, $this->service->list($this->filters(['status' => 'closed'])));
    }

    public function testTheCategoryFilterKeepsOnlyThatCategory(): void
    {
        $this->ticket('Un souci de courriel.', TicketCategory::EMAIL);
        $this->ticket("Un souci d'installation.", TicketCategory::INSTALLATION);

        $found = $this->service->list($this->filters(['category' => 'email', 'status' => 'all']));

        $this->assertCount(1, $found);
        $this->assertSame(TicketCategory::EMAIL, $found[0]['category']);
    }

    /**
     * The ticket is one-way, so the resolution note is the only place what
     * actually happened is written down.
     */
    public function testClosingStoresTheNoteAndIsNotRepeatable(): void
    {
        $id = $this->ticket('Un souci.');

        $this->assertTrue($this->service->close($id, 'Cron absent chez eux.', new \DateTimeImmutable()));
        $this->assertFalse($this->service->close($id, 'Une autre note.', new \DateTimeImmutable()));

        $ticket = $this->tickets->find($id);
        $this->assertSame(SupportTicketRepository::STATUS_CLOSED, $ticket['status']);
        $this->assertSame('Cron absent chez eux.', $ticket['resolution_note']);
        $this->assertNotNull($ticket['closed_at']);
    }

    /**
     * « Clos sans note » and « clos avec une note vide » are the same
     * fact; only one of them should be representable.
     */
    public function testAnEmptyNoteIsStoredAsNoNote(): void
    {
        $id = $this->ticket('Un souci.');
        $this->service->close($id, '   ', new \DateTimeImmutable());

        $this->assertNull($this->tickets->find($id)['resolution_note']);
    }

    /**
     * The entry says a ticket was closed; the note is the maintainer's own
     * words about somebody's installation and belongs in the ticket.
     */
    public function testTheJournalEntryCarriesTheReferenceAndNotTheNote(): void
    {
        $id = $this->ticket('Un souci.');
        $this->service->close($id, 'Une phrase que personne ne doit relire ici.', new \DateTimeImmutable());

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'support_ticket_closed'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('SUP-', (string) $row['context']);
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['context']);
        $this->assertStringNotContainsString('personne ne doit relire', (string) $row['description']);
    }

    public function testOnlyTheCategoriesActuallyPresentAreOffered(): void
    {
        $this->ticket('Un souci de courriel.', TicketCategory::EMAIL);

        $this->assertSame([TicketCategory::EMAIL], $this->service->categoriesInUse());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function filters(array $query): TicketListFilters
    {
        return TicketListFilters::fromQuery($query);
    }

    private function ticket(string $description, TicketCategory $category = TicketCategory::OTHER): int
    {
        $reference = $this->tickets->create(
            $this->installationId,
            $category,
            $description,
            'chef@unite.be',
            '1.0.33',
            '8.4.0'
        );

        return (int) $this->tickets->findByReference($reference)['id'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function registerInstallation(string $installationId, array $payload): int
    {
        return (new SupportInstallationRepository($this->pdo))->register(
            $installationId,
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );
    }
}
