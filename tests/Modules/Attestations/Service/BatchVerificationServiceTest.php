<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Service\AttestationsException;
use Modules\Attestations\Service\BatchVerificationService;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * The two decisions the verification screen commits.
 *
 * Both are irreversible in the direction that matters — a discarded line
 * takes its bytes with it, and the original file is already gone — so the
 * server-side checks are the subject here, not the screen's own guards.
 */
#[Group('database')]
class BatchVerificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storageRoot;
    private BatchVerificationService $service;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private FileRepository $files;
    private EncryptedFileStorageService $fileStorage;
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
        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $encryption);

        $this->service = new BatchVerificationService(
            $connection,
            $this->batches,
            $this->lines,
            $this->files,
            $this->fileStorage,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->seedBatch();
    }

    protected function tearDown(): void
    {
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    /**
     * Three lines: one matched outright, one ambiguous between two
     * homonyms, one nobody carries.
     */
    private function seedBatch(): void
    {
        $this->memberIds['margaux'] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande'
        );
        $this->memberIds['zoe_a'] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Zoé', 'Herremans'
        );
        $this->memberIds['zoe_b'] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Zoé', 'Herremans'
        );
        $this->memberIds['outsider'] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Basile', 'Herremans'
        );

        $this->batchId = $this->batches->create(
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            6,
            2,
            3,
            1
        );

        $this->lineIds['matched'] = $this->lines->create(
            $this->batchId, 1, 1, 2, 'VANDENBRANDE Margaux',
            $this->memberIds['margaux'], MatchState::Matched, $this->storeCertificate($this->memberIds['margaux'])
        );
        $this->lineIds['ambiguous'] = $this->lines->create(
            $this->batchId, 2, 3, 4, 'HERREMANS Zoé',
            null, MatchState::Ambiguous, $this->storeCertificate(null),
            [$this->memberIds['zoe_a'], $this->memberIds['zoe_b']]
        );
        $this->lineIds['unmatched'] = $this->lines->create(
            $this->batchId, 3, 5, 6, 'DELACROIX Camille',
            null, MatchState::Unmatched, $this->storeCertificate(null)
        );
    }

    private function storeCertificate(?int $ownerMemberId): int
    {
        return $this->fileStorage->store(
            '%PDF-1.4 fake',
            'application/pdf',
            'attestation.pdf',
            'attestations/documents',
            'admin',
            'attestations',
            null,
            $ownerMemberId
        );
    }

    // --- assigning a member ---------------------------------------------

    public function testResolvingAnAmbiguityAttachesTheMemberAndDropsTheCandidates(): void
    {
        $this->service->assignMember($this->batchId, $this->lineIds['ambiguous'], $this->memberIds['zoe_b']);

        $line = $this->lines->findById($this->lineIds['ambiguous']);

        $this->assertNotNull($line);
        $this->assertSame($this->memberIds['zoe_b'], $line->memberId);
        $this->assertSame(MatchState::Matched, $line->state);
        $this->assertSame([], $line->candidateMemberIds);
        $this->assertTrue($line->isSelected);
    }

    /**
     * The certificate becomes readable by its family the moment the line
     * has one — not later, at publication.
     */
    public function testResolvingALineOwnerScopesItsCertificate(): void
    {
        $line = $this->lines->findById($this->lineIds['ambiguous']);
        $this->assertNotNull($line);
        $this->assertNull($this->files->findById($line->fileId)?->ownerMemberId);

        $this->service->assignMember($this->batchId, $this->lineIds['ambiguous'], $this->memberIds['zoe_a']);

        $this->assertSame(
            $this->memberIds['zoe_a'],
            $this->files->findById($line->fileId)?->ownerMemberId
        );
    }

    /**
     * The check that keeps a certificate out of the wrong family's hands.
     * A member id in a request body is a request, never an authority: this
     * one is a real member, of the same surname, and was simply never
     * offered for this line.
     */
    public function testAMemberWhoWasNeverACandidateIsRefused(): void
    {
        try {
            $this->service->assignMember($this->batchId, $this->lineIds['ambiguous'], $this->memberIds['outsider']);
            $this->fail('A member who was never a candidate must be refused.');
        } catch (AttestationsException $e) {
            $this->assertStringContainsString('proposé', $e->getMessage());
        }

        $this->assertNull($this->lines->findById($this->lineIds['ambiguous'])?->memberId);
    }

    /** An unmatched line has no candidates at all, so nothing can be assigned to it. */
    public function testAnUnmatchedLineTakesNobody(): void
    {
        $this->expectException(AttestationsException::class);
        $this->service->assignMember($this->batchId, $this->lineIds['unmatched'], $this->memberIds['margaux']);
    }

    public function testALineOfAnotherBatchIsRefused(): void
    {
        $otherBatch = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Other, 'Autre lot', 2, 2, 1, null
        );

        $this->expectException(AttestationsException::class);
        $this->service->assignMember($otherBatch, $this->lineIds['ambiguous'], $this->memberIds['zoe_a']);
    }

    // --- committing the selection ---------------------------------------

    public function testUncheckedLinesAreDeletedRowsAndBytesAlike(): void
    {
        $discardedLine = $this->lines->findById($this->lineIds['unmatched']);
        $this->assertNotNull($discardedLine);
        $keptRecord = $this->files->findById($discardedLine->fileId);
        $this->assertNotNull($keptRecord);
        $onDisk = $this->storageRoot . '/' . $keptRecord->relativePath;
        $this->assertFileExists($onDisk);

        $discarded = $this->service->commitSelection($this->batchId, [$this->lineIds['matched']]);

        $this->assertSame(2, $discarded);
        $this->assertCount(1, $this->lines->findByBatch($this->batchId));
        $this->assertNull($this->files->findById($discardedLine->fileId));
        $this->assertFileDoesNotExist($onDisk);
    }

    /**
     * A counter, never a list of names — that is the whole point of keeping
     * it: it answers « pourquoi 43 attestations pour 55 membres ? » without
     * keeping who the 12 were.
     */
    public function testTheBatchKeepsTheCountOfWhatWasDiscarded(): void
    {
        $this->service->commitSelection($this->batchId, [$this->lineIds['matched']]);

        $batch = $this->batches->findById($this->batchId);

        $this->assertNotNull($batch);
        $this->assertSame(1, $batch->documentCount);
        $this->assertSame(2, $batch->discardedCount);
    }

    /**
     * An unresolved line is left aside rather than blocking the whole
     * batch: a chef d'unité must not be stuck on a name Desk does not hold.
     * It goes with the rest, and the screen says so before they press.
     */
    public function testAnUnresolvedLineIsDiscardedRatherThanBlocking(): void
    {
        $discarded = $this->service->commitSelection(
            $this->batchId,
            [$this->lineIds['matched'], $this->lineIds['unmatched']]
        );

        // Two: the ambiguous one nobody ticked, and the unmatched one the
        // form tried to tick and the server refused.
        $this->assertSame(2, $discarded);

        $remaining = $this->lines->findByBatch($this->batchId);
        $this->assertCount(1, $remaining);
        $this->assertSame($this->lineIds['matched'], $remaining[0]->id);
    }

    /**
     * The browser only decides whether a box LOOKS tickable. A crafted body
     * naming an unresolved line must not make it distributable.
     */
    public function testTheServerRefusesToTickALineWithNoMember(): void
    {
        $this->lines->applySelection($this->batchId, [$this->lineIds['unmatched']]);

        $line = $this->lines->findById($this->lineIds['unmatched']);
        $this->assertNotNull($line);
        $this->assertFalse($line->isSelected);
    }

    public function testCommittingTwiceOnAnAlreadyCommittedBatchIsHarmless(): void
    {
        $this->service->commitSelection($this->batchId, [$this->lineIds['matched']]);
        $second = $this->service->commitSelection($this->batchId, [$this->lineIds['matched']]);

        $this->assertSame(0, $second);
        $this->assertCount(1, $this->lines->findByBatch($this->batchId));
    }

    public function testAnUnknownBatchIsRefused(): void
    {
        $this->expectException(AttestationsException::class);
        $this->service->commitSelection(4242, []);
    }

    public function testTheJournalRecordsCountsAndNoName(): void
    {
        $this->service->commitSelection($this->batchId, [$this->lineIds['matched']]);

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'attestation_batch_selection_committed'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('1 attestations retenues, 2 écartées', (string) $row['description']);
        $this->assertStringNotContainsString('Vandenbrande', (string) $row['context']);
    }
}
