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
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    private function fakeBackupService(): BackupServiceInterface
    {
        $dir = sys_get_temp_dir() . '/auto_backup_fake_' . uniqid();
        mkdir($dir, 0755, true);

        return new class ($dir) implements BackupServiceInterface {
            public bool $includeGalleryRequested = false;

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

            public function createFileBackup(bool $includeGallery = false): string
            {
                $this->includeGalleryRequested = $includeGallery;
                $path = $this->dir . '/files_' . bin2hex(random_bytes(4)) . '.zip';
                $zip = new \ZipArchive();
                $zip->open($path, \ZipArchive::CREATE);
                $zip->addFromString('marker.txt', 'fake backup');
                $zip->close();
                return $path;
            }

            public function createFullBackup(string $scope, string $password): array
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

    public function testHandleNeverIncludesTheGallery(): void
    {
        $this->settings->set('backup_auto_frequency', 'daily');
        $fake = $this->fakeBackupService();
        $handler = new AutoBackupHandler($fake);

        $handler->handle([], $this->context);

        $this->assertFalse($fake->includeGalleryRequested);
    }

    public function testHandleUpdatesLastRunSetting(): void
    {
        $this->settings->set('backup_auto_frequency', 'monthly');
        $handler = new AutoBackupHandler($this->fakeBackupService());

        $handler->handle([], $this->context);

        $this->settings->clearCache();
        $this->assertNotSame('', $this->settings->get('backup_auto_last_run'));
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

    public function testHandleJournalsFailureWhenTheBackupItselfFails(): void
    {
        $this->settings->set('backup_auto_frequency', 'daily');
        $failing = new class implements BackupServiceInterface {
            public function createDatabaseDump(): string { throw new \RuntimeException('mysqldump unavailable'); }
            public function createConfigOnlyDump(): string { return $this->createDatabaseDump(); }
            public function createFileBackup(bool $includeGallery = false): string { return ''; }
            public function createFullBackup(string $scope, string $password): array { return ['zipPath' => '', 'dbDumpPath' => '']; }
            public function supportsZipEncryption(): bool { return true; }
            public function restoreDatabase(string $dumpPath): void {}
            public function restoreFiles(string $archivePath, ?string $password = null): void {}
        };
        $handler = new AutoBackupHandler($failing);

        $handler->handle([], $this->context);

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'auto_backup_failed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame([], $this->backupRepository->findRecent(5));
    }
}
