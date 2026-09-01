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
 *   (`Tests\Architecture\SelfUpdateMigrationBoundaryTest`).
 */
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
            $this->createMock(Connection::class),
            $this->createMock(EncryptionService::class),
            $this->createMock(MailService::class),
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
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
}
