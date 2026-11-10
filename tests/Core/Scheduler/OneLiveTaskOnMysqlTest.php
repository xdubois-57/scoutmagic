<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Database\AdvisoryLock;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * « One restore at a time » (issue #753), against the engine an
 * installation runs: SchedulerRepository::createUnlessLive().
 *
 * The invariant belongs to the server, not to the button the page greys
 * out, and a SELECT followed by an INSERT does not hold it — two requests
 * can both see nothing. The concurrent case is played with two genuine
 * connections: the second holds the task's named lock, as a request
 * between its check and its insert does, and the first must stand down
 * without writing.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OneLiveTaskOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
    }

    public function testTheFirstRestoreIsQueued(): void
    {
        $this->assertIsInt($this->scheduleRestore());
        $this->assertSame(1, $this->liveRestores());
    }

    /** @return iterable<string, array{string}> */
    public static function liveStatuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'processing' => ['processing'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveStatuses')]
    public function testASecondRestoreIsRefusedWhileOneIsAlive(string $status): void
    {
        $this->queue($status, null);

        $this->assertNull($this->scheduleRestore());
        $this->assertSame(1, $this->liveRestores());
    }

    /** @return iterable<string, array{string}> */
    public static function endedStatuses(): iterable
    {
        yield 'done' => ['done'];
        yield 'failed' => ['failed'];
        yield 'canceled' => ['canceled'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endedStatuses')]
    public function testARestoreThatEndedDoesNotBlockTheNext(string $status): void
    {
        $this->queue($status, null);

        $this->assertIsInt($this->scheduleRestore());
    }

    /**
     * A row queued under a reference still counts: the guard reads the task,
     * not a chain of it.
     */
    public function testARowWithAReferenceStillCounts(): void
    {
        $this->queue('pending', 'legacy-chain');

        $this->assertNull($this->scheduleRestore());
    }

    /**
     * The concurrent case. Another connection is between its check and its
     * insert — it holds the lock — so this one must not insert, even though
     * the table holds no live row yet.
     */
    public function testARestoreArrivingWhileAnotherIsBeingQueuedIsRefused(): void
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $lock = 'scheduler_one_live:core:restore_backup';

        $this->assertTrue(AdvisoryLock::acquire($other, $lock));
        try {
            $this->assertNull($this->scheduleRestore());
            $this->assertSame(0, $this->liveRestores(), 'nothing may be written while another request holds the lock');
        } finally {
            AdvisoryLock::release($other, $lock);
        }

        $this->assertIsInt($this->scheduleRestore(), 'the lock is released after every attempt');
        $this->assertSame(1, $this->liveRestores());
    }

    private function scheduleRestore(): ?int
    {
        return (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->scheduleAfterUnlessLive('core', 'restore_backup', 0, ['source' => 'server', 'backup_id' => 1]);
    }

    private function queue(string $status, ?string $reference): void
    {
        $this->pdo->prepare(
            "INSERT INTO scheduled_actions (module_id, task_key, reference, run_at, status, created_at)
             VALUES ('core', 'restore_backup', ?, NOW(), ?, NOW())"
        )->execute([$reference, $status]);
    }

    private function liveRestores(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions
             WHERE module_id = 'core' AND task_key = 'restore_backup' AND status IN ('pending', 'processing')"
        )->fetchColumn();
    }
}
