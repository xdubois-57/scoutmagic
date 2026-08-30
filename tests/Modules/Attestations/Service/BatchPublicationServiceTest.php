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
use Modules\Attestations\Service\BatchVerificationService;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * Publishing a batch, and asking for it to go out.
 *
 * Two gestures and deliberately not one: a certificate has a short window
 * of use, so the screen says « familles non prévenues » until somebody
 * presses — and the send itself never happens inside the request that asked
 * for it.
 */
#[Group('database')]
class BatchPublicationServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storageRoot;
    private BatchPublicationService $service;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private MemberDocumentRepository $documents;
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
        $files = new FileRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService($files, $encryption, $this->storageRoot);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $encryption);
        $this->documents = new MemberDocumentRepository($this->pdo);

        $this->service = new BatchPublicationService(
            $connection,
            $this->batches,
            $this->lines,
            new BatchVerificationService($connection, $this->batches, $this->lines, $files, $fileStorage, $journal),
            $this->documents,
            SchedulerService::forPdo($this->pdo),
            $journal
        );

        $this->seedBatch($fileStorage);
    }

    protected function tearDown(): void
    {
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    private function seedBatch(EncryptedFileStorageService $fileStorage): void
    {
        $this->memberIds['margaux'] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande'
        );
        $this->memberIds['sacha'] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Sacha', 'Meunier'
        );

        $this->batchId = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Tax, 'Attestation fiscale 2025', 6, 2, 3, 1
        );

        $store = fn(?int $owner): int => $fileStorage->store(
            '%PDF-1.4 fake',
            'application/pdf',
            'attestation.pdf',
            'attestations/documents',
            'admin',
            'attestations',
            null,
            $owner
        );

        $this->lineIds['margaux'] = $this->lines->create(
            $this->batchId, 1, 1, 2, 'VANDENBRANDE Margaux',
            $this->memberIds['margaux'], MatchState::Matched, $store($this->memberIds['margaux'])
        );
        $this->lineIds['sacha'] = $this->lines->create(
            $this->batchId, 2, 3, 4, 'MEUNIER Sacha',
            $this->memberIds['sacha'], MatchState::Matched, $store($this->memberIds['sacha'])
        );
        $this->lineIds['unmatched'] = $this->lines->create(
            $this->batchId, 3, 5, 6, 'DELACROIX Camille',
            null, MatchState::Unmatched, $store(null)
        );
    }

    // --- publishing -----------------------------------------------------

    public function testPublishingPutsOneDocumentOnEachKeptMembersPage(): void
    {
        $result = $this->service->publish(
            $this->batchId,
            [$this->lineIds['margaux'], $this->lineIds['sacha']],
            9
        );

        $this->assertSame(['published' => 2, 'discarded' => 1], $result);

        foreach (['margaux', 'sacha'] as $key) {
            $documents = $this->documents->findByMemberAndYear($this->memberIds[$key], $this->scoutYearId);
            $this->assertCount(1, $documents, $key);
            $this->assertSame('Attestation fiscale 2025', $documents[0]->title);
        }
    }

    /**
     * The document points at the certificate that was already cut and
     * already owner-scoped — publication creates no second file.
     */
    public function testTheDocumentPointsAtTheCertificateThatAlreadyExisted(): void
    {
        $lineBefore = $this->lines->findById($this->lineIds['margaux']);
        $this->assertNotNull($lineBefore);

        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);

        $documents = $this->documents->findByMemberAndYear($this->memberIds['margaux'], $this->scoutYearId);
        $this->assertSame($lineBefore->fileId, $documents[0]->fileId);
    }

    /**
     * The line remembers what it created, which is what makes the batch
     * reversible: taking it back deletes exactly these rows and nothing
     * else.
     */
    public function testEachLineRemembersTheDocumentItProduced(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);

        $line = $this->lines->findById($this->lineIds['margaux']);
        $this->assertNotNull($line);
        $this->assertNotNull($line->memberDocumentId);
        $this->assertTrue($line->isPublished());
    }

    public function testPublishingMarksTheBatchAndStampsTheMoment(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);

        $batch = $this->batches->findById($this->batchId);
        $this->assertNotNull($batch);
        $this->assertSame(BatchStatus::Published, $batch->status);
        $this->assertNotNull($batch->publishedAt);
        $this->assertTrue($batch->awaitsDistribution());
    }

    /** The unchecked lines are gone before anything is published. */
    public function testTheUncheckedLinesAreGone(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);

        $remaining = $this->lines->findByBatch($this->batchId);
        $this->assertCount(1, $remaining);
        $this->assertSame($this->lineIds['margaux'], $remaining[0]->id);
    }

    public function testAPublishedBatchCannotBePublishedAgain(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);

        $this->expectException(AttestationsException::class);
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);
    }

    // --- asking for the send --------------------------------------------

    /**
     * Never automatic, and never inside the request: a batch of two hundred
     * is two hundred SMTP round trips.
     */
    public function testAskingForTheSendQueuesAScheduledTaskCarryingTheBatch(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);
        $this->service->startDistribution($this->batchId, 7);

        $row = $this->pdo->query(
            "SELECT module_id, task_key, payload, reference FROM scheduled_actions"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('attestations', $row['module_id']);
        $this->assertSame('send_batch', $row['task_key']);
        $this->assertSame((string) $this->batchId, (string) $row['reference']);
        $this->assertSame(['batch_id' => $this->batchId], json_decode((string) $row['payload'], true));
    }

    /**
     * The stamp goes on when the gesture is made, not when the last message
     * leaves — otherwise the screen keeps saying « familles non prévenues »
     * and somebody presses again, and the families get two copies.
     */
    public function testTheScreenStopsAskingAsSoonAsTheGestureIsMade(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);
        $this->service->startDistribution($this->batchId, null);

        $batch = $this->batches->findById($this->batchId);
        $this->assertNotNull($batch);
        $this->assertFalse($batch->awaitsDistribution());
        $this->assertTrue($batch->isDistributing());
    }

    public function testAskingTwiceIsRefused(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux']], null);
        $this->service->startDistribution($this->batchId, null);

        try {
            $this->service->startDistribution($this->batchId, null);
            $this->fail('A second send must be refused.');
        } catch (AttestationsException $e) {
            $this->assertStringContainsString('déjà été lancé', $e->getMessage());
        }

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn());
    }

    public function testABatchThatWasNeverPublishedHasNothingToSend(): void
    {
        $this->expectException(AttestationsException::class);
        $this->service->startDistribution($this->batchId, null);
    }

    public function testTheJournalRecordsCountsAndNoName(): void
    {
        $this->service->publish($this->batchId, [$this->lineIds['margaux'], $this->lineIds['sacha']], null);

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'attestation_batch_published'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('2 attestations publiées, 1 écartées', (string) $row['description']);
        $this->assertStringNotContainsString('Vandenbrande', (string) $row['context']);
        $this->assertStringNotContainsString('Margaux', (string) $row['description']);
    }
}
