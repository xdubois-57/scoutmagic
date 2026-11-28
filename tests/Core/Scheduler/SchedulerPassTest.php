<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What ONE scheduler pass does, and — above all — what it deliberately
 * does not do.
 *
 * These cases used to live in `SchedulerContinuationTest`, alongside the
 * self-continuation chain that has since been removed: with a real
 * crontab now required and verified at installation, `public/cron.php`
 * is the one engine and nothing hops. The chain's own assertions went
 * with it; these did not, because what they pin is `SchedulerRunner`'s
 * own behaviour and it is still load-bearing:
 *
 * - the optional deadline stops a pass from TAKING ON new work rather
 *   than interrupting work in flight, and hands untouched rows back to
 *   `pending` instead of stranding them at `processing`;
 * - a task created WHILE a pass is running is never run by that pass,
 *   which is the guarantee `Task\InstallUpdateHandler` relies on when it
 *   schedules the schema migration instead of running it in the process
 *   that has just replaced every file on disk
 *   (`Tests\Architecture\SelfUpdateMigrationBoundaryTest`);
 * - a pass that DIED in the middle of its list (issue #1439) costs the
 *   next pass nothing for the tasks it never reached, and the task it was
 *   running is retried a bounded number of times, not for ever.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SchedulerPassTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRepository $repo;
    private SchedulerRunner $runner;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->runner = new SchedulerRunner($this->repo, $journal);

        $this->runner->setTaskContext(new TaskContext(
            $this->createStub(Connection::class),
            $this->createStub(EncryptionService::class),
            $this->createStub(MailService::class),
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            sys_get_temp_dir()
        ));
    }

    private function countingHandler(): TaskHandlerInterface
    {
        return new class implements TaskHandlerInterface {
            public int $calls = 0;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->calls++;
            }
        };
    }

    private function queue(int $count): void
    {
        $past = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        for ($i = 0; $i < $count; $i++) {
            $this->repo->create('core', 'test_task', $past, null, 'ref-' . $i);
        }
    }

    /** @return array<string, int> status => count */
    private function statusCounts(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS n FROM scheduled_actions GROUP BY status')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    public function testAPassRunsEveryDueTaskWhenNoDeadlineBoundsIt(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->queue(3);

        $processed = $this->runner->processOverdue();

        $this->assertSame(3, $processed);
        $this->assertSame(3, $handler->calls);
        $this->assertSame(0, $this->repo->countOverdue());
    }

    /**
     * The trap this could easily have fallen into: claimOverdue() flips
     * EVERY due row to 'processing' up front, and nothing ever re-claims a
     * 'processing' row. Stopping at the deadline without handing the
     * untouched rows back would strand them forever — a queue that
     * silently loses tasks is far worse than a queue that drains slowly.
     */
    public function testTasksNotStartedWithinTheBudgetGoBackToPendingNotProcessing(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->queue(3);

        // A deadline already in the past: the first task still runs (a pass
        // must always achieve something), the other two are released.
        $processed = $this->runner->processOverdue(microtime(true) - 1);

        $this->assertSame(1, $processed);
        $this->assertSame(1, $handler->calls);
        $this->assertSame(['done' => 1, 'pending' => 2], $this->statusCounts());
        $this->assertSame(2, $this->repo->countOverdue());
    }

    public function testTheReleasedTasksAreRunByTheNextPass(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->queue(3);

        $this->runner->processOverdue(microtime(true) - 1);
        $this->runner->processOverdue(microtime(true) - 1);
        $this->runner->processOverdue(microtime(true) - 1);

        $this->assertSame(3, $handler->calls);
        $this->assertSame(['done' => 3], $this->statusCounts());
        $this->assertSame(0, $this->repo->countOverdue());
    }

    /**
     * A release is not a failure: nothing was attempted, so `attempts`
     * must not move and `last_error` must stay empty. Otherwise a busy
     * queue would slowly mark every task as having failed repeatedly.
     */
    public function testAReleasedTaskIsNotRecordedAsHavingFailed(): void
    {
        $this->runner->registerHandler('core', 'test_task', $this->countingHandler());
        $this->queue(2);

        $this->runner->processOverdue(microtime(true) - 1);

        $row = $this->pdo->query("SELECT attempts, last_error FROM scheduled_actions WHERE status = 'pending'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
    }

    /**
     * The separation `Task\InstallUpdateHandler` depends on, asserted
     * rather than assumed: a task created WHILE a pass is running is never
     * run by that pass.
     *
     * That handler replaces every file on disk and then schedules the
     * schema migration instead of running it, precisely so that a
     * different process — one where every class is loaded from the new
     * files — does the migrating. If a pass could pick up a task its own
     * handler had just created, that separation would be an illusion and
     * the mixed-code bug class would be back.
     *
     * processOverdue() claims its task list once, up front
     * (SchedulerRepository::claimOverdue()), and iterates that snapshot.
     */
    public function testATaskScheduledByARunningTaskIsNotRunByTheSamePass(): void
    {
        $ranInThisPass = [];
        $scheduler = new \Core\Scheduler\SchedulerService($this->repo);

        $spawner = new class ($scheduler, $ranInThisPass) implements TaskHandlerInterface {
            /** @param array<string> $log */
            public function __construct(
                private \Core\Scheduler\SchedulerService $scheduler,
                private array &$log
            ) {
            }

            public function handle(array $payload, TaskContext $context): void
            {
                $this->log[] = 'spawner';
                // Due immediately — the strongest form of the question.
                $this->scheduler->scheduleAfter('core', 'spawned_task', 0, []);
            }
        };

        $spawned = new class ($ranInThisPass) implements TaskHandlerInterface {
            /** @param array<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function handle(array $payload, TaskContext $context): void
            {
                $this->log[] = 'spawned';
            }
        };

        $this->runner->registerHandler('core', 'test_task', $spawner);
        $this->runner->registerHandler('core', 'spawned_task', $spawned);
        $this->queue(1);

        $this->runner->processOverdue();

        $this->assertSame(['spawner'], $ranInThisPass, 'the spawned task must wait for a later pass');
        $this->assertSame(1, $this->repo->countOverdue(), 'and it must still be queued, not lost');

        // A later pass — a different process, in production — runs it.
        $this->runner->processOverdue();
        $this->assertSame(['spawner', 'spawned'], $ranInThisPass);
    }

    /**
     * Backdate a row to « claimed and started seven hours ago, by a pass
     * that is gone » — the state a killed process leaves, which no test
     * can produce by actually killing one.
     */
    private function abandonWhileRunning(int $id): void
    {
        $longAgo = (new \DateTimeImmutable('-7 hours'))->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'processing', claimed_at = ?, started_at = ? WHERE id = ?"
        )->execute([$longAgo, $longAgo, $id]);
    }

    /**
     * Issue #1439, the scenario: a pass claims its list, the first task
     * takes the process down, and the others were never reached. They go
     * back to the queue at the NEXT pass and run there — not six hours
     * later — while the task that was running keeps the reclaim delay of
     * decision #446, since nothing here can prove it is not still running.
     */
    public function testAPassKilledMidListLeavesNothingButTheRunningTaskBehind(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->queue(4);

        // The dead pass: everything claimed, the first one started, and
        // then the process is gone.
        $claimed = $this->repo->claimOverdue();
        $this->assertCount(4, $claimed);
        $running = (int) $claimed[0]['id'];
        $this->assertTrue($this->repo->markStarted($running));

        $processed = $this->runner->processOverdue();

        $this->assertSame(3, $processed, 'the three never-started tasks run in the very next pass');
        $this->assertSame(3, $handler->calls);
        $this->assertSame('processing', $this->repo->findById($running)['status'], 'the running one keeps its delay');
        $this->assertSame(['done' => 3, 'processing' => 1], $this->statusCounts());

        $lines = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'scheduler_tasks_released'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $lines, 'one line per pass, not one per task');
        $this->assertSame('warning', $lines[0]['level']);
        $context = json_decode((string) $lines[0]['context'], true);
        $this->assertSame(3, $context['count']);
        $released = array_map('intval', array_column(array_slice($claimed, 1), 'id'));
        sort($released);
        $this->assertSame($released, $context['task_ids']);

        // A release is not an attempt: nothing ran.
        foreach ($released as $id) {
            $this->assertSame(0, (int) $this->repo->findById($id)['attempts']);
        }
    }

    /** And a pass with nothing to hand back says nothing. */
    public function testANormalPassWritesNoReleaseLine(): void
    {
        $this->runner->registerHandler('core', 'test_task', $this->countingHandler());
        $this->queue(2);

        $this->runner->processOverdue();

        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'scheduler_tasks_released'"
            )->fetchColumn()
        );
    }

    /**
     * The task that killed its pass is reclaimed after the delay, and each
     * reclaim counts. Without the count it killed the next pass, and the
     * one after, for ever; at the ceiling it ends 'failed', visibly.
     *
     * The row is due in the future after each reclaim so that this test's
     * passes do not run it — each cycle stands for « ran, and took the
     * pass down with it again ».
     */
    public function testATaskThatKeepsKillingItsPassEndsFailedWithItsJournalLine(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $id = $this->repo->create('core', 'killer_task', '2099-01-01 00:00:00', null, null);

        for ($cycle = 1; $cycle < SchedulerRepository::ABANDON_AFTER_ATTEMPTS; $cycle++) {
            $this->abandonWhileRunning($id);
            $this->runner->processOverdue();

            $row = $this->repo->findById($id);
            $this->assertSame('pending', $row['status'], "reclaim #{$cycle} hands it back");
            $this->assertSame($cycle, (int) $row['attempts']);
            $this->assertNull($row['started_at']);
        }

        $this->abandonWhileRunning($id);
        $this->runner->processOverdue();

        $row = $this->repo->findById($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(SchedulerRepository::ABANDON_AFTER_ATTEMPTS, (int) $row['attempts']);
        $this->assertStringContainsString('killer_task', (string) $row['last_error']);
        $this->assertStringContainsString('abandonnée', (string) $row['last_error']);

        $lines = $this->pdo->query(
            "SELECT category, level, description, context FROM event_log WHERE event_type = 'scheduler_task_abandoned'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $lines);
        $this->assertSame('core', $lines[0]['category']);
        $this->assertSame('error', $lines[0]['level']);
        $this->assertStringContainsString('killer_task', (string) $lines[0]['description']);
        $this->assertSame(
            ['task_id' => $id, 'module_id' => 'core', 'attempts' => SchedulerRepository::ABANDON_AFTER_ATTEMPTS],
            json_decode((string) $lines[0]['context'], true)
        );
        $this->assertSame(
            SchedulerRepository::ABANDON_AFTER_ATTEMPTS - 1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'scheduler_task_reclaimed'"
            )->fetchColumn(),
            'the reclaims before the ceiling are warnings, as before'
        );
        $this->assertSame(0, $handler->calls);
    }
}
