<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\SentEmailClaimRepository;
use Core\Mail\Task\PurgeSentEmailClaimsHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeSentEmailClaimsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;
    private SentEmailClaimRepository $claims;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->claims = new SentEmailClaimRepository($this->pdo);

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

    public function testAClaimPastTheWindowIsDropped(): void
    {
        $this->claims->claim('campaign:2020-09-30', '42');
        $this->ageEveryClaimBy(PurgeSentEmailClaimsHandler::RETENTION_DAYS + 1);

        (new PurgeSentEmailClaimsHandler())->handle([], $this->context);

        $this->assertSame(0, $this->claimCount());
    }

    public function testAClaimInsideTheWindowIsKept(): void
    {
        $this->claims->claim('campaign:2026-09-30', '42');
        $this->ageEveryClaimBy(PurgeSentEmailClaimsHandler::RETENTION_DAYS - 1);

        (new PurgeSentEmailClaimsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->claimCount(), 'A campaign still running must keep its guard.');
    }

    public function testAPassThatDroppedNothingSaysNothing(): void
    {
        (new PurgeSentEmailClaimsHandler())->handle([], $this->context);

        $this->assertSame(0, $this->journalCount());
    }

    public function testAPassThatDroppedSomethingSaysHowMuch(): void
    {
        $this->claims->claim('campaign:2020-09-30', '42');
        $this->ageEveryClaimBy(PurgeSentEmailClaimsHandler::RETENTION_DAYS + 1);

        (new PurgeSentEmailClaimsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->journalCount());
    }

    public function testItReschedulesItselfDaily(): void
    {
        (new PurgeSentEmailClaimsHandler())->handle([], $this->context);

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('core', PurgeSentEmailClaimsHandler::TASK_KEY, PurgeSentEmailClaimsHandler::REFERENCE);

        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    private function ageEveryClaimBy(int $days): void
    {
        $stmt = $this->pdo->prepare('UPDATE sent_email_claims SET claimed_at = ?');
        $stmt->execute([(new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s')]);
    }

    private function claimCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM sent_email_claims')->fetchColumn();
    }

    private function journalCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'sent_email_claims_purged'"
        )->fetchColumn();
    }
}
