<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupServiceInterface;
use Core\Maintenance\Task\FullResetHandler;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The safety backup (mysqldump/ZipArchive shell-out) is faked via
 * BackupServiceInterface so these tests exercise FullResetHandler's own
 * truncate/wipe/preserve logic for real, against a real (SQLite) database
 * and a real synthetic storage/ tree — without needing a live MySQL server.
 */
class FullResetHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $basePath;
    private string $storagePath;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->basePath = sys_get_temp_dir() . '/full_reset_handler_test_' . uniqid();
        $this->storagePath = $this->basePath . '/storage';
        mkdir($this->storagePath . '/keys', 0755, true);
        mkdir($this->storagePath . '/config', 0755, true);
        mkdir($this->storagePath . '/uploads', 0755, true);
        mkdir($this->basePath . '/schema', 0755, true);
        file_put_contents($this->storagePath . '/keys/master.key', 'the-master-key-bytes');
        file_put_contents($this->storagePath . '/config/secrets.enc', 'encrypted-secrets');
        file_put_contents($this->storagePath . '/uploads/doc.pdf', 'fake-pdf-bytes');

        $connection = Connection::withPdo($this->pdo);
        $settings = new SettingService(new SettingRepository($this->pdo));

        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    private function fakeBackupService(): BackupServiceInterface
    {
        $dbDumpDir = sys_get_temp_dir() . '/full_reset_fake_backups_' . uniqid();
        mkdir($dbDumpDir, 0755, true);

        return new class ($dbDumpDir) implements BackupServiceInterface {
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

            public function createFileBackup(): string
            {
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

    public function testHandleWipesAllTableDataButPreservesMasterKeyAndSafetyBackup(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn());

        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn());
    }

    public function testHandleDeletesSecretsEncButKeepsMasterKey(): void
    {
        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertFileDoesNotExist($this->storagePath . '/config/secrets.enc');
        $this->assertFileExists($this->storagePath . '/keys/master.key');
        $this->assertSame('the-master-key-bytes', file_get_contents($this->storagePath . '/keys/master.key'));
    }

    public function testHandleDeletesOtherStorageFiles(): void
    {
        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertFileDoesNotExist($this->storagePath . '/uploads/doc.pdf');
    }

    /**
     * **A declared storage location survives the wipe, and it has to.**
     *
     * Until D10 the safety copy taken in step 1 contained it, so deleting
     * it here was reversible. It no longer does — no archive carries a
     * declared location — and wiping it would have made this operation
     * quietly more destructive than it had ever been, with no archive and
     * no warning between an administrator and the only copy of six years
     * of photographs.
     *
     * The undeclared folder in the same tree is asserted in the same
     * test on purpose: what survives is the DECLARATION, not a name, and
     * a reset that spared all of `storage/` would be a reset that reset
     * nothing.
     */
    public function testADeclaredLocationSurvivesTheWipeAndAnUndeclaredFolderDoesNot(): void
    {
        $this->declareLocation('Galerie', 'gallery');
        mkdir($this->storagePath . '/gallery/1', 0755, true);
        file_put_contents($this->storagePath . '/gallery/1/photo.jpg', 'fake-jpeg-bytes');

        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertFileExists(
            $this->storagePath . '/gallery/1/photo.jpg',
            'A reset destroyed a declared location that no archive covers.'
        );
        $this->assertSame('fake-jpeg-bytes', file_get_contents($this->storagePath . '/gallery/1/photo.jpg'));
        $this->assertFileDoesNotExist($this->storagePath . '/uploads/doc.pdf');
    }

    /**
     * The locations are read BEFORE the tables are emptied.
     *
     * Step 2 truncates `storage_locations`; asking afterwards which
     * directories were declared answers "none", and step 4 would then
     * delete every one of them. The bug would be invisible in any test
     * that declared nothing, which is why this one declares a location
     * whose folder is nested two levels deep — the shallow case is
     * already covered above, and a nested one also exercises the
     * recursion's preserve check rather than only its first level.
     */
    public function testALocationNestedUnderAnotherFolderAlsoSurvives(): void
    {
        $this->declareLocation('Disque monté', 'media/photos');
        mkdir($this->storagePath . '/media/photos', 0755, true);
        file_put_contents($this->storagePath . '/media/photos/holiday.jpg', 'fake-jpeg-on-a-nas');
        file_put_contents($this->storagePath . '/media/README.txt', 'not-a-location');

        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertFileExists($this->storagePath . '/media/photos/holiday.jpg');
        $this->assertFileDoesNotExist(
            $this->storagePath . '/media/README.txt',
            'Only the declared folder is spared, not the one that happens to contain it.'
        );
    }

    /**
     * Declares a local storage location the way a real installation does.
     */
    private function declareLocation(string $label, string $path): void
    {
        (new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        ))->create(StorageLocationType::Local, $label, new LocalLocationConfig($path), null);
    }

    public function testHandlePreservesTheSafetyBackupFilesUnderMaintenance(): void
    {
        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertDirectoryExists($this->storagePath . '/maintenance');
        $files = array_diff(scandir($this->storagePath . '/maintenance') ?: [], ['.', '..']);
        $this->assertCount(2, $files);
    }

    public function testHandleRecreatesEmptyDirectoryStructure(): void
    {
        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $this->assertDirectoryExists($this->storagePath . '/keys');
        $this->assertDirectoryExists($this->storagePath . '/config');
        $this->assertDirectoryExists($this->storagePath . '/temp');
    }

    public function testHandleWritesTheFirstJournalEntryOfTheNewInstallation(): void
    {
        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $rows = $this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('full_reset_performed', $rows[0]['event_type']);
        $this->assertNull($rows[0]['user_account_id']);
        $this->assertStringNotContainsString('emplacement', (string) $rows[0]['description']);
    }

    /**
     * The journal names what is still on the disk.
     *
     * An entry saying only « retour à l'état d'installation neuve » would
     * be read, by whoever comes to the journal after a reset, as an empty
     * disk — and the folders it left are exactly the ones with something
     * irreplaceable in them.
     */
    public function testTheJournalSaysAFolderWasKeptWhenOneWas(): void
    {
        $this->declareLocation('Galerie', 'gallery');
        mkdir($this->storagePath . '/gallery', 0755, true);

        $handler = new FullResetHandler($this->fakeBackupService());
        $handler->handle([], $this->context);

        $rows = $this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertStringContainsString('1 emplacement de stockage conservé', (string) $rows[0]['description']);
        $this->assertStringContainsString('aucune archive', (string) $rows[0]['description']);
    }

    public function testHandleJournalsFailureWhenTheSafetyBackupFails(): void
    {
        $failingBackupService = new class implements BackupServiceInterface {
            // Nothing to reserve: this fake writes a couple of bytes.
            public function ensureRoomForDumpAndArchive(int $extraBytes = 0): void
            {
            }

            public function createDatabaseDump(): string
            {
                throw new \RuntimeException('mysqldump unavailable');
            }
            public function createConfigOnlyDump(): string { return $this->createDatabaseDump(); }
            public function createFileBackup(): string { return ''; }
            public function createFullBackup(string $scope, string $password): array { return ['zipPath' => '', 'dbDumpPath' => '']; }
            public function supportsZipEncryption(): bool { return true; }
            public function restoreDatabase(string $dumpPath): void {}
            public function restoreFiles(string $archivePath, ?string $password = null): void {}
        };

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);

        $handler = new FullResetHandler($failingBackupService);
        $handler->handle([], $this->context);

        // Nothing was touched — the DB wipe never started.
        $this->assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM user_accounts')->fetchColumn());
        $this->assertFileExists($this->storagePath . '/config/secrets.enc');

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'full_reset_failed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }
}
