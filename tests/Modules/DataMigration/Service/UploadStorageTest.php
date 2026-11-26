<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Service;

use Core\File\DataMigrationDeposits;
use Modules\DataMigration\Service\UploadStorage;
use PHPUnit\Framework\TestCase;

/**
 * What the module keeps on the disk, and how it tells the core's sweep of
 * that directory what is in use (issue #1259).
 */
final class UploadStorageTest extends TestCase
{
    private string $storagePath;
    private UploadStorage $storage;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/dm_storage_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->storage = new UploadStorage($this->storagePath);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storagePath);
    }

    public function testTheModuleDepositsWhereTheCoreSweeps(): void
    {
        $this->assertSame(DataMigrationDeposits::DIR, UploadStorage::DIR);
        $this->assertSame(
            (new DataMigrationDeposits($this->storagePath))->directory(),
            dirname($this->storage->absolute($this->storage->newArchivePath()))
        );
    }

    public function testAMovementStampsTheRunsFile(): void
    {
        $relative = $this->storage->newArchivePath();
        $absolute = $this->storage->absolute($relative);
        file_put_contents($absolute, 'zip');
        touch($absolute, 1_000_000);

        $this->storage->markMoved($relative, new \DateTimeImmutable('@1800000000'));

        clearstatcache();
        $this->assertSame(1_800_000_000, filemtime($absolute));
    }

    public function testAMovementOfARunWithoutFileOrWithAForeignPathDoesNothing(): void
    {
        $this->storage->markMoved(null, new \DateTimeImmutable());
        $this->storage->markMoved('', new \DateTimeImmutable());
        $this->storage->markMoved('../outside', new \DateTimeImmutable());
        $this->storage->markMoved(UploadStorage::DIR . '/run-missing.zip', new \DateTimeImmutable());

        $this->assertFileDoesNotExist($this->storagePath . '/' . UploadStorage::DIR . '/run-missing.zip');
    }

    public function testEachSliceStartsWithAnEmptyCopiesDirectoryOfItsRun(): void
    {
        $first = $this->storage->freshCopiesDirectory(7);
        file_put_contents($first . '/sm_mail_left', 'orphan of a killed slice');
        $other = $this->storage->freshCopiesDirectory(8);
        file_put_contents($other . '/sm_mail_other', 'another run');

        $again = $this->storage->freshCopiesDirectory(7);

        $this->assertSame($first, $again);
        $this->assertSame(
            $this->storagePath . '/' . UploadStorage::DIR . '/copies-7',
            $again
        );
        $this->assertSame([], glob($again . '/*') ?: [], 'what the killed slice left is gone');
        $this->assertSame(0700, fileperms($again) & 0777);
        $this->assertFileExists($other . '/sm_mail_other', 'another run\'s copies are not touched');

        $this->storage->discardCopies(7);
        $this->assertDirectoryDoesNotExist($again);
    }
}
