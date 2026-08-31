<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketAnalysisRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Task\PurgeTicketsHandler;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Ticket retention (roadmap IT-28).
 *
 * Two clocks, and the properties that matter are about the difference
 * between them: the archive is somebody else's server logs and goes
 * early, the ticket is the corpus and stays. The one case worth pinning
 * hardest is the ticket nobody ever closed — without its own ceiling it
 * would keep its archive for ever, and « on l'a gardée parce que personne
 * n'a cliqué » is not a retention policy.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeTicketsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SupportTicketRepository $tickets;
    private EncryptedFileStorageService $storage;
    private FileRepository $files;
    private TaskContext $context;
    private string $storagePath;
    private int $installationId;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);
        $this->files = new FileRepository($this->pdo);

        $this->storagePath = sys_get_temp_dir() . '/purge_tickets_' . uniqid();
        mkdir($this->storagePath . '/keys', 0o777, true);
        $this->storage = new EncryptedFileStorageService($this->files, $encryption, $this->storagePath);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            new MailService('local', 'unite@exemple.be', 'Unité', 'EX', new DkimManager($this->storagePath . '/keys'), 's1'),
            new JournalService(new JournalRepository($this->pdo)),
            $settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );

        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => 'unite-de-test',
            'instance_url' => 'https://unite-de-test.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
        ];
        $this->installationId = (new SupportInstallationRepository($this->pdo))->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/support-tickets/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach ([$this->storagePath . '/support-tickets', $this->storagePath . '/keys', $this->storagePath] as $dir) {
            @rmdir($dir);
        }
    }

    public function testAnArchiveGoesNinetyDaysAfterTheTicketIsClosed(): void
    {
        $recent = $this->ticketWithArchive('-100 days', closedRelative: '-80 days');
        $old = $this->ticketWithArchive('-200 days', closedRelative: '-100 days');

        (new PurgeTicketsHandler())->handle([], $this->context);

        $this->assertNotNull($this->tickets->find($recent)['archive_file_id']);
        $this->assertNull($this->tickets->find($old)['archive_file_id']);
    }

    /**
     * The bound that matters. A ticket nobody ever closed is the normal
     * fate of one nobody could reproduce; without this it would keep its
     * archive indefinitely.
     */
    public function testAnArchiveGoesAtTheOneYearCeilingEvenWhenTheTicketWasNeverClosed(): void
    {
        $young = $this->ticketWithArchive('-300 days');
        $old = $this->ticketWithArchive('-400 days');

        (new PurgeTicketsHandler())->handle([], $this->context);

        $this->assertNotNull($this->tickets->find($young)['archive_file_id']);
        $this->assertNull($this->tickets->find($old)['archive_file_id']);
        $this->assertSame(SupportTicketRepository::STATUS_OPEN, $this->tickets->find($old)['status']);
    }

    /**
     * The ticket is the corpus — losing it with the archive would lose
     * the whole point of keeping tickets at all.
     */
    public function testTheTicketAndItsResolutionNoteSurviveTheArchiveDeletion(): void
    {
        // Closed once, with the note — close() only ever moves an OPEN
        // ticket, so closing twice would silently keep the first note.
        $id = $this->ticketWithArchive('-200 days');
        $this->assertTrue(
            $this->tickets->close($id, 'Un cron absent chez eux.', new \DateTimeImmutable('-100 days'))
        );

        (new PurgeTicketsHandler())->handle([], $this->context);

        $ticket = $this->tickets->find($id);
        $this->assertNotNull($ticket);
        $this->assertNull($ticket['archive_file_id']);
        $this->assertNull($ticket['archive_received_at']);
        $this->assertSame('Un cron absent chez eux.', $ticket['resolution_note']);
        $this->assertSame(TicketCategory::INSTALLATION, $ticket['category']);
    }

    /**
     * Deleting the reference alone would leave an encrypted archive on
     * the receiver's disk that nothing points at and nobody remembers.
     */
    public function testTheFileItselfIsDeletedAndNotJustTheReference(): void
    {
        $id = $this->ticketWithArchive('-400 days');
        $fileId = (int) $this->tickets->find($id)['archive_file_id'];
        $record = $this->files->findById($fileId);
        $this->assertNotNull($record);
        $path = $this->storagePath . '/' . $record->relativePath;
        $this->assertFileExists($path);

        (new PurgeTicketsHandler())->handle([], $this->context);

        $this->assertNull($this->files->findById($fileId));
        $this->assertFileDoesNotExist($path);
    }

    public function testTheTicketItselfGoesAtTwoYears(): void
    {
        $keep = $this->ticket('-700 days');
        $drop = $this->ticket('-800 days');

        (new PurgeTicketsHandler())->handle([], $this->context);

        $this->assertNotNull($this->tickets->find($keep));
        $this->assertNull($this->tickets->find($drop));
    }

    /**
     * A stored analysis is a summary OF descriptions: keeping it past
     * their retention would be holding a digest of texts that no longer
     * exist anywhere.
     */
    public function testAnAnalysisNeverOutlivesTheTicketsItSummarises(): void
    {
        $analyses = new SupportTicketAnalysisRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $analyses->store('Trois unités sur un hébergeur sans cron.', 3, new \DateTimeImmutable('-800 days'));

        (new PurgeTicketsHandler())->handle([], $this->context);

        $this->assertNull($analyses->latest());
    }

    public function testItAlwaysReschedulesItself(): void
    {
        (new PurgeTicketsHandler())->handle([], $this->context);

        $next = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('support_dashboard', PurgeTicketsHandler::TASK_KEY, PurgeTicketsHandler::REFERENCE);

        $this->assertNotNull($next, 'a purge that stops rescheduling stops running forever');
    }

    private function ticket(string $createdRelative): int
    {
        $reference = $this->tickets->create(
            $this->installationId,
            TicketCategory::INSTALLATION,
            "L'installation s'arrête à l'étape 3.",
            'chef@unite.be',
            '1.0.33',
            '8.4.0'
        );

        $id = (int) $this->tickets->findByReference($reference)['id'];
        $this->pdo->prepare('UPDATE support_tickets SET created_at = ? WHERE id = ?')->execute([
            (new \DateTimeImmutable($createdRelative))->format('Y-m-d H:i:s'),
            $id,
        ]);

        return $id;
    }

    private function ticketWithArchive(string $createdRelative, ?string $closedRelative = null): int
    {
        $id = $this->ticket($createdRelative);

        $this->tickets->attachArchive($id, $this->storage->store(
            'PK' . str_repeat('x', 64),
            'application/zip',
            'support.zip',
            'support-tickets',
            'superadmin'
        ));

        if ($closedRelative !== null) {
            $this->tickets->close($id, null, new \DateTimeImmutable($closedRelative));
        }

        return $id;
    }
}
