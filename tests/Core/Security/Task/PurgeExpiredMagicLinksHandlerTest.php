<?php

declare(strict_types=1);

namespace Tests\Core\Security\Task;

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
use Core\Security\Task\PurgeExpiredMagicLinksHandler;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1099: expired magic links are purged on a schedule — the purge
 * existed and nothing called it — but not before the rate-limit hour that
 * still counts them is over.
 */
#[Group('database')]
class PurgeExpiredMagicLinksHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
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

    public function testItDeletesOnlyTheLinksPastBothTheirExpiryAndTheRateLimitHour(): void
    {
        $old = $this->link('-2 hours', '-2 hours');
        $expiredButCounted = $this->link('-30 minutes', '-15 minutes');
        $live = $this->link('now', '+15 minutes');

        (new PurgeExpiredMagicLinksHandler())->handle([], $this->context);

        $rows = $this->pdo->query('SELECT id FROM magic_links ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $ids = array_map('intval', $rows);
        $this->assertNotContains($old, $ids);
        $this->assertContains($expiredButCounted, $ids);
        $this->assertContains($live, $ids);
    }

    public function testItReschedulesItselfForTomorrow(): void
    {
        (new PurgeExpiredMagicLinksHandler())->handle([], $this->context);

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('core', PurgeExpiredMagicLinksHandler::TASK_KEY, PurgeExpiredMagicLinksHandler::REFERENCE);
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    private function link(string $createdAt, string $expiresAt): int
    {
        $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        )->execute([
            str_repeat('a', 64),
            'hash',
            (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
