<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Task\InstallUpdateHandler;
use Core\Maintenance\UpdateHistoryRepository;
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
 * The full success/rollback pipeline (real mysqldump/mysql/ZipArchive I/O)
 * is exercised by Core\Maintenance\BackupServiceTest, gated behind a real
 * TEST_DB_* server — InstallUpdateHandler only orchestrates calls into
 * BackupService, so these tests cover its own logic (status transitions,
 * no-op guards, notifications, temp-directory cleanup) using a connection
 * with no real database, which makes the safety-backup step itself fail
 * fast — the same trade-off already made in CreateBackupHandlerTest.
 */
class InstallUpdateHandlerTest extends TestCase
{
    private \PDO $pdo;
    private InstallUpdateHandler $handler;
    private UpdateHistoryRepository $updateHistoryRepository;
    private TaskContext $context;
    private string $storagePath;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->updateHistoryRepository = new UpdateHistoryRepository($this->pdo);
        $this->handler = new InstallUpdateHandler();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        $settingRepository = new SettingRepository($this->pdo);
        $settings = new SettingService($settingRepository);
        $settings->register('site_version', '0.0.0', 'text', 'Version', 'Version', null, null, null, false);

        $this->storagePath = sys_get_temp_dir() . '/install_update_handler_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        // Fake credentials — the safety backup step (mysqldump) fails fast
        // against these, which is exactly the branch these tests exercise
        // (see class docblock).
        $connection = Connection::withPdo($this->pdo);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath,
            new NotificationService(
                new NotificationRepository($this->pdo),
                new PushSubscriptionRepository($this->pdo, $encryption),
                $this->createMock(WebPush::class)
            )
        );
    }

    public function testHandleNoOpsWhenHistoryIdIsMissing(): void
    {
        $this->handler->handle(['download_url' => 'https://example.test/artifact.zip'], $this->context);
        $this->addToAssertionCount(1);
    }

    public function testHandleNoOpsWhenDownloadUrlIsMissing(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->handler->handle(['history_id' => $id], $this->context);

        $this->assertSame('pending', $this->updateHistoryRepository->findById($id)->status);
    }

    public function testHandleNoOpsWhenHistoryDoesNotExist(): void
    {
        $this->handler->handle(['history_id' => 999999, 'download_url' => 'https://example.test/artifact.zip'], $this->context);
        $this->addToAssertionCount(1);
    }

    public function testHandleNoOpsWhenHistoryIsAlreadyNotPending(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->updateHistoryRepository->markCompleted($id);

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $this->assertSame('completed', $this->updateHistoryRepository->findById($id)->status);
    }

    public function testHandleMarksFailedAndNotifiesWhenTheSafetyBackupFails(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $history = $this->updateHistoryRepository->findById($id);
        $this->assertSame('failed', $history->status);
        $this->assertNotNull($history->errorMessage);
        $this->assertNull($history->backupId);

        $notifications = (new NotificationRepository($this->pdo))->findByUserAccountId($this->userId);
        $this->assertCount(1, $notifications);
        $this->assertSame('Échec de la mise à jour', $notifications[0]->title);
    }

    public function testHandleRemovesLeftoverTempDirectoryEvenOnFailure(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, $this->userId);

        $tempDir = $this->storagePath . '/temp/update_' . $id;
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/stray.txt', 'leftover from a previous crashed run');

        $this->handler->handle(['history_id' => $id, 'download_url' => 'https://example.test/artifact.zip'], $this->context);

        $this->assertDirectoryDoesNotExist($tempDir);
    }
}
