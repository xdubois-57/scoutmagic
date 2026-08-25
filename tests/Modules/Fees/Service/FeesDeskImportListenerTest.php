<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\DeskImportListener;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Fees\Repository\RosterSnapshotRepository;
use Modules\Fees\Service\FeesDeskImportListener;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FeesDeskImportListenerTest extends TestCase
{
    private \PDO $pdo;
    private FeesDeskImportListener $listener;
    private RosterSnapshotRepository $repository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->repository = new RosterSnapshotRepository($this->pdo);
        $this->listener = new FeesDeskImportListener($this->repository, new JournalService(new JournalRepository($this->pdo)));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    /** @return int members.id */
    private function createMember(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc']);

        return $memberId;
    }

    public function testItIsAnImportListener(): void
    {
        $this->assertInstanceOf(DeskImportListener::class, $this->listener);
    }

    public function testEveryImportLeavesASnapshotBehind(): void
    {
        $memberIds = [$this->createMember(), $this->createMember()];

        $this->listener->onDeskImportCompleted($this->scoutYearId, $memberIds);

        $snapshot = $this->repository->findLatestForYear($this->scoutYearId);
        $this->assertNotNull($snapshot);
        $this->assertSame(2, $snapshot->memberCount);
        $this->assertCount(2, $this->repository->findMembers($snapshot->id));
    }

    /**
     * SECURITY.md §11: the journal records a count and ids, never who was
     * on the roster.
     */
    public function testTheJournalRecordsACountAndNoIdentity(): void
    {
        $this->createMember();

        $this->listener->onDeskImportCompleted($this->scoutYearId, [1]);

        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'fees_roster_snapshot_taken'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('info', $row['level']);
        $this->assertSame('fees', $row['category']);
        $this->assertStringContainsString('1 membre(s)', (string) $row['description']);

        $context = json_decode((string) $row['context'], true);
        $this->assertIsArray($context);
        $this->assertSame(['snapshot_id', 'scout_year_id', 'count'], array_keys($context));
        $this->assertSame(1, $context['count']);
    }

    /**
     * The listener runs inside the import's own transaction, so an import
     * that rolls back must leave no snapshot behind — the alternative is a
     * frozen composition of a roster that was never committed.
     */
    public function testASnapshotTakenInsideARolledBackImportDoesNotSurvive(): void
    {
        $this->createMember();

        $this->pdo->beginTransaction();
        $this->listener->onDeskImportCompleted($this->scoutYearId, [1]);
        $this->pdo->rollBack();

        $this->assertSame(0, $this->repository->countForYear($this->scoutYearId));
    }

    /**
     * Two imports the same day are two snapshots, not one overwritten: an
     * invoice is checked against the composition as it stood, and rewriting
     * an earlier snapshot would destroy exactly that.
     */
    public function testTwoImportsLeaveTwoSnapshots(): void
    {
        $this->createMember();
        $this->listener->onDeskImportCompleted($this->scoutYearId, [1]);
        $this->createMember();
        $this->listener->onDeskImportCompleted($this->scoutYearId, [1, 2]);

        $this->assertSame(2, $this->repository->countForYear($this->scoutYearId));
        $snapshots = $this->repository->findAllForYear($this->scoutYearId);
        $this->assertSame([2, 1], array_map(static fn($s): int => $s->memberCount, $snapshots));
    }
}
