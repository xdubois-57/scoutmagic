<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\Task\PurgeSendCountersHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The daily tallies stop being read long before they stop existing
 * (ARCHITECTURE.md §8.106).
 *
 * The window is deliberately wider than the thirty days the reserve
 * reads: the peak it is built on is a number an administrator is shown,
 * and « d'où sort ce nombre » is unanswerable the day the rows behind it
 * are gone.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeSendCountersHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;
    private SendCounterRepository $counters;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->counters = new SendCounterRepository($this->pdo);

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

    public function testACounterPastTheWindowIsDropped(): void
    {
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(PurgeSendCountersHandler::RETENTION_DAYS + 1));

        (new PurgeSendCountersHandler())->handle([], $this->context);

        $this->assertSame(0, $this->counterRows());
    }

    public function testACounterInsideTheWindowIsKept(): void
    {
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(PurgeSendCountersHandler::RETENTION_DAYS - 1));

        (new PurgeSendCountersHandler())->handle([], $this->context);

        $this->assertSame(1, $this->counterRows());
    }

    /**
     * The window has to be wider than the reserve's own thirty days, or
     * the figure the screen shows would outlive the rows that justify it.
     */
    public function testTheRetentionIsWiderThanTheReserveWindow(): void
    {
        $this->assertGreaterThan(30, PurgeSendCountersHandler::RETENTION_DAYS);
    }

    public function testAPassThatDroppedNothingSaysNothing(): void
    {
        (new PurgeSendCountersHandler())->handle([], $this->context);

        $this->assertSame(0, $this->journalCount());
    }

    public function testAPassThatDroppedSomethingSaysHowMuch(): void
    {
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(PurgeSendCountersHandler::RETENTION_DAYS + 1));

        (new PurgeSendCountersHandler())->handle([], $this->context);

        $this->assertSame(1, $this->journalCount());
    }

    /**
     * A recurring chain that never re-arms runs exactly once, ever.
     */
    public function testItRearmsItself(): void
    {
        (new PurgeSendCountersHandler())->handle([], $this->context);

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions
             WHERE module_id = 'core' AND task_key = ? AND status = 'pending'"
        );
        $statement->execute([PurgeSendCountersHandler::TASK_KEY]);

        $this->assertSame(1, (int) $statement->fetchColumn());
    }

    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d');
    }

    private function counterRows(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_send_counters');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    private function journalCount(): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM event_log WHERE event_type = ?");
        $statement->execute(['mail_send_counters_purged']);

        return (int) $statement->fetchColumn();
    }
}
