<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Task;

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
use Modules\Groups\Repository\RateLimitRepository;
use Modules\Groups\Task\PurgeRateLimitHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The sweep that keeps discussion_group_rate_limits from growing without
 * bound, and the self-rescheduling that keeps it running — same shape as
 * Tests\Modules\Retro\Task\PurgeRateLimitHandlerTest, over this module's
 * own table.
 *
 * @group database
 */
#[Group('database')]
class PurgeRateLimitHandlerTest extends TestCase
{
    private \PDO $pdo;
    private RateLimitRepository $repository;
    private SchedulerService $schedulerService;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->repository = new RateLimitRepository($this->pdo);
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testDeletesRowsOlderThanTheRetentionWindowAndKeepsRecentOnes(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_rate_limits (member_id, action_type, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([3, 'post', '2020-01-01 00:00:00']);
        $this->repository->record(4, 'post');

        (new PurgeRateLimitHandler())->handle([], $this->context);

        $this->assertSame(0, $this->repository->countSince(3, 'post', '2000-01-01 00:00:00'));
        $this->assertSame(1, $this->repository->countSince(4, 'post', '2000-01-01 00:00:00'));
    }

    /**
     * A row an hour old is still well inside the retention window: the
     * retention is deliberately far longer than the rate-limit window
     * itself, so a missed run never silently widens someone's budget.
     */
    public function testARowInsideTheRetentionWindowSurvives(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_rate_limits (member_id, action_type, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([3, 'reply', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')]);

        (new PurgeRateLimitHandler())->handle([], $this->context);

        $this->assertSame(1, $this->repository->countSince(3, 'reply', '2000-01-01 00:00:00'));
    }

    public function testReschedulesItselfForTheNextDay(): void
    {
        (new PurgeRateLimitHandler())->handle([], $this->context);

        $scheduled = $this->schedulerService->find('groups', 'purge_rate_limits', 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testDoesNotDoubleScheduleWhenAFutureRunIsAlreadyPending(): void
    {
        $this->schedulerService->schedule('groups', 'purge_rate_limits', new \DateTimeImmutable('+1 day'), [], 'daily');

        (new PurgeRateLimitHandler())->handle([], $this->context);

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'groups' AND task_key = 'purge_rate_limits'")
            ->fetchColumn();
        $this->assertSame(1, $count);
    }
}
