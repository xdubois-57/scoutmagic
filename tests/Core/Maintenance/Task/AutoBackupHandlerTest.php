<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupServiceInterface;
use Core\Maintenance\Task\AutoBackupHandler;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The safety-net BackupServiceInterface fake (same seam as
 * FullResetHandlerTest) lets these tests exercise the real
 * perform-backup/purge/self-reschedule orchestration without needing a
 * live MySQL server.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AutoBackupHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private BackupRepository $backupRepository;
    private SchedulerRepository $schedulerRepository;
    private TaskContext $context;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('backup_auto_frequency', 'monthly', 'select', 'L', 'D', null, null, ['none', 'daily', 'weekly', 'biweekly', 'monthly']);
        $this->settings->register('backup_auto_last_run', '', 'text', 'L', 'D');

        $this->backupRepository = new BackupRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);

        $this->storagePath = sys_get_temp_dir() . '/auto_backup_handler_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        $connection = Connection::withPdo($this->pdo);
        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    private function fakeBackupService(?string $dir = null): BackupServiceInterface
    {
        $dir ??= sys_get_temp_dir() . '/auto_backup_fake_' . uniqid();
        @mkdir($dir, 0755, true);

        return new class ($dir) implements BackupServiceInterface {
            // Nothing to reserve: this fake writes a couple of bytes.
            public function ensureRoomForDumpAndArchive(int $extraBytes = 0): void
            {
            }

            public function __construct(private string $dir)
            {
            }

            public function createDatabaseDump(): string
            {
                $path = $this->dir . '/database_' . bin2hex(random_bytes(4)) . '.sql';
                file_put_contents($path, '-- fake dump');
                return $path;
            }

            public function createConfigOnlyDump(): string
            {
                return $this->createDatabaseDump();
            }

            public function createFileBackup(?string $password = null): string
            {
                $path = $this->dir . '/files_' . bin2hex(random_bytes(4)) . '.zip';
                $zip = new \ZipArchive();
                $zip->open($path, \ZipArchive::CREATE);
                $zip->addFromString('marker.txt', 'fake backup');
                if ($password !== null) {
                    $zip->setEncryptionName('marker.txt', \ZipArchive::EM_AES_256, $password);
                }
                $zip->close();
                return $path;
            }

            public function createFullBackup(string $scope, ?string $password): array
            {
                return ['zipPath' => $this->createFileBackup(), 'dbDumpPath' => $this->createDatabaseDump()];
            }

            public function supportsZipEncryption(): bool
            {
                return true;
            }

            public function restoreDatabase(string $dumpPath): void
            {
            }

            public function restoreFiles(string $archivePath, ?string $password = null): void
            {
            }
        };
    }

    public function testHandleCreatesAnAutoBackupRowWhenFrequencyIsActive(): void
    {
        $this->settings->set('backup_auto_frequency', 'weekly');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $recent = $this->backupRepository->findRecent(5);
        $this->assertCount(1, $recent);
        $this->assertSame('auto_backup', $recent[0]->type);
        $this->assertSame('completed', $recent[0]->status);
    }

    /**
     * The automatic backup is encrypted at its creation (issue #619,
     * IT-03b), with a password kept under its row and able to open it.
     */
    public function testTheAutomaticBackupIsEncryptedWithAPasswordKeptUnderItsRow(): void
    {
        $secrets = new \Core\Security\SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $secrets->generateMasterKey();
        $secrets->writeSecrets([]);
        $this->settings->set('backup_auto_frequency', 'weekly');

        (new AutoBackupHandler($this->fakeBackupService($this->storagePath . '/backups')))->handle([], $this->context);

        $backup = $this->backupRepository->findRecent(1)[0];
        $password = \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->passwordFor($backup->id);
        $this->assertNotNull($password);
        $file = (new \Core\File\FileRepository($this->pdo))->findById((int) $backup->fileId);
        $zip = new \ZipArchive();
        $zip->open($this->storagePath . '/' . $file->relativePath);
        $this->assertNotSame(0, $zip->statName('marker.txt')['encryption_method']);
        $zip->setPassword($password);
        $this->assertSame('fake backup', $zip->getFromName('marker.txt'));
        $zip->close();
    }

    public function testHandleUpdatesLastRunSetting(): void
    {
        $this->settings->set('backup_auto_frequency', 'monthly');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $this->settings->clearCache();
        $this->assertNotSame('', $this->settings->get('backup_auto_last_run'));
    }

    /**
     * With nothing stored, the handler backs up at the frequency the
     * screen displays.
     *
     * `public/index.php` registers `backup_auto_frequency` with
     * « weekly » and `MaintenanceController::index()` shows « weekly »
     * when it reads nothing, but this handler's own fallback still said
     * « monthly » — the value issue #286 moved away from. Unreachable on
     * an installation whose settings row exists, and precisely for that
     * reason the worst place to disagree: the one situation that reaches
     * it is the one where the screen and the scheduler would tell an
     * operator two different things about the same setting.
     *
     * The row is removed rather than emptied, because `set()` refuses an
     * empty value outright — a missing row is the only way this fallback
     * is ever reached, which is the whole reason it went unnoticed.
     */
    public function testAnUnsetFrequencyBacksUpAtTheFrequencyTheScreenShows(): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM settings WHERE module_id IS NULL AND setting_key = ?'
        );
        $statement->execute(['backup_auto_frequency']);
        $this->settings->clearCache();
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $this->assertCount(1, $this->backupRepository->findRecent(5));
        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'auto_backup', 'auto');
        $this->assertNotNull($scheduled);
        $runAt = strtotime($scheduled['run_at']);
        $this->assertGreaterThan(strtotime('+6 days'), $runAt);
        $this->assertLessThan(strtotime('+8 days'), $runAt);
    }

    public function testHandleSkipsBackupWhenFrequencyIsNone(): void
    {
        $this->settings->set('backup_auto_frequency', 'none');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $this->assertSame([], $this->backupRepository->findRecent(5));
    }

    public function testHandleSelfReschedulesAccordingToFrequency(): void
    {
        $this->settings->set('backup_auto_frequency', 'weekly');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'auto_backup', 'auto');
        $this->assertNotNull($scheduled);
        $runAt = strtotime($scheduled['run_at']);
        $this->assertGreaterThan(strtotime('+6 days'), $runAt);
        $this->assertLessThan(strtotime('+8 days'), $runAt);
    }

    public function testHandleStillReschedulesWhenFrequencyIsNone(): void
    {
        $this->settings->set('backup_auto_frequency', 'none');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'auto_backup', 'auto');
        $this->assertNotNull($scheduled);
    }

    public function testHandleDoesNotDuplicateAnAlreadyScheduledFutureRun(): void
    {
        $this->settings->set('backup_auto_frequency', 'daily');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);
        $handler->handle([], $this->context);

        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'auto_backup', 10);
        $this->assertCount(1, $all);
    }

    private function failingBackupService(): BackupServiceInterface
    {
        return new class implements BackupServiceInterface {
            // Nothing to reserve: this fake writes a couple of bytes.
            public function ensureRoomForDumpAndArchive(int $extraBytes = 0): void
            {
            }

            public function createDatabaseDump(): string { throw new \RuntimeException('mysqldump unavailable'); }
            public function createConfigOnlyDump(): string { return $this->createDatabaseDump(); }
            public function createFileBackup(?string $password = null): string { return ''; }
            public function createFullBackup(string $scope, ?string $password): array
            {
                return ['zipPath' => '', 'dbDumpPath' => ''];
            }
            public function supportsZipEncryption(): bool { return true; }
            public function restoreDatabase(string $dumpPath): void {}
            public function restoreFiles(string $archivePath, ?string $password = null): void {}
        };
    }

    /**
     * A failed backup is journaled at `error` — somebody has to free the
     * space or fix the cause — and leaves handle() as an exception, after
     * the next run is queued: the chain survives a full disk (issue #1450).
     */
    public function testHandleJournalsFailureWhenTheBackupItselfFails(): void
    {
        $this->settings->set('backup_auto_frequency', 'daily');
        $handler = new AutoBackupHandler($this->failingBackupService());

        try {
            $handler->handle([], $this->context);
            $this->fail('a failed backup must not return as if it had succeeded');
        } catch (\RuntimeException $e) {
            $this->assertSame('mysqldump unavailable', $e->getMessage());
        }

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'auto_backup_failed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]['level']);
        $this->assertSame([], $this->backupRepository->findRecent(5));

        $next = $this->schedulerRepository->findByModuleAndKey('core', 'auto_backup', 'auto');
        $this->assertNotNull($next, 'the daily chain must be re-armed even when the backup failed');
        $this->assertSame('pending', $next['status']);
        $this->assertGreaterThan(time(), strtotime($next['run_at']));
    }

    /**
     * The scenario of issue #1450, through the real runner: the night's
     * backup fails, and the task is marked failed — never « terminée » —
     * while tomorrow's run is still queued.
     */
    public function testAFailedAutomaticBackupIsAFailedTaskNotACompletedOne(): void
    {
        $this->settings->set('backup_auto_frequency', 'daily');
        $journal = new JournalService(new JournalRepository($this->pdo));
        $runner = new SchedulerRunner($this->schedulerRepository, $journal);
        $runner->setTaskContext($this->context);
        $runner->registerHandler('core', 'auto_backup', new AutoBackupHandler($this->failingBackupService()));
        $taskId = $this->schedulerRepository->create(
            'core',
            'auto_backup',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            null,
            'auto'
        );

        $this->assertSame(0, $runner->processOverdue());

        $task = $this->schedulerRepository->findById($taskId);
        $this->assertNotNull($task);
        $this->assertSame('failed', $task['status']);

        $levels = $this->pdo->query('SELECT event_type, level FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertArrayNotHasKey('scheduler_task_done', $levels, 'a failed backup was reported « terminée »');
        $this->assertSame('error', $levels['auto_backup_failed'] ?? null);
        $this->assertSame('error', $levels['scheduler_task_failed'] ?? null);

        $pending = array_filter(
            $this->schedulerRepository->findByModuleAndTaskKey('core', 'auto_backup', 10),
            static fn (array $row): bool => $row['status'] === 'pending'
        );
        $this->assertCount(1, $pending, 'the next automatic backup is no longer queued');
    }
}
