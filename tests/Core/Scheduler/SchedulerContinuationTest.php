<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerContinuation;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SliceOutcome;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Almost no test here emits a real hop: `base_url` is deliberately left
 * unset, so SchedulerContinuation::baseUrl() answers null and emitHop()
 * returns before touching a socket. What is under test is the decision —
 * the three cumulative conditions and the ceiling — and the slice
 * mechanics around it, which is where the damage would be. A test that
 * reached out to the network would be testing the network.
 *
 * The two exceptions at the end of this class are about whether a hop was
 * SENT, which is a different question and became a load-bearing one when
 * `Task\InstallUpdateHandler` stopped migrating in its own process: the
 * answer decides whether the schema migration starts now or waits for a
 * visitor. Both point `base_url` at a socket on 127.0.0.1 that the test
 * itself owns — one listening, one that nothing is listening on. That is
 * not the network; it is the two outcomes of a local connect, which is
 * exactly what emitHop() has to tell apart.
 */
class SchedulerContinuationTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRepository $repo;
    private SchedulerRunner $runner;
    private SettingService $settings;
    private SchedulerContinuation $continuation;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->runner = new SchedulerRunner($this->repo, $journal);

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            [SchedulerContinuation::BUDGET_SETTING, '75'],
            [SchedulerContinuation::MAX_HOPS_SETTING, '30'],
            [SchedulerContinuation::HOPS_SETTING, '0'],
        ] as [$key, $default]) {
            $this->settings->register($key, $default, 'number', $key, 'test', null, null, null, false, 900);
        }

        $this->runner->setTaskContext(new TaskContext(
            $this->createMock(Connection::class),
            $this->createMock(EncryptionService::class),
            $this->createMock(MailService::class),
            $journal,
            $this->settings,
            $this->createMock(UserAccountRepository::class),
            sys_get_temp_dir()
        ));

        $this->continuation = new SchedulerContinuation(
            $this->runner,
            $this->repo,
            $this->settings,
            $journal,
            $this->pdo,
            'the-shared-secret'
        );
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

    public function testASliceRunsEveryDueTaskWhenTheBudgetIsAmple(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->queue(3);

        $outcome = $this->continuation->runSlice();

        $this->assertTrue($outcome->heldLock);
        $this->assertSame(3, $outcome->processed);
        $this->assertFalse($outcome->workRemains);
        $this->assertSame(3, $handler->calls);
    }

    /**
     * The trap this mechanism could easily have fallen into: claimOverdue()
     * flips EVERY due row to 'processing' up front, and nothing ever
     * re-claims a 'processing' row. Stopping at the budget without handing
     * the untouched rows back would strand them forever — a queue that
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

    public function testTheReleasedTasksAreRunByTheNextSlice(): void
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

    public function testAHopNeedsWorkStillRemaining(): void
    {
        $this->assertFalse($this->continuation->shouldHop(
            new SliceOutcome(heldLock: true, processed: 5, workRemains: false)
        ));
    }

    public function testAHopNeedsTheSliceToHaveHeldTheLock(): void
    {
        // Without this, every visitor arriving during a long chain starts a
        // chain of their own, and they double at every turn.
        $this->assertFalse($this->continuation->shouldHop(
            new SliceOutcome(heldLock: false, processed: 5, workRemains: true)
        ));
    }

    public function testAHopNeedsTheSliceToHaveMadeProgress(): void
    {
        // Hopping on zero progress is a loop that burns one request per
        // turn and finishes nothing.
        $this->assertFalse($this->continuation->shouldHop(
            new SliceOutcome(heldLock: true, processed: 0, workRemains: true)
        ));
    }

    public function testAHopNeedsTheCounterToBeUnderItsCeiling(): void
    {
        $this->settings->setInternal(SchedulerContinuation::MAX_HOPS_SETTING, '3');
        $this->settings->setInternal(SchedulerContinuation::HOPS_SETTING, '3');

        $this->assertFalse($this->continuation->shouldHop(
            new SliceOutcome(heldLock: true, processed: 5, workRemains: true)
        ));
    }

    public function testAllThreeConditionsTogetherEarnAHop(): void
    {
        $this->assertTrue($this->continuation->shouldHop(
            new SliceOutcome(heldLock: true, processed: 5, workRemains: true)
        ));
    }

    /**
     * Zero must mean "off", not "unset". An environment that cannot host
     * self-continuation needs a way to say so — and the case that matters
     * is a `php -S` server, which serves one request per worker at a time
     * and defaults to one worker: a hop there does not run alongside the
     * request that emitted it, it queues behind it and then holds the only
     * worker for a whole slice. That is how two unrelated browser specs
     * came to time out at 40 s under the dynamic-security scan while the
     * same commit passed the plain browser suite.
     */
    public function testAMaxHopsOfZeroTurnsChainingOffRatherThanFallingBackToTheDefault(): void
    {
        $this->settings->setInternal(SchedulerContinuation::MAX_HOPS_SETTING, '0');

        $this->assertFalse($this->continuation->shouldHop(
            new SliceOutcome(heldLock: true, processed: 5, workRemains: true)
        ));
    }

    /**
     * The counterpart: a missing or unreadable setting must NOT be read as
     * zero, or a fresh installation would silently never chain.
     */
    public function testAnUnsetCeilingFallsBackToTheDefaultRatherThanToZero(): void
    {
        $settingless = new SchedulerContinuation(
            $this->runner,
            $this->repo,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            $this->pdo,
            'secret'
        );
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = '" . SchedulerContinuation::MAX_HOPS_SETTING . "'");

        $this->assertTrue($settingless->shouldHop(
            new SliceOutcome(heldLock: true, processed: 5, workRemains: true)
        ));
    }

    public function testBeginChainResetsTheHopCounter(): void
    {
        $this->settings->setInternal(SchedulerContinuation::HOPS_SETTING, '12');
        $this->assertSame(12, $this->continuation->hopCount());

        $this->continuation->beginChain();

        $this->assertSame(0, $this->continuation->hopCount());
        $this->assertTrue($this->continuation->shouldHop(
            new SliceOutcome(heldLock: true, processed: 1, workRemains: true)
        ));
    }

    /**
     * The chain must be bounded even if every slice keeps finding work: a
     * chain that cannot end is worse than a queue that drains slowly.
     */
    public function testTheChainStopsAtTheCeilingEvenWithWorkStillQueued(): void
    {
        $this->settings->setInternal(SchedulerContinuation::MAX_HOPS_SETTING, '2');
        $earned = 0;

        for ($hop = 0; $hop < 10; $hop++) {
            if (!$this->continuation->shouldHop(new SliceOutcome(heldLock: true, processed: 1, workRemains: true))) {
                break;
            }
            $earned++;
            $this->settings->setInternal(SchedulerContinuation::HOPS_SETTING, (string) $earned);
        }

        $this->assertSame(2, $earned);
    }

    /**
     * The assumption the whole of IT-07 rests on, asserted rather than
     * assumed: a task created WHILE a pass is running is never run by that
     * pass.
     *
     * `Task\InstallUpdateHandler` replaces every file on disk and then
     * schedules the schema migration instead of running it, precisely so
     * that a different process — one where every class is loaded from the
     * new files — does the migrating. If a pass could pick up a task its
     * own handler had just created, that separation would be an illusion
     * and the mixed-code bug class would be back.
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
     * kick() is how Task\InstallUpdateHandler asks for a pass to start
     * NOW, in a process of its own, having deliberately not migrated in
     * the process that replaced the files. It starts a fresh chain: the
     * update is not a continuation of whatever chain happened to be
     * running, and must get the full hop budget.
     */
    public function testKickStartsAFreshChain(): void
    {
        $this->settings->setInternal(SchedulerContinuation::HOPS_SETTING, '27');

        $this->continuation->kick();

        $this->assertLessThan(
            27,
            $this->continuation->hopCount(),
            'a kick must not inherit the hop count of an unrelated chain'
        );
    }

    /**
     * And it degrades exactly like a hop. No base_url means no request to
     * write — which must be reported, not thrown: the update that calls
     * this has already succeeded, and the migration is queued either way.
     * A failed kick is a slower migration, never a failed update.
     */
    public function testKickReportsRatherThanThrowsWhenThereIsNowhereToSend(): void
    {
        $this->assertFalse($this->continuation->kick(), 'no base_url, nothing written');
        $this->assertSame(0, $this->continuation->hopCount(), 'and no hop was consumed');
    }

    public function testAMissingOrWrongSecretIsRefusedTheSameWay(): void
    {
        $this->assertTrue($this->continuation->verifySecret('the-shared-secret'));
        $this->assertFalse($this->continuation->verifySecret('not-the-secret'));
        $this->assertFalse($this->continuation->verifySecret(''));
        $this->assertFalse($this->continuation->verifySecret(null));
    }

    /**
     * An installation with no secret at all (secrets.enc unwritable, say)
     * must not accept an empty presented secret as a match — that would
     * make the endpoint world-callable on exactly the installations least
     * able to notice.
     */
    public function testAnInstallationWithoutASecretAcceptsNothing(): void
    {
        $secretless = new SchedulerContinuation(
            $this->runner,
            $this->repo,
            $this->settings,
            new JournalService(new JournalRepository($this->pdo)),
            $this->pdo,
            ''
        );

        $this->assertFalse($secretless->verifySecret(''));
        $this->assertFalse($secretless->verifySecret('anything'));
    }

    /**
     * A configured base_url with nothing listening: the hop is attempted
     * against every target and none of them takes it. Reported as not
     * sent, and — the part that matters — not thrown. The caller here is
     * an update that has already succeeded.
     */
    public function testAHopReportsFailureWhenNothingIsListening(): void
    {
        $port = $this->aPortNobodyIsUsing();
        $this->settings->register('base_url', '', 'text', 'base', 'test', null, null, null, false, 900);
        $this->settings->setInternal('base_url', 'http://127.0.0.1:' . $port);

        $this->assertFalse($this->continuation->kick());
    }

    /**
     * And the other outcome, which is the one the migration depends on: a
     * listening socket takes the request, so the kick reports that it was
     * sent. The socket is this test's own, accepted and dropped without
     * ever being read — which is precisely what fire-and-forget means, and
     * why the caller never waits for a response.
     */
    public function testAHopReportsSuccessWhenTheRequestIsAccepted(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a local listening socket: {$errstr}");

        $name = stream_socket_get_name($server, false);
        $this->assertNotFalse($name);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        $this->settings->register('base_url', '', 'text', 'base', 'test', null, null, null, false, 900);
        $this->settings->setInternal('base_url', 'http://127.0.0.1:' . $port);

        try {
            $this->assertTrue($this->continuation->kick());
            $this->assertSame(1, $this->continuation->hopCount(), 'a sent hop consumes one of the chain budget');
        } finally {
            fclose($server);
        }
    }

    private function aPortNobodyIsUsing(): int
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "could not reserve a local port: {$errstr}");
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        return $port;
    }

    /**
     * With no base_url configured there is nowhere to hop to, and the
     * whole chain must degrade to what it was before this existed: the
     * slice still runs, nothing throws, and no counter moves.
     */
    public function testNoBaseUrlMeansNoHopAndNoFailure(): void
    {
        $handler = $this->countingHandler();
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->queue(2);

        $outcome = $this->continuation->runSliceAndContinue();

        $this->assertSame(2, $outcome->processed);
        $this->assertSame(0, $this->continuation->hopCount());
    }
}
