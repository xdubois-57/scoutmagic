<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Task\ResetSettingsHandler;
use Core\Mail\MailService;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 *
 * The safety-backup step (real mysqldump) fails fast against the fake
 * connection used here — same trade-off as CreateBackupHandlerTest and
 * InstallUpdateHandlerTest, since ResetSettingsHandler has no injectable
 * BackupService seam (unlike FullResetHandler, whose truncate logic is the
 * highest-risk new code this iteration and got one). This still covers the
 * handler's own orchestration: it must NOT reset any setting when the
 * safety backup fails, and must notify the requester of the failure.
 */
class ResetSettingsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private ResetSettingsHandler $handler;
    private SettingService $settings;
    private TaskContext $context;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->handler = new ResetSettingsHandler();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('some_setting', 'default_val', 'text', 'L', 'D');
        $this->settings->set('some_setting', 'changed_val');

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        $storagePath = sys_get_temp_dir() . '/reset_settings_handler_test_' . uniqid();
        mkdir($storagePath, 0755, true);

        $connection = Connection::withPdo($this->pdo);
        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $storagePath,
            new NotificationService(
                new NotificationRepository($this->pdo),
                new PushSubscriptionRepository($this->pdo, $encryption),
                $this->createMock(WebPush::class)
            )
        );
    }

    public function testHandleDoesNotResetSettingsWhenTheSafetyBackupFails(): void
    {
        $this->handler->handle(['requested_by_user_account_id' => $this->userId], $this->context);

        $this->settings->clearCache();
        $this->assertSame('changed_val', $this->settings->get('some_setting'));
    }

    public function testHandleNotifiesRequesterOnFailure(): void
    {
        $this->handler->handle(['requested_by_user_account_id' => $this->userId], $this->context);

        $notifications = (new NotificationRepository($this->pdo))->findByUserAccountId($this->userId);
        $this->assertCount(1, $notifications);
        $this->assertSame('Échec de la réinitialisation', $notifications[0]->title);
    }

    public function testHandleJournalsFailureWhenNoRequester(): void
    {
        $this->handler->handle([], $this->context);

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'settings_reset_failed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }
}
