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
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\AttestationPdfReader;
use Modules\Attestations\Service\AttestationPdfSplitter;
use Modules\Attestations\Service\BatchDepositService;
use Modules\Attestations\Service\PageCountMismatchException;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * Depositing the golden fixture, end to end: read, cut, store, write.
 *
 * The properties asserted here are the ones a batch cannot be wrong about.
 * Each piece holds ITS pages; a piece with a member is owner-scoped from the
 * moment it exists; a refusal leaves nothing at all behind.
 */
#[Group('database')]
class BatchDepositServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storageRoot;
    private BatchDepositService $service;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private FileRepository $files;
    private EncryptedFileStorageService $fileStorage;
    private int $scoutYearId;

    /** @var array<string, int> surname => members.id */
    private array $memberIds = [];

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

        $this->service = new BatchDepositService(
            $connection,
            $this->batches,
            $this->lines,
            new MemberNameRepository($connection, $encryption),
            new AttestationPdfReader(),
            new AttestationPdfSplitter(),
            $this->fileStorage,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->seedRoster();
    }

    protected function tearDown(): void
    {
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    /** The unit the golden fixture assumes: four names known, one to two people. */
    private function seedRoster(): void
    {
        $scout = AttestationsTestHelper::createFunction($this->pdo, 'SCOUT', 'Scout');
        $leader = AttestationsTestHelper::createFunction($this->pdo, 'ANIM', 'Animateur', 'chief');

        $this->memberIds['vandenbrande'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande', $scout
        );
        $this->memberIds['meunier'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Sacha', 'Meunier', $scout
        );
        $this->memberIds['roskam'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Timéo', 'Roskam', $leader
        );
        $this->memberIds['herremans_a'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Zoé', 'Herremans', $scout
        );
        $this->memberIds['herremans_b'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Zoé', 'Herremans', $scout
        );
        // « Camille Delacroix » is deliberately unknown to the site.
    }

    private function deposit(?string $path = null): int
    {
        return $this->service->deposit(
            $path ?? AttestationsTestHelper::goldenFixturePath(),
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            7
        );
    }

    public function testTheBatchRecordsWhatWasReadOffTheFile(): void
    {
        $batch = $this->batches->findById($this->deposit());

        $this->assertNotNull($batch);
        $this->assertSame(10, $batch->pageCount);
        $this->assertSame(2, $batch->pagesPerDocument);
        $this->assertSame(5, $batch->documentCount);
        $this->assertSame(AttestationCategory::Tax, $batch->category);
        $this->assertSame(7, $batch->createdBy);
    }

    public function testOneLinePerCertificateInPageOrder(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());

        $this->assertCount(5, $lines);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn($l): int => $l->position, $lines));
        $this->assertSame(
            ['1–2', '3–4', '5–6', '7–8', '9–10'],
            array_map(static fn($l): string => $l->pageRangeLabel(), $lines)
        );
    }

    public function testEachLineCarriesTheNameAsPrinted(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());

        $this->assertSame('VANDENBRANDE Margaux', $lines[0]->readName);
        $this->assertSame('Camille DELACROIX', $lines[4]->readName);
    }

    /**
     * The name is a natural person's and is stored as a BLOB, decrypted
     * only in the repository (SECURITY.md §5). Reading the column directly
     * must therefore never give the name back.
     */
    public function testThePrintedNameIsEncryptedAtRest(): void
    {
        $this->deposit();

        $stored = $this->pdo->query('SELECT read_name_encrypted FROM attestation_batch_lines')
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($stored as $value) {
            $this->assertIsString($value);
            $this->assertStringNotContainsString('VANDENBRANDE', $value);
            $this->assertStringNotContainsString('Margaux', $value);
        }
    }

    public function testTheThreeMatchStatesAreRecorded(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());

        $this->assertSame(MatchState::Matched, $lines[0]->state);
        $this->assertSame($this->memberIds['vandenbrande'], $lines[0]->memberId);

        $this->assertSame(MatchState::Ambiguous, $lines[3]->state);
        $this->assertNull($lines[3]->memberId);
        $this->assertSame(
            [$this->memberIds['herremans_a'], $this->memberIds['herremans_b']],
            $lines[3]->candidateMemberIds
        );

        $this->assertSame(MatchState::Unmatched, $lines[4]->state);
        $this->assertNull($lines[4]->memberId);
        $this->assertSame([], $lines[4]->candidateMemberIds);
    }

    /** A line with no destination is never offered as one that will be distributed. */
    public function testALineWithNoMemberIsNotSelected(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());

        $this->assertTrue($lines[0]->isSelected);
        $this->assertFalse($lines[3]->isSelected);
        $this->assertFalse($lines[4]->isSelected);
    }

    /**
     * The property this whole module exists for: each stored piece holds
     * ITS pages and nobody else's.
     */
    public function testEachStoredCertificateHoldsOnlyItsOwnPages(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());
        $surnames = ['VANDENBRANDE', 'MEUNIER', 'ROSKAM', 'HERREMANS', 'DELACROIX'];

        foreach ($lines as $index => $line) {
            $text = (new Parser())->parseContent($this->fileStorage->retrieve($line->fileId))->getText();

            $this->assertStringContainsString($surnames[$index], $text);
            foreach ($surnames as $otherIndex => $other) {
                if ($otherIndex !== $index) {
                    $this->assertStringNotContainsString($other, $text);
                }
            }
        }
    }

    /**
     * Owner-scoped from the instant it exists, not from the instant
     * somebody publishes it. A piece with no member yet is owned by nobody,
     * which the fail-closed guard reads as "reachable by nobody"
     * (ARCHITECTURE.md §8.3) — the safe direction while a decision is
     * pending.
     */
    public function testAMatchedCertificateIsOwnerScopedAndAnUnresolvedOneIsNot(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());

        $matched = $this->files->findById($lines[0]->fileId);
        $this->assertNotNull($matched);
        $this->assertSame($this->memberIds['vandenbrande'], $matched->ownerMemberId);
        $this->assertTrue($matched->encrypted);
        // `identified`, not `admin`: the guard wants the role floor AND
        // the ownership match, so an admin floor would lock out the family
        // the certificate belongs to and grant the staff nothing.
        $this->assertSame('identified', $matched->roleMin);

        $ambiguous = $this->files->findById($lines[3]->fileId);
        $this->assertNotNull($ambiguous);
        $this->assertNull($ambiguous->ownerMemberId);
    }

    /** Never written to disk in plaintext — these are the most nominative documents the site holds. */
    public function testTheStoredBytesAreNotAReadablePdf(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());
        $record = $this->files->findById($lines[0]->fileId);

        $this->assertNotNull($record);
        $onDisk = (string) file_get_contents($this->storageRoot . '/' . $record->relativePath);

        $this->assertStringStartsNotWith('%PDF', $onDisk);
        $this->assertStringNotContainsString('VANDENBRANDE', $onDisk);
    }

    /** The file name travels; it carries the batch's label and nobody's name. */
    public function testTheStoredFileNameNamesNobody(): void
    {
        $lines = $this->lines->findByBatch($this->deposit());
        $record = $this->files->findById($lines[0]->fileId);

        $this->assertNotNull($record);
        $this->assertSame('attestation-fiscale-2025-1.pdf', $record->originalName);
    }

    /**
     * The guard rail, from the outside: a refusal produces no batch, no
     * line and — the one that would be invisible — no orphan file on disk.
     */
    public function testARefusedFileLeavesNothingBehind(): void
    {
        $pages = [];
        for ($i = 0; $i < 3; $i++) {
            $pages[] = ['ATTESTATION FISCALE', 'VANDENBRANDE Margaux'];
            $pages[] = ['ANNEXE', 'suite'];
        }
        $pages[] = ['ATTESTATION FISCALE', 'orpheline'];

        $path = AttestationsTestHelper::writeTemporaryPdf($pages);

        try {
            $this->expectException(PageCountMismatchException::class);
            $this->deposit($path);
        } finally {
            @unlink($path);

            $this->assertSame([], $this->batches->findRecent());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
            $this->assertSame([], glob($this->storageRoot . '/attestations/documents/*') ?: []);
        }
    }

    /**
     * The failure that would otherwise be invisible: the cut succeeded, the
     * pieces are on disk, and the database write then failed. The batch and
     * its lines go with the transaction — the certificates must go too, or
     * the disk keeps nominative documents nothing points at.
     *
     * The line table is dropped to make the write fail deterministically,
     * which is what a schema half-migrated, a full disk or a lost
     * connection would do at that exact point.
     */
    public function testAFailedDatabaseWriteTakesTheAlreadyCutCertificatesWithIt(): void
    {
        $this->pdo->exec('DROP TABLE attestation_batch_lines');

        try {
            $this->deposit();
            $this->fail('The deposit must fail once its lines cannot be written.');
        } catch (\Throwable) {
            // The exception itself is the database's; what matters is what
            // it left behind.
        }

        $this->assertSame([], $this->batches->findRecent());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertSame([], glob($this->storageRoot . '/attestations/documents/*') ?: []);
    }

    /** Counters, never names: the journal must not become a copy of the roster. */
    public function testTheJournalEntryCarriesCountsAndNoName(): void
    {
        $this->deposit();

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'attestation_batch_created'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('5 attestations', (string) $row['description']);
        $this->assertStringNotContainsString('Vandenbrande', (string) $row['description']);
        $this->assertStringNotContainsString('Vandenbrande', (string) $row['context']);
        $this->assertStringNotContainsString('Margaux', (string) $row['context']);
    }
}
