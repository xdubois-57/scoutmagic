<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Repository;

use Core\Database\Connection;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

#[Group('database')]
class BatchRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BatchRepository $repository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->repository = new BatchRepository(Connection::withPdo($this->pdo));
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);
    }

    public function testABatchIsStoredAndReadBack(): void
    {
        $id = $this->repository->create(
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            10,
            2,
            5,
            42
        );

        $batch = $this->repository->findById($id);

        $this->assertNotNull($batch);
        $this->assertSame($this->scoutYearId, $batch->scoutYearId);
        $this->assertSame(AttestationCategory::Tax, $batch->category);
        $this->assertSame('Attestation fiscale 2025', $batch->label);
        $this->assertSame(10, $batch->pageCount);
        $this->assertSame(2, $batch->pagesPerDocument);
        $this->assertSame(5, $batch->documentCount);
        $this->assertSame(42, $batch->createdBy);
    }

    /**
     * Publishing is what creates the documents, and that is a second step
     * by design — there is no way to insert a batch that is already
     * published.
     */
    public function testANewBatchIsAlwaysADraft(): void
    {
        $id = $this->repository->create($this->scoutYearId, AttestationCategory::Attendance, 'Camp 2026', 4, 1, 4, null);
        $batch = $this->repository->findById($id);

        $this->assertNotNull($batch);
        $this->assertSame(BatchStatus::Draft, $batch->status);
        $this->assertFalse($batch->isPublished());
        $this->assertNull($batch->publishedAt);
        $this->assertSame(0, $batch->discardedCount);
    }

    /**
     * The timestamp is written from PHP and bound as a parameter, never
     * left to the column default: SQLite's CURRENT_TIMESTAMP is UTC while
     * this application runs on Europe/Brussels, and the two get compared
     * against each other (docs/module-development.md § Timestamps).
     */
    public function testTheCreationTimestampIsOnTheApplicationClock(): void
    {
        $before = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repository->create($this->scoutYearId, AttestationCategory::Tax, 'Lot', 2, 2, 1, null);
        $after = (new \DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s');

        $batch = $this->repository->findById($id);

        $this->assertNotNull($batch);
        $this->assertGreaterThanOrEqual($before, $batch->createdAt);
        $this->assertLessThanOrEqual($after, $batch->createdAt);
    }

    public function testTheListingPutsTheMostRecentlyDepositedFirst(): void
    {
        $first = $this->repository->create($this->scoutYearId, AttestationCategory::Tax, 'Premier', 2, 2, 1, null);
        $second = $this->repository->create($this->scoutYearId, AttestationCategory::Other, 'Second', 2, 2, 1, null);

        $ids = array_map(static fn($b): int => $b->id, $this->repository->findRecent());

        // Same second, so the id tiebreaker is what decides — which is the
        // point of ordering on both.
        $this->assertSame([$second, $first], $ids);
    }

    public function testTheListingIsBounded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->create($this->scoutYearId, AttestationCategory::Tax, 'Lot ' . $i, 2, 2, 1, null);
        }

        $this->assertCount(3, $this->repository->findRecent(3));
    }

    public function testAnUnknownBatchIsNull(): void
    {
        $this->assertNull($this->repository->findById(4242));
    }

    /**
     * A stored value naming no known category would be a row this code
     * never wrote. Reading it as « Autre » keeps the page rendering rather
     * than fataling on somebody else's data — and never silently files it
     * under the heading the coverage screen counts.
     */
    public function testAnUnknownStoredCategoryReadsAsOtherRatherThanFataling(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attestation_batches (scout_year_id, category, label, status, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->scoutYearId, 'zorglub', 'Lot inconnu', 'draft', '2026-02-11 10:00:00']);
        $id = (int) $this->pdo->lastInsertId();

        $batch = $this->repository->findById($id);

        $this->assertNotNull($batch);
        $this->assertSame(AttestationCategory::Other, $batch->category);
    }
}
