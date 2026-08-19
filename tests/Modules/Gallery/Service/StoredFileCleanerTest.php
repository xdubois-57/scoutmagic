<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\File\FileRepository;
use Modules\Gallery\Service\StoredFileCleaner;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class StoredFileCleanerTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private string $storagePath;
    private StoredFileCleaner $cleaner;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/gallery_cleaner_' . bin2hex(random_bytes(4));
        mkdir($this->storagePath . '/gallery/1/orig', 0700, true);
        $this->cleaner = new StoredFileCleaner($this->fileRepository, $this->storagePath);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($this->storagePath);
    }

    private function createStoredFile(string $relativePath = 'gallery/1/orig/abc.jpg'): int
    {
        file_put_contents($this->storagePath . '/' . $relativePath, 'original-bytes');

        return $this->fileRepository->create(
            $relativePath, 'photo.jpg', 'image/jpeg', 14, 'identified', 'gallery', null
        );
    }

    public function testDeleteRemovesBothTheRowAndTheBytes(): void
    {
        $fileId = $this->createStoredFile();
        $path = $this->storagePath . '/gallery/1/orig/abc.jpg';
        $this->assertFileExists($path);

        $this->cleaner->delete($fileId);

        $this->assertFileDoesNotExist($path);
        $this->assertNull($this->fileRepository->findById($fileId));
    }

    public function testDeleteStillRemovesTheRowWhenTheBytesAreAlreadyGone(): void
    {
        // The normal case for a successfully processed photo: Task\
        // ProcessPhotoHandler already unlinked the staging original, only the
        // files-table row is left to reclaim.
        $fileId = $this->createStoredFile();
        unlink($this->storagePath . '/gallery/1/orig/abc.jpg');

        $this->cleaner->delete($fileId);

        $this->assertNull($this->fileRepository->findById($fileId));
    }

    public function testDeleteIsANoOpForNull(): void
    {
        $this->cleaner->delete(null);

        $this->assertTrue(true);
    }

    public function testDeleteIsANoOpForAnUnknownFileId(): void
    {
        $this->cleaner->delete(999999);

        $this->assertTrue(true);
    }

    public function testDeleteLeavesOtherFilesAlone(): void
    {
        $kept = $this->createStoredFile('gallery/1/orig/keep.jpg');
        $dropped = $this->createStoredFile('gallery/1/orig/drop.jpg');

        $this->cleaner->delete($dropped);

        $this->assertNotNull($this->fileRepository->findById($kept));
        $this->assertFileExists($this->storagePath . '/gallery/1/orig/keep.jpg');
    }

    public function testAnEmptyStoragePathOnlyRemovesTheRow(): void
    {
        $fileId = $this->createStoredFile();
        $cleaner = new StoredFileCleaner($this->fileRepository, '');

        $cleaner->delete($fileId);

        $this->assertNull($this->fileRepository->findById($fileId));
        $this->assertFileExists($this->storagePath . '/gallery/1/orig/abc.jpg');
    }
}
