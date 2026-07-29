<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Retro\Repository\RateLimitRepository;
use Modules\Retro\Task\PurgeRateLimitHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
class PurgeRateLimitHandlerTest extends TestCase
{
    private \PDO $pdo;
    private RateLimitRepository $repository;
    private SchedulerService $schedulerService;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->repository = new RateLimitRepository($this->pdo);
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testDeletesRowsOlderThanTheRetentionWindow(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO retro_rate_limits (identifier_hash, action_type, created_at) VALUES (?, ?, ?)');
        $stmt->execute(['old', 'comment', '2020-01-01 00:00:00']);
        $this->repository->record('recent', 'comment');

        (new PurgeRateLimitHandler())->handle([], $this->context);

        $this->assertSame(0, $this->repository->countSince('old', 'comment', '2000-01-01 00:00:00'));
        $this->assertSame(1, $this->repository->countSince('recent', 'comment', '2000-01-01 00:00:00'));
    }

    public function testReschedulesItselfForTheNextDay(): void
    {
        (new PurgeRateLimitHandler())->handle([], $this->context);

        $scheduled = $this->schedulerService->find('retro', 'purge_rate_limits', 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testDoesNotDoubleScheduleWhenAFutureRunIsAlreadyPending(): void
    {
        $this->schedulerService->schedule('retro', 'purge_rate_limits', new \DateTimeImmutable('+1 day'), [], 'daily');

        (new PurgeRateLimitHandler())->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'retro' AND task_key = 'purge_rate_limits'")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
