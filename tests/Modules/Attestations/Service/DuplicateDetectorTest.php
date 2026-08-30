<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Core\Database\Connection;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Service\DuplicateDetector;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * « Ce membre a déjà reçu la sienne » — a warning, never a block.
 *
 * The site cannot tell whether a second certificate is a correction the
 * federation sent or a legitimate top-up, so it says what it knows and
 * leaves the decision where the information is.
 */
#[Group('database')]
class DuplicateDetectorTest extends TestCase
{
    private \PDO $pdo;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private DuplicateDetector $detector;
    private int $scoutYearId;
    private int $memberId;
    private int $otherMemberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);

        $connection = Connection::withPdo($this->pdo);
        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, AttestationsTestHelper::encryption());
        $this->detector = new DuplicateDetector($this->lines);

        $this->memberId = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande'
        );
        $this->otherMemberId = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, 'Sacha', 'Meunier'
        );
    }

    private function createFile(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['a/' . bin2hex(random_bytes(6)), 'x.pdf', 'application/pdf', 10, 'admin']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createBatch(
        AttestationCategory $category,
        string $label,
        ?int $scoutYearId = null
    ): int {
        return $this->batches->create(
            $scoutYearId ?? $this->scoutYearId,
            $category,
            $label,
            2,
            2,
            1,
            null
        );
    }

    private function publish(int $batchId, string $publishedAt = '2026-02-11 09:30:00'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE attestation_batches SET status = ?, published_at = ? WHERE id = ?'
        );
        $stmt->execute([BatchStatus::Published->value, $publishedAt, $batchId]);
    }

    private function addLine(int $batchId, int $position, ?int $memberId): int
    {
        return $this->lines->create(
            $batchId,
            $position,
            $position * 2 - 1,
            $position * 2,
            'NOM Prenom',
            $memberId,
            $memberId === null ? MatchState::Unmatched : MatchState::Matched,
            $this->createFile()
        );
    }

    public function testAMemberWhoAlreadyHasOneIsFlaggedWithTheDateAndTheOtherBatchsLabel(): void
    {
        $published = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025 — 1er envoi');
        $this->addLine($published, 1, $this->memberId);
        $this->publish($published);

        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025 — complément');
        $lineId = $this->addLine($draft, 1, $this->memberId);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $warnings = $this->detector->warningsFor($batch, $this->lines->findByBatch($draft));

        $this->assertArrayHasKey($lineId, $warnings);
        $this->assertStringContainsString('a déjà reçu une attestation fiscale', $warnings[$lineId]);
        $this->assertStringContainsString('11/02/2026', $warnings[$lineId]);
        $this->assertStringContainsString('1er envoi', $warnings[$lineId]);
    }

    /**
     * The one thing the category is for. A tax certificate and an
     * attendance certificate are two perfectly legitimate documents for the
     * same person in the same season, and warning about the second because
     * of the first would train a reader to ignore the warning.
     */
    public function testAnotherCategoryIsNotADuplicate(): void
    {
        $published = $this->createBatch(AttestationCategory::Attendance, 'Attestation présence camp 2026');
        $this->addLine($published, 1, $this->memberId);
        $this->publish($published);

        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025');
        $this->addLine($draft, 1, $this->memberId);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $this->assertSame([], $this->detector->warningsFor($batch, $this->lines->findByBatch($draft)));
    }

    public function testAnotherScoutYearIsNotADuplicate(): void
    {
        $lastYear = AttestationsTestHelper::createScoutYear($this->pdo, '2024-2025');

        $published = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2024', $lastYear);
        $this->addLine($published, 1, $this->memberId);
        $this->publish($published);

        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025');
        $this->addLine($draft, 1, $this->memberId);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $this->assertSame([], $this->detector->warningsFor($batch, $this->lines->findByBatch($draft)));
    }

    /**
     * A batch nobody has validated has given nobody anything. Warning about
     * it would make a reader untick a line for a document that does not
     * exist.
     */
    public function testADraftBatchIsNotADuplicate(): void
    {
        $otherDraft = $this->createBatch(AttestationCategory::Tax, 'Lot jamais validé');
        $this->addLine($otherDraft, 1, $this->memberId);

        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025');
        $this->addLine($draft, 1, $this->memberId);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $this->assertSame([], $this->detector->warningsFor($batch, $this->lines->findByBatch($draft)));
    }

    /**
     * The case the specification left open, decided here: the same member
     * twice in ONE deposited file is the same fact as twice across two, so
     * it gets the same warning and the same freedom to untick. Both lines
     * are flagged — the reader has to choose which one to keep.
     */
    public function testTheSameMemberTwiceInOneBatchIsFlaggedOnBothLines(): void
    {
        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025');
        $first = $this->addLine($draft, 1, $this->memberId);
        $second = $this->addLine($draft, 2, $this->memberId);
        $this->addLine($draft, 3, $this->otherMemberId);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $warnings = $this->detector->warningsFor($batch, $this->lines->findByBatch($draft));

        $this->assertArrayHasKey($first, $warnings);
        $this->assertArrayHasKey($second, $warnings);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('apparaît 2 fois dans ce lot', $warnings[$first]);
    }

    public function testALineWithNoMemberIsNeverFlagged(): void
    {
        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025');
        $this->addLine($draft, 1, null);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $this->assertSame([], $this->detector->warningsFor($batch, $this->lines->findByBatch($draft)));
    }

    /**
     * A published batch with no date read through the one door
     * (SECURITY.md §35). The constructor would have answered *now* for a
     * missing value and dated the duplicate to today.
     */
    public function testAPublishedBatchWithNoDateDoesNotClaimTodaysDate(): void
    {
        $published = $this->createBatch(AttestationCategory::Tax, 'Lot sans date');
        $this->addLine($published, 1, $this->memberId);
        $stmt = $this->pdo->prepare('UPDATE attestation_batches SET status = ? WHERE id = ?');
        $stmt->execute([BatchStatus::Published->value, $published]);

        $draft = $this->createBatch(AttestationCategory::Tax, 'Attestation fiscale 2025');
        $lineId = $this->addLine($draft, 1, $this->memberId);

        $batch = $this->batches->findById($draft);
        $this->assertNotNull($batch);

        $warnings = $this->detector->warningsFor($batch, $this->lines->findByBatch($draft));

        $this->assertArrayHasKey($lineId, $warnings);
        $this->assertStringContainsString('une date inconnue', $warnings[$lineId]);
        $this->assertStringNotContainsString(date('d/m/Y'), $warnings[$lineId]);
    }
}
