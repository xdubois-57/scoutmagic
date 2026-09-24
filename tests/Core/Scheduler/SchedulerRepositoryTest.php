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

    /**
     * Claim a row and then pretend the process that claimed it died
     * `$hoursAgo` hours ago, by writing the claim stamp a handler would
     * have left behind.
     *
     * `$claimedAt === null` reproduces a row claimed before `claimed_at`
     * existed: that is what every installation's stranded rows look like
     * the moment this migration lands, and it is the case the fallback on
     * `run_at` is for.
     */
    private function claimAndStrand(string $taskKey, string $runAt, ?string $claimedAt): int
    {
        $this->insertAction($taskKey, $runAt);
        $claimed = $this->repo->claimOverdue();
        $id = (int) $claimed[array_key_last($claimed)]['id'];

        $stmt = $this->pdo->prepare('UPDATE scheduled_actions SET claimed_at = ? WHERE id = ?');
        $stmt->execute([
            $claimedAt === null ? null : (new \DateTimeImmutable($claimedAt))->format('Y-m-d H:i:s'),
            $id,
        ]);

        return $id;
    }

    /**
     * The failure this closes: a handler killed outright — OOM, a shared
     * host's max_execution_time, a power cut — takes none of the three
     * ways out of a claim, so its row stays 'processing' forever.
     * `hasLive()` counts that row as a live chain, `SchedulerService::
     * seed()` therefore never re-arms, and the task stops happening with
     * nothing logged and nothing failing.
     */
    public function testARowHeldSinceLongerThanAnyRealTaskIsHandedBack(): void
    {
        $id = $this->claimAndStrand('abandoned_task', '-1 minute', '-12 hours');

        $reclaimed = $this->repo->reclaimAbandoned();

        $this->assertCount(1, $reclaimed);
        $this->assertSame($id, (int) $reclaimed[0]['id']);
        $this->assertSame('abandoned_task', $reclaimed[0]['task_key']);

        $row = $this->repo->findById($id);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['claimed_at'], 'a row handed back carries no claim');
    }

    /**
     * The other direction, and the one that matters more: a full backup or
     * an update install legitimately holds its row across several passes,
     * and reclaiming it would start a SECOND copy beside the first — two
     * processes copying an extracted archive over the live install at
     * once. The threshold is set from that end.
     */
    public function testARowClaimedRecentlyIsLeftAlone(): void
    {
        $id = $this->claimAndStrand('long_running_task', '-1 minute', '-30 minutes');

        $this->assertSame([], $this->repo->reclaimAbandoned());
        $this->assertSame('processing', $this->repo->findById($id)['status']);
    }

    /**
     * A row claimed before the column existed has no stamp at all, and
     * `run_at` is the only timestamp it carries. Those are exactly the
     * rows that have been stranded the longest, so they must be reclaimed
     * rather than left because their stamp is missing.
     */
    public function testARowStrandedBeforeTheClaimStampExistedIsStillHandedBack(): void
    {
        $id = $this->claimAndStrand('stranded_before_migration', '-3 days', null);

        $reclaimed = $this->repo->reclaimAbandoned();

        $this->assertCount(1, $reclaimed);
        $this->assertSame($id, (int) $reclaimed[0]['id']);
        $this->assertSame('pending', $this->repo->findById($id)['status']);
    }

    /**
     * And a stampless row that only became due a moment ago is NOT
     * reclaimed: the fallback reads `run_at`, so it has to stop being
     * generous somewhere, and "due one minute ago" is a task a pass may
     * simply still be running.
     */
    public function testAStamplessRowThatOnlyJustBecameDueIsLeftAlone(): void
    {
        $id = $this->claimAndStrand('just_claimed', '-1 minute', null);

        $this->assertSame([], $this->repo->reclaimAbandoned());
        $this->assertSame('processing', $this->repo->findById($id)['status']);
    }

    /**
     * Reclaiming touches nothing that has left 'processing' on its own.
     * The guard is the same `AND status = 'processing'` the claim uses, so
     * a pass reclaiming while another finishes the very same task cannot
     * resurrect a row that has just been marked done.
     */
    public function testReclaimingLeavesEveryOtherStatusWhereItIs(): void
    {
        $done = $this->claimAndStrand('finished_task', '-1 minute', '-12 hours');
        $this->repo->markDone($done);

        $failed = $this->claimAndStrand('broken_task', '-1 minute', '-12 hours');
        $this->repo->markFailed($failed, 'boom');

        $this->assertSame([], $this->repo->reclaimAbandoned());
        $this->assertSame('done', $this->repo->findById($done)['status']);
        $this->assertSame('failed', $this->repo->findById($failed)['status']);
    }

    /**
     * Every way out of a claim clears the stamp, so a row that comes back
     * to 'pending' and is claimed again is judged on its NEW claim and not
     * on the one before it.
     */
    public function testEveryWayOutOfAClaimClearsTheStamp(): void
    {
        $released = $this->claimAndStrand('released_task', '-1 minute', '-12 hours');
        $this->repo->release($released);
        $this->assertNull($this->repo->findById($released)['claimed_at']);

        $done = $this->claimAndStrand('done_task', '-1 minute', '-12 hours');
        $this->repo->markDone($done);
        $this->assertNull($this->repo->findById($done)['claimed_at']);

        $failed = $this->claimAndStrand('failed_task', '-1 minute', '-12 hours');
        $this->repo->markFailed($failed, 'boom');
        $this->assertNull($this->repo->findById($failed)['claimed_at']);
    }

    /**
     * And the claim itself stamps: a row that is 'processing' without
     * saying since when cannot be told apart from an abandoned one, which
     * is the whole question reclaimAbandoned() answers.
     */
    public function testClaimingStampsTheRowWithTheMomentItWasClaimed(): void
    {
        $this->insertAction('due_task', '-1 minute');

        $claimed = $this->repo->claimOverdue();

        $this->assertNotNull($claimed[0]['claimed_at']);
        $this->assertLessThanOrEqual(
            5,
            abs(time() - (int) strtotime((string) $claimed[0]['claimed_at'])),
            'the stamp is the moment of the claim, not of anything else'
        );
    }
}
