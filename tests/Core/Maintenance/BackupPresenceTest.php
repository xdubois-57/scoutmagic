<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\File\FileRepository;
use Core\Maintenance\BackupPresence;
use Core\Maintenance\BackupRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What a backup row offers follows what is on the disk now (#847): a
 * `files` row and a readable file, for each component the backup declares.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BackupPresenceTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private FileRepository $files;
    private BackupRepository $backups;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/backup_presence_' . bin2hex(random_bytes(4));
        mkdir($this->storagePath . '/maintenance', 0700, true);
        $this->files = new FileRepository($this->pdo);
        $this->backups = new BackupRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->storagePath . '/maintenance/*') ?: []);
        @rmdir($this->storagePath . '/maintenance');
        @rmdir($this->storagePath);
    }

    public function testBothPresent(): void
    {
        $presence = $this->presence();
        $backup = $this->backup($this->file('a.zip'), $this->file('a.sql'));

        $this->assertSame(['archive' => true, 'dump' => true], $presence->of($backup));
        $this->assertNull($presence->refusal($backup));
    }

    public function testAFileGoneFromTheDiskIsAbsent(): void
    {
        $backup = $this->backup($this->file('a.zip'), $this->file('a.sql'));
        unlink($this->storagePath . '/maintenance/a.sql');

        $this->assertSame(['archive' => true, 'dump' => false], $this->presence()->of($backup));
        $this->assertStringContainsString('sa base de données n\'est plus sur ce serveur', (string) $this->presence()->refusal($backup));
    }

    public function testAFilesRowGoneIsAbsent(): void
    {
        $archive = $this->file('a.zip');
        $backup = $this->backup($archive, $this->file('a.sql'));
        $this->files->delete($archive);

        $this->assertSame(['archive' => false, 'dump' => true], $this->presence()->of($backup));
        $this->assertStringContainsString('son archive n\'est plus sur ce serveur', (string) $this->presence()->refusal($backup));
    }

    /** A completed backup that lost its archive reference has lost the archive. */
    public function testACompletedBackupWithoutItsArchiveReferenceHasLostIt(): void
    {
        $backup = $this->backup(null, $this->file('a.sql'));

        $this->assertSame(['archive' => false, 'dump' => true], $this->presence()->of($backup));
        $this->assertFalse($this->presence()->isComplete($backup));
        $this->assertStringContainsString(
            'son archive n\'est plus sur ce serveur',
            (string) $this->presence()->refusal($backup)
        );
    }

    /** A backup still running has declared nothing yet: neither present nor absent. */
    public function testABackupNotYetCompletedIsNeitherPresentNorAbsent(): void
    {
        $id = $this->backups->create('full_no_gallery', null);
        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);

        $this->assertSame(['archive' => null, 'dump' => null], $this->presence()->of($backup));
    }

    /**
     * A completed backup whose dump reference was cleared — its `files` row
     * deleted by a purge interrupted before the `backups` row — has lost
     * its dump: refused here, before any safety copy.
     */
    public function testACompletedBackupWithoutItsDumpReferenceHasLostIt(): void
    {
        $backup = $this->backup($this->file('a.zip'), null);

        $this->assertSame(['archive' => true, 'dump' => false], $this->presence()->of($backup));
        $this->assertFalse($this->presence()->isComplete($backup));
        $this->assertStringContainsString(
            'sa base de données n\'est plus sur ce serveur',
            (string) $this->presence()->refusal($backup)
        );
    }

    public function testBothGoneSaysTheFiles(): void
    {
        $backup = $this->backup($this->file('a.zip'), $this->file('a.sql'));
        array_map('unlink', glob($this->storagePath . '/maintenance/*') ?: []);

        $this->assertStringContainsString('ses fichiers ne sont plus sur ce serveur', (string) $this->presence()->refusal($backup));
    }

    private function presence(): BackupPresence
    {
        return new BackupPresence($this->files, $this->storagePath);
    }

    private function file(string $name): int
    {
        file_put_contents($this->storagePath . '/maintenance/' . $name, 'x');

        return $this->files->create('maintenance/' . $name, $name, 'application/octet-stream', 1, 'superadmin', null, null);
    }

    private function backup(?int $archive, ?int $dump): \Core\Maintenance\Backup
    {
        $id = $this->backups->create('full_no_gallery', null);
        $this->backups->markCompleted($id, $archive, $dump);

        return $this->backups->findById($id) ?? throw new \RuntimeException('no backup');
    }
}
