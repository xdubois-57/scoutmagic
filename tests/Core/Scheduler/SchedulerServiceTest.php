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
}
