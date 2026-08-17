<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupService;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase
{
    private string $basePath;
    private string $storagePath;
    private BackupService $service;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/backup_test_' . uniqid();
        $this->storagePath = $this->basePath . '/storage';

        // Minimal fake site tree: core/modules/public/storage with a
        // handful of files, including the sensitive/excluded directories
        // and a gallery media file, to exercise the include/exclude logic
        // without touching the real project tree.
        $this->makeFile('core/App.php', '<?php // app');
        $this->makeFile('modules/gallery/module.json', '{}');
        $this->makeFile('public/index.php', '<?php // entry');
        $this->makeFile('storage/keys/master.key', 'secret-key-bytes');
        $this->makeFile('storage/config/secrets.enc', 'secret-config');
        $this->makeFile('storage/temp/scratch.txt', 'ephemeral');
        $this->makeFile('storage/gallery/1/photo.jpg', 'fake-jpeg-bytes');
        $this->makeFile('storage/uploads/doc.pdf', 'fake-pdf-bytes');

        // Fake credentials — fine for tests that never actually shell out
        // to mysqldump (scope/password validation, file backup, encryption
        // support check all fail/succeed before touching the database).
        $connection = new Connection('127.0.0.1', 3306, 'nonexistent_db', 'nobody', '');
        $this->service = new BackupService($connection, $this->storagePath, $this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    private function makeFile(string $relativePath, string $content): void
    {
        $path = $this->basePath . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    public function testSupportsZipEncryptionOnThisEnvironment(): void
    {
        // Documents the actual capability of the CI/dev environment this
        // suite runs in — if this ever flips to false, every full-backup
        // test below (and the feature itself) needs re-evaluating.
        $this->assertTrue($this->service->supportsZipEncryption());
    }

    public function testCreateFileBackupExcludesKeysConfigAndTempByDefault(): void
    {
        $zipPath = $this->service->createFileBackup(true);
        $entries = $this->zipEntryNames($zipPath);

        $this->assertNotContains('storage/keys/master.key', $entries);
        $this->assertNotContains('storage/config/secrets.enc', $entries);
        $this->assertNotContains('storage/temp/scratch.txt', $entries);
        $this->assertContains('core/App.php', $entries);
        $this->assertContains('public/index.php', $entries);
        $this->assertContains('storage/uploads/doc.pdf', $entries);

        unlink($zipPath);
    }

    public function testCreateFileBackupExcludesGalleryByDefault(): void
    {
        $zipPath = $this->service->createFileBackup(false);
        $entries = $this->zipEntryNames($zipPath);

        $this->assertNotContains('storage/gallery/1/photo.jpg', $entries);
        $this->assertContains('storage/uploads/doc.pdf', $entries);

        unlink($zipPath);
    }

    public function testCreateFileBackupIncludesGalleryWhenRequested(): void
    {
        $zipPath = $this->service->createFileBackup(true);
        $entries = $this->zipEntryNames($zipPath);

        $this->assertContains('storage/gallery/1/photo.jpg', $entries);

        unlink($zipPath);
    }

    public function testCreateFullBackupRejectsInvalidScope(): void
    {
        $this->expectException(BackupException::class);
        $this->service->createFullBackup('not_a_real_scope', 'password123');
    }

    public function testCreateFullBackupRejectsEmptyPassword(): void
    {
        $this->expectException(BackupException::class);
        $this->service->createFullBackup('full_config', '');
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testCreateDatabaseDumpProducesANonEmptySqlFile(): void
    {
        $service = $this->realDbService();

        $path = $service->createDatabaseDump();

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        unlink($path);
    }

    /**
     * End-to-end proof that restoreDatabase() (Core\Database\
     * DatabaseRestorer, pure PHP — see its own docblock for why this
     * replaced shelling out to a `mysql` binary) actually round-trips a
     * real dump: insert a row with content designed to break a naive
     * statement splitter (a semicolon and a quote in the same string),
     * dump the whole database, delete the row, restore, and confirm the
     * exact row is back.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testRestoreDatabaseRoundTripsARealDumpIncludingTrickyContent(): void
    {
        $connection = $this->realDbConnection();
        $service = new BackupService($connection, $this->storagePath, $this->basePath);
        $pdo = $connection->getPdo();

        $trickyValue = "rendez-vous à 14h; n'oublie pas!";
        $settingKey = 'backup_restore_test_' . bin2hex(random_bytes(4));

        $pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (NULL, ?, ?, \'text\', \'test\', \'test\')'
        )->execute([$settingKey, $trickyValue]);

        $dumpPath = $service->createDatabaseDump();

        $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$settingKey]);
        $this->assertFalse(
            $this->fetchSettingValue($pdo, $settingKey),
            'row should be gone before restore — otherwise the assertion below proves nothing'
        );

        $service->restoreDatabase($dumpPath);

        $this->assertSame($trickyValue, $this->fetchSettingValue($pdo, $settingKey));

        $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$settingKey]);
        unlink($dumpPath);
    }

    private function fetchSettingValue(\PDO $pdo, string $settingKey): string|false
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$settingKey]);
        $value = $stmt->fetchColumn();
        return $value === null ? false : $value;
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testCreateFullBackupProducesAZipWhoseDatabaseEntryNeedsThePassword(): void
    {
        $service = $this->realDbService();

        $result = $service->createFullBackup('full_config', 'correct-horse-battery-staple');

        $this->assertFileExists($result['zipPath']);
        $this->assertFileExists($result['dbDumpPath']);

        $zip = new \ZipArchive();
        $zip->open($result['zipPath']);
        $zip->setPassword('correct-horse-battery-staple');
        $this->assertNotFalse($zip->getFromName('database.sql'));
        $zip->close();

        // Wrong password must fail to decrypt.
        $zip2 = new \ZipArchive();
        $zip2->open($result['zipPath']);
        $zip2->setPassword('wrong-password');
        $this->assertFalse(@$zip2->getFromName('database.sql'));
        $zip2->close();

        unlink($result['zipPath']);
        unlink($result['dbDumpPath']);
    }

    /**
     * Builds a BackupService against a real, reachable MySQL server — same
     * TEST_DB_* env var convention as SetupControllerTest — skipping
     * rather than failing when none is available, since mysqldump/mysql
     * genuinely need a live server this sandbox doesn't always have.
     *
     * This test needs `settings`/`module_registry` (Core\Maintenance\
     * BackupService::CONFIG_ONLY_TABLES) to actually exist — since this is
     * a real, persistent MySQL shared across the whole @group database
     * run (unlike the SQLite-per-test default suite), relying on some
     * other, unrelated test class happening to have migrated the schema
     * first (and not cleaned up after itself) is exactly the kind of
     * execution-order fragility this suite has been fixing elsewhere —
     * so migrate the real schema here too, idempotently.
     */
    private function realDbService(): BackupService
    {
        return new BackupService($this->realDbConnection(), $this->storagePath, $this->basePath);
    }

    private function realDbConnection(): Connection
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database not available: ' . (is_string($result) ? $result : 'unknown error'));
        }

        $introspector = new SchemaIntrospector($connection->getPdo());
        $runner = new MigrationRunner($connection, $introspector, new SchemaComparator(), new SqlParser());
        $runner->migrate([dirname(__DIR__, 3) . '/schema/core.sql']);

        return $connection;
    }

    /**
     * @return string[]
     */
    private function zipEntryNames(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $names;
    }
}
