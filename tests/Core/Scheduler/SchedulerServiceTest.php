<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SchedulerServiceTest extends TestCase
{
    private SchedulerService $service;
    private SchedulerRepository $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $this->service = new SchedulerService($this->repo);
    }

    public function testScheduleCreatesAction(): void
    {
        $runAt = new \DateTimeImmutable('+1 hour');
        $id = $this->service->schedule('core', 'send_reminder', $runAt, ['member_id' => 42], 'ref-123');

        $this->assertGreaterThan(0, $id);

        $action = $this->repo->findById($id);
        $this->assertNotNull($action);
        $this->assertSame('core', $action['module_id']);
        $this->assertSame('send_reminder', $action['task_key']);
        $this->assertSame('ref-123', $action['reference']);
        $this->assertSame('pending', $action['status']);
    }

    public function testSchedulePropagatesRequestedByUserAccountId(): void
    {
        $runAt = new \DateTimeImmutable('+1 hour');
        $id = $this->service->schedule('core', 'send_reminder', $runAt, [], null, 99);

        $action = $this->repo->findById($id);
        $this->assertSame(99, (int) $action['requested_by_user_account_id']);
    }

    public function testScheduleAfterPropagatesRequestedByUserAccountId(): void
    {
        $id = $this->service->scheduleAfter('core', 'cleanup', 300, [], null, 13);

        $action = $this->repo->findById($id);
        $this->assertSame(13, (int) $action['requested_by_user_account_id']);
    }

    public function testScheduleAfterCreatesDelayedAction(): void
    {
        $id = $this->service->scheduleAfter('core', 'cleanup', 300, ['days' => 30]);

        $action = $this->repo->findById($id);
        $this->assertNotNull($action);
        $this->assertSame('pending', $action['status']);

        // Verify run_at is approximately 5 minutes from now
        $runAt = new \DateTimeImmutable($action['run_at']);
        $diff = $runAt->getTimestamp() - time();
        $this->assertGreaterThan(290, $diff);
        $this->assertLessThan(310, $diff);
    }

    public function testFindReturnsPendingAction(): void
    {
        $runAt = new \DateTimeImmutable('+1 hour');
        $this->service->schedule('core', 'task_a', $runAt, [], 'ref-1');

        $found = $this->service->find('core', 'task_a', 'ref-1');
        $this->assertNotNull($found);
        $this->assertSame('task_a', $found['task_key']);
    }

    public function testFindReturnsNullForCanceledAction(): void
    {
        $runAt = new \DateTimeImmutable('+1 hour');
        $id = $this->service->schedule('core', 'task_b', $runAt, [], 'ref-2');
        $this->service->cancel($id);

        $found = $this->service->find('core', 'task_b', 'ref-2');
        $this->assertNull($found);
    }

    public function testCancelChangesStatus(): void
    {
        $runAt = new \DateTimeImmutable('+1 hour');
        $id = $this->service->schedule('core', 'task_c', $runAt);

        $this->service->cancel($id);

        $action = $this->repo->findById($id);
        $this->assertSame('canceled', $action['status']);
    }

    public function testClaimOverdueClaimsDueTasks(): void
    {
        // Create a task that's already overdue
        $pastTime = (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'overdue_task', $pastTime, null, null);

        // Create a task in the future (should not be claimed)
        $futureTime = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'future_task', $futureTime, null, null);

        $claimed = $this->repo->claimOverdue();
        $this->assertCount(1, $claimed);
        $this->assertSame('overdue_task', $claimed[0]['task_key']);
        $this->assertSame('processing', $claimed[0]['status']);
    }

    public function testMarkDoneSetsStatusAndTimestamp(): void
    {
        $pastTime = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'done_task', $pastTime, null, null);

        $this->repo->markDone($id);

        $action = $this->repo->findById($id);
        $this->assertSame('done', $action['status']);
        $this->assertNotNull($action['executed_at']);
    }

    public function testMarkFailedSetsErrorAndIncrements(): void
    {
        $pastTime = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'fail_task', $pastTime, null, null);

        $this->repo->markFailed($id, 'Something went wrong');

        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertSame('Something went wrong', $action['last_error']);
        $this->assertSame(1, (int) $action['attempts']);
    }

    public function testFindAllAndCount(): void
    {
        $runAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'task_1', $runAt, null, null);
        $this->repo->create('core', 'task_2', $runAt, null, null);
        $this->repo->create('mod', 'task_3', $runAt, null, null);

        $all = $this->repo->findAll();
        $this->assertCount(3, $all);
        $this->assertSame(3, $this->repo->countAll());
    }

    public function testFindAllForTaskScopesToModuleAndTaskKey(): void
    {
        $runAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->service->schedule('sos_staff', 'apply_redirect', new \DateTimeImmutable($runAt), [], '2026-02-01');
        $this->service->schedule('sos_staff', 'apply_redirect', new \DateTimeImmutable($runAt), [], '2026-02-02');
        $this->service->schedule('sos_staff', 'other_task', new \DateTimeImmutable($runAt));

        $rows = $this->service->findAllForTask('sos_staff', 'apply_redirect');

        $this->assertCount(2, $rows);
    }

    public function testDeleteOlderThanPurgesOldScheduledActions(): void
    {
        $this->service->schedule('sos_staff', 'apply_redirect', new \DateTimeImmutable('2024-01-01'), [], '2024-01-01');
        $this->service->schedule('sos_staff', 'apply_redirect', new \DateTimeImmutable('2026-07-01'), [], '2026-07-01');

        $deleted = $this->service->deleteOlderThan('sos_staff', 'apply_redirect', new \DateTimeImmutable('2025-01-01'));

        $this->assertSame(1, $deleted);
        $this->assertCount(1, $this->service->findAllForTask('sos_staff', 'apply_redirect'));
    }

    // ── rearm() ─────────────────────────────────────────────────────────

    public function testRearmSchedulesWhenNothingIsQueued(): void
    {
        $this->assertTrue($this->service->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00'));

        $action = $this->service->find('camps', 'purge_unsorted_mail', 'daily');
        $this->assertNotNull($action);
        $this->assertSame('pending', $action['status']);
        $this->assertSame(
            (new \DateTimeImmutable('tomorrow 04:00'))->format('Y-m-d H:i:s'),
            $action['run_at']
        );
    }

    /**
     * The failure this guard exists for: a composition root seeding its
     * module's tasks on every request would otherwise grow one row per
     * page view.
     */
    public function testRearmNeverQueuesASecondCopy(): void
    {
        $this->service->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00');

        $this->assertFalse($this->service->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00'));
        $this->assertFalse($this->service->rearm('camps', 'purge_unsorted_mail', 'daily', '+5 minutes'));

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_unsorted_mail'"
            )->fetchColumn()
        );
    }

    public function testRearmDoesNotMoveAnAlreadyQueuedRun(): void
    {
        $this->service->rearm('camps', 'geocode_places', 'ref', new \DateTimeImmutable('2030-01-01 04:00'));
        $this->service->rearm('camps', 'geocode_places', 'ref', new \DateTimeImmutable('2031-01-01 04:00'));

        $action = $this->service->find('camps', 'geocode_places', 'ref');
        $this->assertNotNull($action);
        $this->assertSame('2030-01-01 04:00:00', $action['run_at']);
    }

    /**
     * A handler re-arming itself from inside handle() must not find
     * ITSELF and stop the chain. `claimOverdue()` flips the running task
     * to `processing` before the handler is called, and `find()` only ever
     * sees `pending` rows — which is exactly why the guard is safe there.
     */
    public function testAHandlerRearmingItselfMidRunStillSchedulesItsSuccessor(): void
    {
        $this->service->rearm('camps', 'review_reminder', 'daily', new \DateTimeImmutable('-1 minute'));
        $claimed = $this->repo->claimOverdue();
        $this->assertCount(1, $claimed);

        $this->assertTrue(
            $this->service->rearm('camps', 'review_reminder', 'daily', 'tomorrow 06:00'),
            'the running task is `processing`, so it must not block its own successor'
        );
    }

    public function testRearmAfterIsRearmSaidAsADelay(): void
    {
        $this->assertTrue($this->service->rearmAfter('core', 'purge', 'daily', 3600));
        $this->assertFalse($this->service->rearmAfter('core', 'purge', 'daily', 3600));

        $rows = $this->service->findAllForTask('core', 'purge');
        $this->assertCount(1, $rows);
        $this->assertGreaterThan(
            (new \DateTimeImmutable('+50 minutes'))->format('Y-m-d H:i:s'),
            (string) $rows[0]['run_at']
        );
    }

    /**
     * The whole reason `rearmAfter()` exists, reproduced.
     *
     * A production installation ran `sync_mailboxes` NINE times per pass
     * for weeks: nine duplicate chains, each re-arming blindly, each
     * keeping the other eight company. With the guard the duplicates are
     * self-healing — whichever copy runs first queues the successor, the
     * other eight find it pending and stand down — so one pass is enough
     * to be back to a single chain.
     */
    public function testDuplicateChainsCollapseToOneOnTheNextPass(): void
    {
        // Nine copies, exactly as the reference installation had them.
        for ($i = 0; $i < 9; $i++) {
            $this->service->schedule('inbound_mail', 'sync_mailboxes', new \DateTimeImmutable('-1 minute'), [], 'quarter_hourly');
        }
        $this->assertCount(9, $this->repo->claimOverdue());

        // Each claimed row's handler re-arms, the way every recurring
        // chain in this codebase now does.
        for ($i = 0; $i < 9; $i++) {
            $this->service->rearmAfter('inbound_mail', 'sync_mailboxes', 'quarter_hourly', 900);
        }

        $pending = array_filter(
            $this->service->findAllForTask('inbound_mail', 'sync_mailboxes'),
            static fn(array $row): bool => $row['status'] === 'pending'
        );

        $this->assertCount(1, $pending, 'nine chains must leave exactly one successor');
    }

    public function testRearmTellsTheTwoReferencesApart(): void
    {
        $this->service->rearm('camps', 'geocode_places', 'a', 'tomorrow 04:00');

        $this->assertTrue($this->service->rearm('camps', 'geocode_places', 'b', 'tomorrow 04:00'));
    }

    public function testForPdoBuildsAWorkingService(): void
    {
        $this->assertTrue(
            SchedulerService::forPdo($this->pdo)->rearm('camps', 'geocode_places', 'ref', 'tomorrow 04:00')
        );
        $this->assertNotNull($this->service->find('camps', 'geocode_places', 'ref'));
    }

    // ── seeding a chain, from a caller that is not the task ─────────────

    /**
     * The bug this exists for, in one test.
     *
     * `SchedulerRepository::claimOverdue()` flips every overdue row to
     * `processing` at the start of a pass, so for the whole length of
     * that pass a running chain has NO pending row. rearm()'s guard only
     * looks at `pending` — deliberately, so a handler re-arming itself
     * does not find its own claimed row — and a seeder borrowing that
     * guard therefore queued a fresh copy on every single web request
     * that landed during a pass, each one with run_at = now and so
     * already overdue.
     *
     * Which is a feedback loop, not a stray duplicate: every extra copy
     * lengthens the next pass, a longer pass is a wider window, a wider
     * window catches more requests. A real installation reached 16 387
     * runs of one hourly task in forty-eight hours, and 99 % of its
     * event journal was « tâche planifiée terminée ».
     */
    public function testSeedingStandsDownWhileTheChainIsRunning(): void
    {
        $this->service->seed('registration', 'reenrollment_campaign', 'poll', new \DateTimeImmutable());
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'processing'");

        // Ten page views landing during the pass.
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse(
                $this->service->seed('registration', 'reenrollment_campaign', 'poll', new \DateTimeImmutable())
            );
        }

        $this->assertSame(1, $this->countRows('reenrollment_campaign'));
    }

    /**
     * The distinction is not academic: the handler's own re-arm has to
     * keep working from inside handle(), where its row is `processing`.
     * A guard shared between the two would end every chain after one run.
     */
    public function testAHandlerStillRearmsItselfWhileItsOwnRowIsProcessing(): void
    {
        $this->service->seed('registration', 'reenrollment_campaign', 'poll', new \DateTimeImmutable());
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'processing'");

        $this->assertTrue(
            $this->service->rearmAfter('registration', 'reenrollment_campaign', 'poll', 3600)
        );
        $this->assertSame(2, $this->countRows('reenrollment_campaign'));
    }

    public function testSeedingQueuesTheFirstRunWhenNothingIsAlive(): void
    {
        $this->assertTrue($this->service->seedAfter('rental', 'expire_rental_holds', 'hourly', 3600));
        $this->assertFalse($this->service->seedAfter('rental', 'expire_rental_holds', 'hourly', 3600));
        $this->assertSame(1, $this->countRows('expire_rental_holds'));
    }

    /**
     * A chain that finished with no successor — a run that died before
     * its `finally` — has to be seedable again, or it stays dead.
     */
    public function testADoneChainIsSeededAgain(): void
    {
        $this->service->seedAfter('rental', 'expire_rental_holds', 'hourly', 3600);
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'done'");

        $this->assertTrue($this->service->seedAfter('rental', 'expire_rental_holds', 'hourly', 3600));
    }

    /**
     * Healing, not just prevention: an installation that already
     * accumulated duplicates would otherwise spend days draining them,
     * one no-op run and one journal line at a time. Two queued rows of
     * one chain ARE the same chain — that is what a reference means.
     */
    public function testDuplicatesAlreadyQueuedAreCollapsedOnTheNextGuard(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->service->schedule(
                'inbound_mail',
                'purge_unlinked_messages',
                new \DateTimeImmutable('+' . $i . ' minutes'),
                [],
                'inbound-mail-retention'
            );
        }
        $this->assertSame(20, $this->countRows('purge_unlinked_messages'));

        $this->service->seedAfter('inbound_mail', 'purge_unlinked_messages', 'inbound-mail-retention', 3600);

        $this->assertSame(1, $this->countRows('purge_unlinked_messages'));
    }

    /**
     * The survivor is the one that runs FIRST: keeping a later copy
     * would silently postpone the task by the difference.
     */
    public function testTheCollapseKeepsTheEarliestQueuedRun(): void
    {
        $this->service->schedule('rental', 'expire_rental_holds', new \DateTimeImmutable('+3 hours'), [], 'hourly');
        $earliest = $this->service->schedule('rental', 'expire_rental_holds', new \DateTimeImmutable('+1 hour'), [], 'hourly');
        $this->service->schedule('rental', 'expire_rental_holds', new \DateTimeImmutable('+2 hours'), [], 'hourly');

        $this->service->seedAfter('rental', 'expire_rental_holds', 'hourly', 3600);

        $this->assertSame(1, $this->countRows('expire_rental_holds'));
        $this->assertNotNull($this->repo->findById($earliest));
    }

    /**
     * A handler that finds a successor already queued collapses the rest
     * too — the chains that run often are the ones that accumulate.
     */
    public function testRearmCollapsesDuplicatesItFindsAsWell(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->schedule('core', 'send_notifications', new \DateTimeImmutable('+1 hour'), [], 'poll');
        }

        $this->assertFalse($this->service->rearmAfter('core', 'send_notifications', 'poll', 3600));
        $this->assertSame(1, $this->countRows('send_notifications'));
    }

    private function countRows(string $taskKey): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE task_key = ?');
        $stmt->execute([$taskKey]);

        return (int) $stmt->fetchColumn();
    }

    // ── the composition root's opt-in rearm cache ───────────────────────

    /**
     * With cachePendingRearms the guard answers from a snapshot loaded
     * once: after the first rearm(), a duplicate probe issues no SQL at
     * all — proven by deleting the row behind the service's back, which a
     * fresh find() would notice and a memory answer must not.
     */
    public function testCachedRearmAnswersDuplicateProbesFromMemory(): void
    {
        $cached = new SchedulerService($this->repo, cachePendingRearms: true);

        $this->assertTrue($cached->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00'));
        $this->pdo->exec('DELETE FROM scheduled_actions');

        $this->assertFalse($cached->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00'));
    }

    public function testCachedRearmSeesRowsThatExistedBeforeTheSnapshot(): void
    {
        $this->service->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00');

        $cached = new SchedulerService($this->repo, cachePendingRearms: true);
        $this->assertFalse($cached->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00'));
        $this->assertTrue($cached->rearm('camps', 'purge_unsorted_mail', 'other', 'tomorrow 04:00'));
    }

    public function testCachedRearmSeesADirectScheduleThroughTheSameInstance(): void
    {
        $cached = new SchedulerService($this->repo, cachePendingRearms: true);
        // Prime the snapshot, then schedule without going through rearm().
        $cached->rearm('camps', 'review_reminder', 'daily', 'tomorrow 06:00');
        $cached->schedule('camps', 'geocode_places', new \DateTimeImmutable('tomorrow 04:00'), [], 'ref');

        $this->assertFalse($cached->rearm('camps', 'geocode_places', 'ref', 'tomorrow 04:00'));
    }

    public function testCachedRearmForgetsTheSnapshotAfterACancel(): void
    {
        $cached = new SchedulerService($this->repo, cachePendingRearms: true);
        $cached->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00');

        $cached->cancelPending('camps', 'purge_unsorted_mail', 'daily');

        $this->assertTrue($cached->rearm('camps', 'purge_unsorted_mail', 'daily', 'tomorrow 04:00'));
    }
}
