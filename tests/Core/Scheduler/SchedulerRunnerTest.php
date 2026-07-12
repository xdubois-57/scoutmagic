<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskHandlerInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SchedulerRunnerTest extends TestCase
{
    private SchedulerRunner $runner;
    private SchedulerRepository $repo;
    private JournalService $journal;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $journalRepo = new JournalRepository($this->pdo);
        $this->journal = new JournalService($journalRepo);
        $this->runner = new SchedulerRunner($this->repo, $this->journal);
    }

    public function testProcessOverdueWithRegisteredHandler(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;
            /** @var array<string, mixed> */
            public array $payload = [];

            public function handle(array $payload): void
            {
                $this->called = true;
                $this->payload = $payload;
            }
        };

        $this->runner->registerHandler('core', 'test_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'test_task', $pastTime, json_encode(['key' => 'value']), null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(1, $processed);
        $this->assertTrue($handler->called);
        $this->assertSame(['key' => 'value'], $handler->payload);
    }

    public function testProcessOverdueMarksTaskDone(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload): void
            {
                // success
            }
        };

        $this->runner->registerHandler('core', 'ok_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'ok_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $action = $this->repo->findById($id);
        $this->assertSame('done', $action['status']);
        $this->assertNotNull($action['executed_at']);
    }

    public function testProcessOverdueMarksTaskFailedOnException(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload): void
            {
                throw new \RuntimeException('Handler error');
            }
        };

        $this->runner->registerHandler('core', 'bad_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'bad_task', $pastTime, null, null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(0, $processed); // failed tasks don't count as processed
        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertStringContainsString('Handler error', $action['last_error']);
    }

    public function testProcessOverdueFailsWithNoHandler(): void
    {
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('unknown', 'missing_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertStringContainsString('No handler registered', $action['last_error']);
    }

    public function testProcessOverdueDoesNotProcessFutureTasks(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload): void
            {
                $this->called = true;
            }
        };

        $this->runner->registerHandler('core', 'future_task', $handler);

        $futureTime = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'future_task', $futureTime, null, null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(0, $processed);
        $this->assertFalse($handler->called);
    }

    public function testJournalEntriesAreCreatedOnCompletion(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload): void
            {
                // success
            }
        };

        $this->runner->registerHandler('core', 'logged_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'logged_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $journalRepo = new JournalRepository($this->pdo);
        $entries = $journalRepo->search();
        $this->assertGreaterThan(0, count($entries));
        $this->assertSame('scheduler_task_done', $entries[0]['event_type']);
    }
}
