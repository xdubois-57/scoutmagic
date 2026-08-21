<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Task\PurgeRateLimitsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Every accepted report writes a rate-limit row, and the counter only ever
 * reads the last hour — so without this task `support_report_rate_limits`
 * grows forever on the one installation that receives every other
 * installation's traffic (ARCHITECTURE.md §8.49).
 */
class PurgeRateLimitsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SupportReportRateLimitRepository $rateLimits;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->rateLimits = new SupportReportRateLimitRepository($this->pdo);
    }

    private function context(): TaskContext
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $storagePath = sys_get_temp_dir() . '/purge_rate_limits_' . uniqid();
        if (!is_dir($storagePath . '/keys')) {
            mkdir($storagePath . '/keys', 0o777, true);
        }

        return new TaskContext(
            $connection,
            $encryption,
            new MailService('local', 'unite@exemple.be', 'Unité', 'EX', new DkimManager($storagePath . '/keys'), 's1'),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            $storagePath
        );
    }

    private function recordAt(string $ipHash, string $when): void
    {
        $this->pdo
            ->prepare('INSERT INTO support_report_rate_limits (ip_hash, created_at) VALUES (?, ?)')
            ->execute([$ipHash, (new \DateTimeImmutable($when))->format('Y-m-d H:i:s')]);
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM support_report_rate_limits')->fetchColumn();
    }

    public function testRowsPastTheWindowAreDeletedAndRecentOnesKept(): void
    {
        $this->recordAt('old-hash', '-5 hours');
        $this->recordAt('old-hash', '-2 hours');
        $this->recordAt('fresh-hash', '-5 minutes');

        (new PurgeRateLimitsHandler())->handle([], $this->context());

        $this->assertSame(1, $this->rowCount());
    }

    /**
     * The window the purge uses is the same constant the counter reads
     * against — a purge on a shorter window would silently reset somebody's
     * count, and on a longer one would keep rows nothing ever reads.
     */
    public function testThePurgeWindowIsTheRateLimiterSOwnWindow(): void
    {
        $this->recordAt('hash', '-' . (StatisticsIntakeService::RATE_LIMIT_WINDOW_MINUTES + 5) . ' minutes');
        $this->recordAt('hash', '-' . (StatisticsIntakeService::RATE_LIMIT_WINDOW_MINUTES - 5) . ' minutes');

        (new PurgeRateLimitsHandler())->handle([], $this->context());

        $this->assertSame(1, $this->rowCount());
    }

    public function testTheTaskReschedulesItselfDaily(): void
    {
        (new PurgeRateLimitsHandler())->handle([], $this->context());

        $scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        $next = $scheduler->find('support_dashboard', PurgeRateLimitsHandler::TASK_KEY, PurgeRateLimitsHandler::REFERENCE);

        $this->assertNotNull($next);
    }

    /**
     * A purge that threw must not be a purge that stops: the reschedule
     * happens in a `finally`, so the task survives its own bad day.
     */
    public function testTheTaskStillReschedulesItselfWhenThePurgeFails(): void
    {
        $this->pdo->exec('DROP TABLE support_report_rate_limits');

        try {
            (new PurgeRateLimitsHandler())->handle([], $this->context());
        } catch (\Throwable) {
            // The purge failing is the premise of this test.
        }

        $scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->assertNotNull(
            $scheduler->find('support_dashboard', PurgeRateLimitsHandler::TASK_KEY, PurgeRateLimitsHandler::REFERENCE)
        );
    }

    public function testDeleteOlderThanReportsHowManyRowsItRemoved(): void
    {
        $this->recordAt('a', '-3 hours');
        $this->recordAt('b', '-3 hours');
        $this->recordAt('c', '-1 minute');

        $cutoff = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');

        $this->assertSame(2, $this->rateLimits->deleteOlderThan($cutoff));
        $this->assertSame(1, $this->rowCount());
    }
}
