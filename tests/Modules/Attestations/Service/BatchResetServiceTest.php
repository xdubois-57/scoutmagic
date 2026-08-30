<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberDocumentRepository;
use Core\Scheduler\SchedulerService;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Service\AttestationsException;
use Modules\Attestations\Service\BatchPublicationService;
use Modules\Attestations\Service\BatchResetService;
use Modules\Attestations\Service\BatchVerificationService;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * Taking a whole batch back.
 *
 * The remedy for the one mistake that is only visible afterwards — a split
 * one page out of step gives every family the next family's certificate —
 * so what matters is that it removes ALL of this batch and NOTHING of any
 * other, rows and bytes alike.
 */
#[Group('database')]
class BatchResetServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storageRoot;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private FileRepository $files;
    private EncryptedFileStorageService $fileStorage;
    private MemberDocumentRepository $documents;
    private BatchPublicationService $publication;
    private BatchResetService $service;
    private int $scoutYearId;
    private int $batchId;

    /** @var array<string, int> */
    private array $memberIds = [];

    /** @var array<string, int> */
    private array $lineIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->storageRoot = AttestationsTestHelper::createStorageRoot();
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);

        $connection = Connection::withPdo($this->pdo);
        $encryption = AttestationsTestHelper::encryption();
        $this->files = new FileRepository($this->pdo);
        $this->fileStorage = new EncryptedFileStorageService($this->files, $encryption, $this->storageRoot);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $encryption);
        $this->documents = new MemberDocumentRepository($this->pdo);

        $this->publication = new BatchPublicationService(
            $connection,
            $this->batches,
            $this->lines,
            new BatchVerificationService(
                $connection, $this->batches, $this->lines, $this->files, $this->fileStorage, $journal
            ),
            $this->documents,
            SchedulerService::forPdo($this->pdo),
            $journal
        );

        $this->service = new BatchResetService(
            $connection,
            $this->batches,
            $this->lines,
            $this->documents,
            $this->fileStorage,
            $journal
        );

        $this->seedBatch();
    }

    protected function tearDown(): void
    {
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    private function seedBatch(): void
    {
        foreach (['margaux' => ['Margaux', 'Vandenbrande'], 'sacha' => ['Sacha', 'Meunier']] as $key => [$first, $last]) {
            $this->memberIds[$key] = AttestationsTestHelper::createMember(
                $this->pdo, $this->scoutYearId, $first, $last
            );
        }

        $this->batchId = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Tax, 'Attestation fiscale 2025', 4, 2, 2, null
        );

        $position = 0;
        foreach (['margaux', 'sacha'] as $key) {
            $position++;
            $this->lineIds[$key] = $this->lines->create(
                $this->batchId, $position, $position * 2 - 1, $position * 2, 'NOM Prenom',
                $this->memberIds[$key], MatchState::Matched, $this->storeCertificate($this->memberIds[$key])
            );
        }
    }

    private function storeCertificate(?int $ownerMemberId): int
    {
        return $this->fileStorage->store(
            '%PDF-1.4 fake',
            'application/pdf',
            'attestation.pdf',
            'attestations/documents',
            'identified',
            'attestations',
            null,
            $ownerMemberId
        );
    }

    private function publishEverything(): void
    {
        $this->publication->publish($this->batchId, array_values($this->lineIds), null);
    }

    public function testTakingBackAPublishedBatchRemovesTheDocumentsFromTheMembersPages(): void
    {
        $this->publishEverything();

        $result = $this->service->reset($this->batchId, null);

        $this->assertSame(2, $result['documents']);
        foreach ($this->memberIds as $memberId) {
            $this->assertSame([], $this->documents->findByMemberAndYear($memberId, $this->scoutYearId));
        }
    }

    /** The rows, the certificates and the batch itself: nothing survives. */
    public function testTheBatchAndItsLinesAreGone(): void
    {
        $this->publishEverything();

        $this->service->reset($this->batchId, null);

        $this->assertNull($this->batches->findById($this->batchId));
        $this->assertSame([], $this->lines->findByBatch($this->batchId));
    }

    /**
     * A file row left behind is a certificate nothing points at, and the
     * bytes on disk are the most nominative document the site holds.
     */
    public function testTheStoredCertificatesAreDeletedRowsAndBytesAlike(): void
    {
        $fileIds = $this->lines->findFileIds($this->batchId);
        $paths = [];
        foreach ($fileIds as $fileId) {
            $record = $this->files->findById($fileId);
            $this->assertNotNull($record);
            $paths[] = $this->storageRoot . '/' . $record->relativePath;
            $this->assertFileExists($paths[count($paths) - 1]);
        }

        $this->publishEverything();
        $result = $this->service->reset($this->batchId, null);

        $this->assertSame(2, $result['certificates']);
        foreach ($fileIds as $index => $fileId) {
            $this->assertNull($this->files->findById($fileId));
            $this->assertFileDoesNotExist($paths[$index]);
        }
    }

    /**
     * The line remembers the document IT produced, which is exactly what
     * makes the reset safe: a member who also holds a certificate from
     * another batch keeps it.
     */
    public function testADocumentFromAnotherBatchIsUntouched(): void
    {
        $otherBatch = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Attendance, 'Attestation présence camp', 2, 2, 1, null
        );
        $otherLine = $this->lines->create(
            $otherBatch, 1, 1, 2, 'VANDENBRANDE Margaux',
            $this->memberIds['margaux'], MatchState::Matched, $this->storeCertificate($this->memberIds['margaux'])
        );
        $this->publication->publish($otherBatch, [$otherLine], null);

        $this->publishEverything();
        $this->service->reset($this->batchId, null);

        $remaining = $this->documents->findByMemberAndYear($this->memberIds['margaux'], $this->scoutYearId);
        $this->assertCount(1, $remaining);
        $this->assertSame('Attestation présence camp', $remaining[0]->title);
        $this->assertNotNull($this->batches->findById($otherBatch));
    }

    /**
     * A batch deposited and never published has no documents to remove, but
     * it does have cut certificates — and without this there would be no way
     * to get rid of them at all.
     */
    public function testADraftBatchIsTakenBackToo(): void
    {
        $fileIds = $this->lines->findFileIds($this->batchId);

        $result = $this->service->reset($this->batchId, null);

        $this->assertSame(['documents' => 0, 'certificates' => 2], $result);
        $this->assertNull($this->batches->findById($this->batchId));
        foreach ($fileIds as $fileId) {
            $this->assertNull($this->files->findById($fileId));
        }
    }

    public function testABatchThatIsAlreadyGoneIsRefusedRatherThanSilentlyRepeated(): void
    {
        $this->service->reset($this->batchId, null);

        $this->expectException(AttestationsException::class);
        $this->service->reset($this->batchId, null);
    }

    /** Counts and identifiers only, at a level somebody will notice. */
    public function testTheJournalRecordsTheResetWithoutNamingAnybody(): void
    {
        $this->publishEverything();

        $this->service->reset($this->batchId, 7);

        $rows = $this->pdo
            ->query("SELECT * FROM event_log WHERE event_type = 'attestation_batch_reset'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['level']);
        $this->assertStringContainsString('2 document(s) retiré(s)', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('Vandenbrande', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('Vandenbrande', (string) $rows[0]['context']);
    }
}
