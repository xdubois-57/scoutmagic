<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Task\RestoreBackupHandler;
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
 *
 * Like ResetSettingsHandlerTest/InstallUpdateHandlerTest, the safety-backup
 * step (real mysqldump) fails fast against the fake connection used here,
 * so these tests cover the outer guard: nothing about the current install
 * is touched and the requester is notified when even the safety backup
 * can't be taken. Source resolution (server backup lookup, uploaded-zip
 * integrity validation) and the rollback path only run once that backup
 * succeeds, so they are not exercised without a live MySQL server — same
 * accepted trade-off as CreateBackupHandlerTest/InstallUpdateHandlerTest.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RestoreBackupHandlerTest extends TestCase
{
    private \PDO $pdo;
    private RestoreBackupHandler $handler;
    private TaskContext $context;
    private string $storagePath;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->handler = new RestoreBackupHandler();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->storagePath = sys_get_temp_dir() . '/restore_backup_handler_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

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

    public function testHandleNotifiesRequesterWhenTheSafetyBackupFails(): void
    {
        $this->handler->handle(['source' => 'server', 'backup_id' => 1, 'requested_by_user_account_id' => $this->userId], $this->context);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $notifications = (new NotificationRepository($this->pdo, $encryption))->findByUserAccountId($this->userId);
        $this->assertCount(1, $notifications);
        $this->assertSame('Échec de la restauration', $notifications[0]->title);
    }

    public function testHandleJournalsFailureOfTheSafetyBackup(): void
    {
        $this->handler->handle(['source' => 'server', 'backup_id' => 1], $this->context);

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'backup_restore_failed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }

    /**
     * **A portable archive is refused before the safety backup is taken.**
     *
     * The distinction this test makes is the whole point, and it is
     * visible precisely because the safety backup fails fast against the
     * fake connection used here: if the guard ran late — as the first
     * version did, from inside `resolveSource()` — the handler would have
     * gone through `ensureRoomForDumpAndArchive()`, `createDatabaseDump()`
     * and `createFileBackup(true)` first, and would journal
     * `backup_restore_failed` like every other test in this class. It
     * journals `backup_restore_refused` instead, and nothing else, which
     * can only happen if it returned before touching anything.
     *
     * On a real installation the difference is minutes of dumping and
     * zipping, followed by a rollback that restores the database and the
     * files all over again — for an operation that could never finish.
     */
    public function testAPortableBackupIsRefusedBeforeTheSafetyBackupIsEvenAttempted(): void
    {
        $backupId = (new \Core\Maintenance\BackupRepository($this->pdo))
            ->create(\Core\Maintenance\Backup::PORTABLE_TYPE, $this->userId);
        $this->pdo->prepare("UPDATE backups SET status = 'completed' WHERE id = ?")->execute([$backupId]);

        $this->handler->handle([
            'source' => 'server',
            'backup_id' => $backupId,
            'requested_by_user_account_id' => $this->userId,
        ], $this->context);

        $refused = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'backup_restore_refused'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $refused, 'the refusal has to be recorded');

        $failed = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'backup_restore_failed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(
            0,
            $failed,
            'the safety backup was attempted — so the refusal came too late to save anything'
        );
    }

    /** And the operator is told what a portable archive is actually for. */
    public function testTheRefusalTellsTheRequesterWhatToDoInstead(): void
    {
        $backupId = (new \Core\Maintenance\BackupRepository($this->pdo))
            ->create(\Core\Maintenance\Backup::PORTABLE_TYPE, $this->userId);
        $this->pdo->prepare("UPDATE backups SET status = 'completed' WHERE id = ?")->execute([$backupId]);

        $this->handler->handle([
            'source' => 'server',
            'backup_id' => $backupId,
            'requested_by_user_account_id' => $this->userId,
        ], $this->context);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $notifications = (new NotificationRepository($this->pdo, $encryption))->findByUserAccountId($this->userId);

        $this->assertCount(1, $notifications);
        $this->assertSame('Restauration impossible', $notifications[0]->title);
    }

    /** An ordinary backup is not caught by that guard. */
    public function testAnOrdinaryBackupStillReachesTheSafetyBackupStep(): void
    {
        $backupId = (new \Core\Maintenance\BackupRepository($this->pdo))->create('full_no_gallery', $this->userId);
        $this->pdo->prepare("UPDATE backups SET status = 'completed' WHERE id = ?")->execute([$backupId]);

        $this->handler->handle(['source' => 'server', 'backup_id' => $backupId], $this->context);

        // It fails there, against this fake connection — which is the
        // proof that it got that far.
        $rows = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'backup_restore_failed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }

    public function testHandleCleansUpTheUploadedTempFileEvenOnFailure(): void
    {
        $tempPath = $this->storagePath . '/uploaded.zip';
        file_put_contents($tempPath, 'fake-zip-bytes');

        $this->handler->handle(['source' => 'upload', 'uploaded_temp_path' => $tempPath], $this->context);

        $this->assertFileDoesNotExist($tempPath);
    }
}
