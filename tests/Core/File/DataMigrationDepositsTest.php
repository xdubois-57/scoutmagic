<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\DataMigrationDeposits;
use PHPUnit\Framework\TestCase;

/**
 * The core's own sweep of `storage/temp/data_migration/` (issue #1259),
 * decided from the disk alone: an entry goes once nothing in it has moved
 * for 24 hours, and stays while anything in it is younger.
 */
final class DataMigrationDepositsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $storage;
    private DataMigrationDeposits $deposits;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/sm_deposits_' . bin2hex(random_bytes(6));
        mkdir($this->storage . '/' . DataMigrationDeposits::DIR, 0700, true);
        $this->deposits = new DataMigrationDeposits($this->storage);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testAnArchiveIdleForADayGoesAndAYoungerOneStays(): void
    {
        $old = $this->file('run-old.zip', self::NOW - DataMigrationDeposits::RETENTION_SECONDS - 1);
        $young = $this->file('run-young.zip', self::NOW - DataMigrationDeposits::RETENTION_SECONDS + 60);

        $this->assertSame(1, $this->deposits->sweep(self::NOW));

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($young);
    }

    public function testAWorkspaceStaysWhileTheFileInsideItMoves(): void
    {
        $inside = $this->file('ws-abc/calendar.ics', self::NOW - 60);
        touch(dirname($inside), self::NOW - 3 * DataMigrationDeposits::RETENTION_SECONDS);

        $this->assertSame(0, $this->deposits->sweep(self::NOW));
        $this->assertFileExists($inside);

        touch($inside, self::NOW - 2 * DataMigrationDeposits::RETENTION_SECONDS);
        $this->assertSame(1, $this->deposits->sweep(self::NOW));
        $this->assertDirectoryDoesNotExist(dirname($inside));
    }

    public function testOrphanedWorkingCopiesGoTooHiddenFilesIncluded(): void
    {
        $copy = $this->file('copies-7/.sm_mail_x', self::NOW - 2 * DataMigrationDeposits::RETENTION_SECONDS);
        touch(dirname($copy), self::NOW - 2 * DataMigrationDeposits::RETENTION_SECONDS);

        $this->assertSame(1, $this->deposits->sweep(self::NOW));
        $this->assertDirectoryDoesNotExist(dirname($copy));
    }

    public function testALinkIsRemovedWithoutFollowingIt(): void
    {
        $target = $this->storage . '/outside.txt';
        file_put_contents($target, 'kept');
        $link = $this->storage . '/' . DataMigrationDeposits::DIR . '/link';
        symlink($target, $link);
        // A link is read with lstat(), so its own time is when it was made:
        // the clock is moved forward rather than the link aged.
        $later = time() + 2 * DataMigrationDeposits::RETENTION_SECONDS;

        $this->deposits->sweep($later);

        $this->assertFalse(is_link($link));
        $this->assertSame('kept', file_get_contents($target));
        unlink($target);
    }

    public function testAMissingDirectoryIsNothingToDo(): void
    {
        $this->assertSame(0, (new DataMigrationDeposits($this->storage . '/nowhere'))->sweep(self::NOW));
    }

    private function file(string $relative, int $mtime): string
    {
        $path = $this->storage . '/' . DataMigrationDeposits::DIR . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, 'contenu');
        touch($path, $mtime);

        return $path;
    }
}
