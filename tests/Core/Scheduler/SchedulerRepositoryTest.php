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
}
