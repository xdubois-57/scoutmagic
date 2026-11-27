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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A pass that died in the middle of its list (issue #1439), on the engine
 * an installation runs.
 *
 * Every step of the recovery is decided on `rowCount()` after an UPDATE —
 * the start stamp (`markStarted()`), the release of the rows never started
 * (`releaseNeverStarted()`), the reclaim and its attempt count
 * (`reclaimAbandoned()`) — and MySQL/MariaDB count CHANGED rows where
 * SQLite counts MATCHED ones. Each of these updates changes a column it
 * also filters on, so the two counts agree by construction; this class is
 * where that claim is checked rather than believed, on the real schema
 * `schema/core.sql` declares (the `started_at` column included).
 */
#[Group('database')]
class InterruptedPassOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private SchedulerRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repo = new SchedulerRepository($this->pdo);
    }

    private function due(string $taskKey): int
    {
        return $this->repo->create(
            'core',
            $taskKey,
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            null,
            null
        );
    }

    private function runner(TaskHandlerInterface $handler): SchedulerRunner
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $runner = new SchedulerRunner($this->repo, $journal);
        $runner->setTaskContext(new TaskContext(
            $this->createStub(Connection::class),
            $this->createStub(EncryptionService::class),
            $this->createStub(MailService::class),
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            sys_get_temp_dir()
        ));
        $runner->registerHandler('core', 'listed_task', $handler);

        return $runner;
    }

    public function testTheStartStampIsTakenOnceAndOnlyOnAClaimedRow(): void
    {
        $id = $this->due('listed_task');
        $this->assertFalse($this->repo->markStarted($id), 'a pending row is not started');

        $this->repo->claimOverdue();

        $this->assertTrue($this->repo->markStarted($id));
        $this->assertFalse($this->repo->markStarted($id), 'the second stamp matches nothing on this engine either');
        $this->assertNotNull($this->repo->findById($id)['started_at']);
    }

    public function testAPassKilledMidListHandsBackOnlyWhatItNeverStarted(): void
    {
        $ids = [$this->due('listed_task'), $this->due('listed_task'), $this->due('listed_task')];
        $this->repo->claimOverdue();
        $this->repo->markStarted($ids[0]);

        $handler = new class implements TaskHandlerInterface {
            public int $calls = 0;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->calls++;
            }
        };
        $processed = $this->runner($handler)->processOverdue();

        $this->assertSame(2, $processed);
        $this->assertSame('processing', $this->repo->findById($ids[0])['status']);
        $this->assertSame('done', $this->repo->findById($ids[1])['status']);
        $this->assertSame('done', $this->repo->findById($ids[2])['status']);

        $context = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'scheduler_tasks_released'"
        )->fetchColumn();
        $this->assertIsString($context);
        $decoded = json_decode($context, true);
        $this->assertSame(2, $decoded['count']);
        $this->assertSame([$ids[1], $ids[2]], $decoded['task_ids']);
    }

    /**
     * The reclaim counts on the server, and the ceiling turns the row
     * 'failed' — compared against `started_at` through COALESCE, on a
     * DATETIME the engine itself compares.
     */
    public function testReclaimsCountUpToTheCeilingAndThenGiveUp(): void
    {
        $id = $this->repo->create('core', 'killer_task', '2099-01-01 00:00:00', null, null);
        $longAgo = (new \DateTimeImmutable('-7 hours'))->format('Y-m-d H:i:s');
        $strand = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'processing', claimed_at = ?, started_at = ? WHERE id = ?"
        );

        for ($cycle = 1; $cycle <= SchedulerRepository::ABANDON_AFTER_ATTEMPTS; $cycle++) {
            $strand->execute([$longAgo, $longAgo, $id]);
            $handled = $this->repo->reclaimAbandoned();

            $this->assertCount(1, $handled, "cycle {$cycle}");
            $this->assertSame($cycle, $handled[0]['attempts']);
            $this->assertSame($cycle === SchedulerRepository::ABANDON_AFTER_ATTEMPTS, $handled[0]['abandoned']);
        }

        $row = $this->repo->findById($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(SchedulerRepository::ABANDON_AFTER_ATTEMPTS, (int) $row['attempts']);
        $this->assertSame([], $this->repo->reclaimAbandoned(), 'a failed row is never reclaimed again');
    }

    /** A recent start keeps the row, however old its claim: the delay is about running time. */
    public function testARecentStartIsNotReclaimedOnThisEngine(): void
    {
        $id = $this->due('listed_task');
        $this->repo->claimOverdue();
        $this->pdo->prepare('UPDATE scheduled_actions SET claimed_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable('-12 hours'))->format('Y-m-d H:i:s'), $id]);
        $this->repo->markStarted($id);

        $this->assertSame([], $this->repo->reclaimAbandoned());
        $this->assertSame([], $this->repo->releaseNeverStarted());
        $this->assertSame('processing', $this->repo->findById($id)['status']);
    }
}
