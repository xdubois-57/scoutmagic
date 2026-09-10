<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\File\FileRepository;
use Core\Maintenance\BackupIntegrity;
use Core\Maintenance\BackupIntegrityStatus;
use Core\Maintenance\BackupRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A backup already on disk, re-read and compared to what was written.
 *
 * The failure this exists for is quiet by nature: an archive is opened on
 * exactly one day, the day somebody needs it, and truncation — what a full
 * quota produces — leaves a file that looks perfectly normal in a
 * directory listing.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BackupIntegrityTest extends TestCase
{
    private \PDO $pdo;
    private BackupRepository $backups;
    private FileRepository $files;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->backups = new BackupRepository($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/integrity_' . uniqid();
        @mkdir($this->storagePath . '/maintenance', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/maintenance/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->storagePath . '/maintenance');
        @rmdir($this->storagePath);
    }

    public function testCompletingABackupRecordsADigestForEachOfItsFiles(): void
    {
        $id = $this->completed('archive.zip', 'dump.sql');

        $backup = $this->backups->findById($id);

        $this->assertNotNull($backup);
        $this->assertSame(hash('sha256', 'archive.zip contents'), $backup->archiveSha256);
        $this->assertSame(hash('sha256', 'dump.sql contents'), $backup->dbDumpSha256);
    }

    public function testAnUntouchedBackupVerifiesIntact(): void
    {
        $backup = $this->backups->findById($this->completed('archive.zip', 'dump.sql'));
        $this->assertNotNull($backup);

        $this->assertSame(BackupIntegrityStatus::Intact, $this->integrity()->verify($backup));
    }

    /**
     * The one this whole iteration exists for: a full quota does not
     * refuse the write, it stops it half way, and what is left looks like
     * an ordinary file until the day it is needed.
     */
    public function testATruncatedArchiveIsDetected(): void
    {
        $backup = $this->backups->findById($this->completed('archive.zip', 'dump.sql'));
        $this->assertNotNull($backup);

        $path = $this->storagePath . '/maintenance/archive.zip';
        file_put_contents($path, substr((string) file_get_contents($path), 0, 5));

        $this->assertSame(BackupIntegrityStatus::Corrupt, $this->integrity()->verify($backup));
    }

    /** A dump that changed counts as much as an archive that did. */
    public function testACorruptedDatabaseDumpIsDetectedToo(): void
    {
        $backup = $this->backups->findById($this->completed('archive.zip', 'dump.sql'));
        $this->assertNotNull($backup);

        file_put_contents($this->storagePath . '/maintenance/dump.sql', 'something else entirely');

        $this->assertSame(BackupIntegrityStatus::Corrupt, $this->integrity()->verify($backup));
    }

    /**
     * A vanished file and a changed one are told apart, because they have
     * different causes: nothing in the application removes a file without
     * its row, so a missing one came from outside.
     */
    public function testAMissingFileIsDistinguishedFromACorruptOne(): void
    {
        $backup = $this->backups->findById($this->completed('archive.zip', 'dump.sql'));
        $this->assertNotNull($backup);

        unlink($this->storagePath . '/maintenance/archive.zip');

        $this->assertSame(BackupIntegrityStatus::Missing, $this->integrity()->verify($backup));
    }

    /**
     * A backup taken before this feature existed has no digest, and saying
     * « illisible » about it would be a lie that lights the alert on every
     * installation the day it upgrades.
     */
    public function testABackupWithNoRecordedDigestIsUnverifiableRatherThanCorrupt(): void
    {
        $fileId = $this->file('legacy.zip', 'written long ago');
        $id = $this->backups->create('full_no_gallery', null);
        // Straight to the repository, the way every completion looked
        // before BackupIntegrity existed: no digests.
        $this->backups->markCompleted($id, $fileId, null);

        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);

        $status = $this->integrity()->verify($backup);

        $this->assertSame(BackupIntegrityStatus::Unverifiable, $status);
        $this->assertFalse($status->isFailure(), 'An unverifiable backup must never light the alert.');
    }

    /** Even with no digest, a file that has gone is still a finding. */
    public function testALegacyBackupWhoseFileVanishedIsStillReportedMissing(): void
    {
        $fileId = $this->file('legacy.zip', 'written long ago');
        $id = $this->backups->create('full_no_gallery', null);
        $this->backups->markCompleted($id, $fileId, null);
        unlink($this->storagePath . '/maintenance/legacy.zip');

        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);

        $this->assertSame(BackupIntegrityStatus::Missing, $this->integrity()->verify($backup));
    }

    /**
     * The archive is never read into memory.
     *
     * This is the property that decides whether the whole verification is
     * affordable: the installation this project sizes for keeps
     * gallery-inclusive archives measured in gigabytes, on shared hosting
     * with a memory limit far below that. `hash_file()` streams; the
     * obvious `hash('sha256', file_get_contents(...))` does not, and would
     * pass every other test in this file.
     *
     * `memory_reset_peak_usage()` first, and that detail is the whole test:
     * the peak is a process-wide high-water mark, and PHPUnit has already
     * pushed it well past eight megabytes by the time any of this runs. A
     * first version measured `memory_get_peak_usage(true)` without
     * resetting, watched the naive implementation sail through, and proved
     * only that the harness allocates more than the fixture does.
     *
     * Eight megabytes against one of slack — decisive without being slow,
     * and `file_get_contents()` misses it by a factor of eight.
     */
    public function testHashingDoesNotLoadTheArchiveIntoMemory(): void
    {
        $path = $this->storagePath . '/maintenance/big.zip';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        for ($i = 0; $i < 8; $i++) {
            fwrite($handle, str_repeat('x', 1024 * 1024));
        }
        fclose($handle);

        $fileId = $this->files->create('maintenance/big.zip', 'big.zip', 'application/zip', 8 << 20, 'admin', null, null);
        $id = $this->backups->create('full_with_gallery', null);
        $this->integrity()->complete($id, $fileId, $path, null, null);

        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $this->assertSame(BackupIntegrityStatus::Intact, $this->integrity()->verify($backup));
        $growth = memory_get_peak_usage() - $before;

        $this->assertLessThan(
            1024 * 1024,
            $growth,
            'Verification allocated with the size of the archive — it is reading the whole file into memory.'
        );
    }

    /** A backup whose files a purge already took has nothing to verify. */
    public function testABackupWithNoFilesAtAllIsUnverifiable(): void
    {
        $id = $this->backups->create('database', null);
        $this->integrity()->complete($id, null, null, null, null);

        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);

        $this->assertSame(BackupIntegrityStatus::Unverifiable, $this->integrity()->verify($backup));
    }

    private function integrity(): BackupIntegrity
    {
        return new BackupIntegrity($this->backups, $this->files, $this->storagePath);
    }

    private function completed(string $archive, ?string $dump): int
    {
        $archiveId = $this->file($archive, $archive . ' contents');
        $dumpId = $dump !== null ? $this->file($dump, $dump . ' contents') : null;
        $id = $this->backups->create('full_no_gallery', null);

        $this->integrity()->complete(
            $id,
            $archiveId,
            $this->storagePath . '/maintenance/' . $archive,
            $dumpId,
            $dump !== null ? $this->storagePath . '/maintenance/' . $dump : null
        );

        return $id;
    }

    private function file(string $name, string $contents): int
    {
        file_put_contents($this->storagePath . '/maintenance/' . $name, $contents);

        return $this->files->create(
            'maintenance/' . $name,
            $name,
            'application/octet-stream',
            strlen($contents),
            'admin',
            null,
            null
        );
    }
}
