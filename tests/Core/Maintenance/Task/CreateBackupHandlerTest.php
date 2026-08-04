<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\Task\CreateBackupHandler;
use Core\Mail\MailService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class CreateBackupHandlerTest extends TestCase
{
    private \PDO $pdo;
    private CreateBackupHandler $handler;
    private BackupRepository $backupRepository;
    private TaskContext $context;
    private string $storagePath;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->backupRepository = new BackupRepository($this->pdo);
        $this->handler = new CreateBackupHandler();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('backup-requester@test.example'), $encryption->blindIndex('backup-requester@test.example')]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->storagePath = sys_get_temp_dir() . '/create_backup_handler_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        // The handler resolves the backup row from $context->connection
        // itself (it builds its own BackupRepository), so this must wrap
        // the same SQLite test database as everything else here, not a
        // real MySQL connection — the actual mysqldump/zip generation path
        // needs a live server and isn't exercised by this test class (see
        // BackupServiceTest for that, gated behind @group database + a
        // reachable TEST_DB_* server).
        $connection = Connection::withPdo($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            $journalService,
            $settingService,
            $userAccountRepository,
            $this->storagePath,
            new NotificationService(
                new NotificationRepository($this->pdo, $encryption),
                new PushSubscriptionRepository($this->pdo, $encryption),
                new NotificationPreferenceRepository($this->pdo),
                $this->createMock(WebPush::class),
                $settingService,
                $journalService,
                new SchedulerService(new SchedulerRepository($this->pdo)),
                $userAccountRepository
            )
        );
    }

    public function testHandleNoOpsWhenBackupIdIsMissing(): void
    {
        $this->handler->handle(['scope' => 'full_config', 'encrypted_password' => 'x'], $this->context);
        $this->addToAssertionCount(1);
    }

    public function testHandleNoOpsWhenScopeIsMissing(): void
    {
        $id = $this->backupRepository->create('full_config', $this->userId);

        $this->handler->handle(['backup_id' => $id, 'encrypted_password' => 'x'], $this->context);

        $this->assertSame('pending', $this->backupRepository->findById($id)->status);
    }

    public function testHandleNoOpsWhenBackupDoesNotExist(): void
    {
        $this->handler->handle(['backup_id' => 999999, 'scope' => 'full_config', 'encrypted_password' => 'x'], $this->context);
        $this->addToAssertionCount(1);
    }

    public function testHandleNoOpsWhenBackupIsAlreadyNotPending(): void
    {
        $id = $this->backupRepository->create('full_config', $this->userId);
        $this->backupRepository->markCompleted($id, 1, null);

        $this->handler->handle(['backup_id' => $id, 'scope' => 'full_config', 'encrypted_password' => 'x'], $this->context);

        // Still completed — the handler must not have touched it again
        // (e.g. reset to in_progress).
        $backup = $this->backupRepository->findById($id);
        $this->assertSame('completed', $backup->status);
    }

    public function testHandleMarksFailedWhenPasswordCannotBeDecoded(): void
    {
        $id = $this->backupRepository->create('full_config', $this->userId);

        $this->handler->handle(['backup_id' => $id, 'scope' => 'full_config', 'encrypted_password' => 'not-valid-base64!!!'], $this->context);

        $backup = $this->backupRepository->findById($id);
        $this->assertSame('failed', $backup->status);
        $this->assertNotNull($backup->errorMessage);
    }

    public function testHandleNotifiesRequesterOnFailure(): void
    {
        $id = $this->backupRepository->create('full_config', $this->userId);

        $this->handler->handle(['backup_id' => $id, 'scope' => 'full_config', 'encrypted_password' => 'not-valid-base64!!!'], $this->context);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $notifications = (new NotificationRepository($this->pdo, $encryption))->findByUserAccountId($this->userId);
        $this->assertCount(1, $notifications);
        $this->assertSame('Échec de la sauvegarde', $notifications[0]->title);
        $this->assertSame('core.backup_failed', $notifications[0]->typeId);
    }
}
