<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Scheduler\SchedulerRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SchedulerRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
    }

    public function testCreateCreatesAction(): void
    {
        $runAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'test_task', $runAt, null, null);

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->repo->countAll());
    }

    public function testCreatePersistsRequestedByUserAccountId(): void
    {
        $runAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'test_task', $runAt, null, null, 7);

        $row = $this->repo->findById($id);
        $this->assertSame(7, (int) $row['requested_by_user_account_id']);
    }

    public function testCreateDefaultsRequestedByUserAccountIdToNull(): void
    {
        $runAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'test_task', $runAt, null, null);

        $row = $this->repo->findById($id);
        $this->assertNull($row['requested_by_user_account_id']);
    }

    /**
     * run_at is written from PHP, exactly the way SchedulerRepository::
     * create() and every real caller writes it — never SQLite's own
     * datetime('now'), which is UTC and has no session timezone to align
     * (Core\Config\AppClock). Seeding with SQL time and asserting against
     * claimOverdue()'s PHP-computed "now" compares two different clocks:
     * an event an hour in the future read as already due.
     */
    private function insertAction(string $taskKey, string $modifier): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status)
             VALUES ('core', ?, ?, 'pending')"
        );
        $stmt->execute([$taskKey, (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s')]);
    }

    public function testClaimOverdueReturnsOnlyDue(): void
    {
        $this->insertAction('due_task', '-1 minute');
        $this->insertAction('future_task', '+1 hour');

        $due = $this->repo->claimOverdue();
        $this->assertCount(1, $due);
        $this->assertSame('due_task', $due[0]['task_key']);
    }

    public function testClaimOverdueMovesRowsToProcessing(): void
    {
        $this->insertAction('due_task', '-1 minute');

        $due = $this->repo->claimOverdue();

        $this->assertSame('processing', $due[0]['status']);
    }

    /**
     * The bug this guards: claimOverdue() used to run a blanket
     * "SET status = processing WHERE status = pending" UPDATE and then
     * re-SELECT *every* row currently 'processing' — which would hand the
     * same row back to a second caller too, since nothing distinguished
     * "processing because I just claimed it" from "processing because an
     * earlier claimOverdue() call already did". Two callers could then
     * both run the same task's handler concurrently (e.g. two overlapping
     * Task\InstallUpdateHandler runs both copying files over the live
     * install at once). A second claimOverdue() call, simulating a second
     * concurrent caller arriving after the first already claimed the row,
     * must come back empty.
     */
    public function testClaimOverdueNeverReturnsARowAlreadyClaimedByAnEarlierCall(): void
    {
        $this->insertAction('due_task', '-1 minute');

        $firstCaller = $this->repo->claimOverdue();
        $secondCaller = $this->repo->claimOverdue();

        $this->assertCount(1, $firstCaller);
        $this->assertCount(0, $secondCaller);
    }

    public function testCountAllReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->repo->countAll());
    }

    public function testFindByModuleAndTaskKeyReturnsAllStatusesNewestFirst(): void
    {
        $this->repo->create('sos_staff', 'apply_redirect', '2026-01-05 10:00:00', null, '2026-01-05');
        $id = $this->repo->create('sos_staff', 'apply_redirect', '2026-01-10 10:00:00', null, '2026-01-10');
        $this->repo->markDone($id);
        $this->repo->create('other_module', 'apply_redirect', '2026-01-07 10:00:00', null, '2026-01-07');

        $rows = $this->repo->findByModuleAndTaskKey('sos_staff', 'apply_redirect');

        $this->assertCount(2, $rows);
        $this->assertSame('2026-01-10 10:00:00', $rows[0]['run_at']);
        $this->assertSame('done', $rows[0]['status']);
        $this->assertSame('2026-01-05 10:00:00', $rows[1]['run_at']);
    }

    public function testFindByModuleAndTaskKeyRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repo->create('sos_staff', 'apply_redirect', "2026-01-0{$i} 10:00:00", null, "2026-01-0{$i}");
        }

        $rows = $this->repo->findByModuleAndTaskKey('sos_staff', 'apply_redirect', 2);

        $this->assertCount(2, $rows);
    }

    public function testDeleteOlderThanRemovesOnlyOldRowsForThatModuleAndTask(): void
    {
        $this->repo->create('sos_staff', 'apply_redirect', '2024-01-01 10:00:00', null, '2024-01-01');
        $this->repo->create('sos_staff', 'apply_redirect', '2026-07-01 10:00:00', null, '2026-07-01');
        $this->repo->create('other_module', 'apply_redirect', '2024-01-01 10:00:00', null, '2024-01-01');

        $deleted = $this->repo->deleteOlderThan('sos_staff', 'apply_redirect', '2025-01-01 00:00:00');

        $this->assertSame(1, $deleted);
        $remaining = $this->repo->findByModuleAndTaskKey('sos_staff', 'apply_redirect');
        $this->assertCount(1, $remaining);
        $this->assertSame('2026-07-01 10:00:00', $remaining[0]['run_at']);
        // Untouched: different module.
        $this->assertCount(1, $this->repo->findByModuleAndTaskKey('other_module', 'apply_redirect'));
    }

    public function testDeleteByTaskKeyRemovesEveryRowOfThatTaskAndLeavesOtherModulesAlone(): void
    {
        // Retiring a task: without this, a scheduled row pointing at a
        // handler that no longer exists resolves to nothing on every tick,
        // forever — not fatal, and not visible either, which is the worse
        // half of it.
        $this->repo->create('camps', 'purge_unsorted_mail', '2026-07-01 10:00:00', null, null);
        $this->repo->create('camps', 'purge_unsorted_mail', '2026-08-01 10:00:00', null, null);
        $this->repo->create('camps', 'other_task', '2026-08-01 10:00:00', null, null);
        $this->repo->create('other_module', 'purge_unsorted_mail', '2026-08-01 10:00:00', null, null);

        $deleted = $this->repo->deleteByTaskKey('camps', 'purge_unsorted_mail');

        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->repo->findByModuleAndTaskKey('camps', 'purge_unsorted_mail'));
        $this->assertCount(1, $this->repo->findByModuleAndTaskKey('camps', 'other_task'));
        $this->assertCount(1, $this->repo->findByModuleAndTaskKey('other_module', 'purge_unsorted_mail'));
    }

    public function testRetiringATaskNobodyScheduledIsNotAnError(): void
    {
        $this->assertSame(0, $this->repo->deleteByTaskKey('camps', 'jamais_planifiee'));
    }
}
