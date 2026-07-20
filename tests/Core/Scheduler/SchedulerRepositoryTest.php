<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Scheduler\SchedulerRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
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

    public function testClaimOverdueReturnsOnlyDue(): void
    {
        // Insert a due action
        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status)
             VALUES ('core', 'due_task', datetime('now', '-1 minute'), 'pending')"
        );
        $stmt->execute();

        // Insert a future action
        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status)
             VALUES ('core', 'future_task', datetime('now', '+1 hour'), 'pending')"
        );
        $stmt->execute();

        $due = $this->repo->claimOverdue();
        $this->assertCount(1, $due);
        $this->assertSame('due_task', $due[0]['task_key']);
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
}
