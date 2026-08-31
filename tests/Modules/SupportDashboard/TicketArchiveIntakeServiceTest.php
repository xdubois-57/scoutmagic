<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TicketArchiveIntakeService;
use Modules\SupportDashboard\Service\TicketIntakeResult;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The receiver's side of the archive (roadmap IT-26).
 *
 * What matters here is that the archive is no less protected than it was
 * on the installation that produced it — encrypted at rest, `role_min:
 * superadmin`, reachable only through `/files/{id}` — and that a caller
 * learns nothing it did not already know.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TicketArchiveIntakeServiceTest extends TestCase
{
    private \PDO $pdo;
    private TicketArchiveIntakeService $service;
    private SupportTicketRepository $tickets;
    private FileRepository $files;
    private string $storagePath;
    private string $reference;

    private const INSTALLATION_ID = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const SECRET = 'f1e2d3c4b5a6978869504132231405f6';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-archive-intake-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);

        $this->files = new FileRepository($this->pdo);
        $installations = new SupportInstallationRepository($this->pdo);
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);

        $this->service = new TicketArchiveIntakeService(
            $installations,
            $this->tickets,
            new EncryptedFileStorageService($this->files, $encryption, $this->storagePath),
            new JournalService(new JournalRepository($this->pdo))
        );

        $installationRowId = $installations->register(
            self::INSTALLATION_ID,
            password_hash(self::SECRET, PASSWORD_DEFAULT),
            '',
            [],
            false
        );
        $this->reference = $this->tickets->create(
            $installationRowId,
            TicketCategory::DESK_IMPORT,
            'Une description.',
            'chef@unite.be',
            '1.0.33',
            '8.4.3'
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->storagePath . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->storagePath);
    }

    public function testAnAcceptedArchiveIsEncryptedAtRestAndSuperadminOnly(): void
    {
        $result = $this->receive('PK-des-octets-darchive');

        $this->assertTrue($result->accepted);

        $ticket = $this->tickets->findByReference($this->reference);
        $this->assertNotNull($ticket);
        $this->assertNotNull($ticket['archive_file_id']);
        $this->assertNotNull($ticket['archive_received_at']);

        $record = $this->files->findById((int) $ticket['archive_file_id']);
        $this->assertNotNull($record);
        $this->assertSame('superadmin', $record->roleMin);
        $this->assertTrue($record->encrypted);

        // On disk it is a ciphertext, not a zip.
        $onDisk = (string) file_get_contents($this->storagePath . '/' . $record->relativePath);
        $this->assertStringNotContainsString('PK-des-octets', $onDisk);
    }

    public function testAnArchiveWithoutCredentialsIsRefusedAndJournaledAsSecurity(): void
    {
        $result = $this->service->receive($this->reference, 'PK-bytes', '', '203.0.113.1', true);

        $this->assertSame(403, $result->statusCode);
        $this->assertSame(
            ['support_ticket_archive_unauthenticated'],
            $this->journalTypes()
        );
    }

    /**
     * A reference nobody has answers exactly like a wrong secret: a
     * caller must not be able to discover which references exist by
     * watching which answer comes back.
     */
    public function testAnUnknownReferenceIsIndistinguishableFromAWrongSecret(): void
    {
        $unknown = $this->service->receive('SUP-XXXXXX', 'PK', 'Bearer ' . self::SECRET, '203.0.113.1', true);
        $wrongSecret = $this->service->receive($this->reference, 'PK', 'Bearer nope', '203.0.113.1', true);

        $this->assertSame(403, $unknown->statusCode);
        $this->assertSame(403, $wrongSecret->statusCode);
        $this->assertSame($unknown->rejectionReason, $wrongSecret->rejectionReason);
    }

    public function testAnArchiveOverTheCeilingIsRefusedExplicitlyRatherThanTruncated(): void
    {
        $result = $this->receive(str_repeat('a', TicketArchiveIntakeService::MAX_ARCHIVE_BYTES + 1));

        $this->assertFalse($result->accepted);
        $this->assertSame(200, $result->statusCode, 'a retry would not help, so not a retryable status');
        $this->assertSame(TicketArchiveIntakeService::REJECT_TOO_LARGE, $result->rejectionReason);
        $this->assertNull($this->tickets->findByReference($this->reference)['archive_file_id']);
    }

    public function testAnEmptyBodyIsRefused(): void
    {
        $this->assertSame(
            TicketArchiveIntakeService::REJECT_EMPTY,
            $this->receive('')->rejectionReason
        );
    }

    /**
     * A retry after an upload that actually succeeded must not store a
     * second copy — the instance cannot tell the difference, so the
     * receiver has to.
     */
    public function testASecondArchiveForTheSameTicketStoresNothingNew(): void
    {
        $this->receive('PK-premier');
        $first = (int) $this->tickets->findByReference($this->reference)['archive_file_id'];

        $result = $this->receive('PK-second');

        $this->assertTrue($result->accepted);
        $this->assertSame($first, (int) $this->tickets->findByReference($this->reference)['archive_file_id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testCleartextTransportIsRefused(): void
    {
        $result = $this->service->receive($this->reference, 'PK', 'Bearer ' . self::SECRET, '203.0.113.1', false);

        $this->assertSame(TicketIntakeResult::REJECT_INSECURE_TRANSPORT, $result->rejectionReason);
    }

    private function receive(string $bytes): TicketIntakeResult
    {
        return $this->service->receive(
            $this->reference,
            $bytes,
            'Bearer ' . self::SECRET,
            '203.0.113.1',
            true
        );
    }

    /** @return array<int, string> */
    private function journalTypes(): array
    {
        return array_column(
            $this->pdo->query(
                "SELECT event_type FROM event_log WHERE category = 'support_dashboard' ORDER BY id ASC"
            )->fetchAll(\PDO::FETCH_ASSOC),
            'event_type'
        );
    }
}
