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
use Core\Security\Task\PurgeExpiredPasswordResetTokensHandler;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1257: the purge of password-reset tokens existed and nothing called
 * it, so the table only ever grew. Expired or used tokens go on a schedule —
 * but not before the rate-limit hour that still counts them is over.
 */
#[Group('database')]
class PurgeExpiredPasswordResetTokensHandlerTest extends TestCase
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

    public function testItDeletesExpiredAndUsedTokensButNotLiveOnesOrThoseStillCounted(): void
    {
        $expired = $this->token('-3 hours', '-2 hours', false);
        $used = $this->token('-2 hours', '-1 hour', true);
        $usedButCounted = $this->token('-10 minutes', '+50 minutes', true);
        $live = $this->token('-5 minutes', '+55 minutes', false);

        (new PurgeExpiredPasswordResetTokensHandler())->handle([], $this->context);

        $ids = array_map('intval', $this->pdo
            ->query('SELECT id FROM password_reset_tokens ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertNotContains($expired, $ids);
        $this->assertNotContains($used, $ids);
        $this->assertContains($usedButCounted, $ids);
        $this->assertContains($live, $ids);
    }

    public function testItReschedulesItselfForTomorrow(): void
    {
        (new PurgeExpiredPasswordResetTokensHandler())->handle([], $this->context);

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))->find(
            'core',
            PurgeExpiredPasswordResetTokensHandler::TASK_KEY,
            PurgeExpiredPasswordResetTokensHandler::REFERENCE
        );
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    /**
     * Review of #1374: a purge that fails must still leave its successor, or the
     * chain dies until a later web request re-seeds it and the table grows again.
     * The failure itself still propagates, so the scheduler records it.
     */
    public function testAFailingPurgeStillLeavesItsSuccessor(): void
    {
        $this->pdo->exec('DROP TABLE password_reset_tokens');

        try {
            (new PurgeExpiredPasswordResetTokensHandler())->handle([], $this->context);
            $this->fail('The failure must propagate.');
        } catch (\PDOException) {
            // expected
        }

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))->find(
            'core',
            PurgeExpiredPasswordResetTokensHandler::TASK_KEY,
            PurgeExpiredPasswordResetTokensHandler::REFERENCE
        );
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    /** Registered where the scheduler looks tasks up, or it would never run. */
    public function testTheTaskIsRegisteredWithTheCoreHandlers(): void
    {
        $handlers = \Core\Scheduler\CoreTaskHandlers::all();

        $this->assertSame(
            PurgeExpiredPasswordResetTokensHandler::class,
            $handlers[PurgeExpiredPasswordResetTokensHandler::TASK_KEY] ?? null
        );
    }

    private function token(string $createdAt, string $expiresAt, bool $used): int
    {
        $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at, used, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            str_repeat('a', 64),
            'hash',
            (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s'),
            $used ? 1 : 0,
            (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
