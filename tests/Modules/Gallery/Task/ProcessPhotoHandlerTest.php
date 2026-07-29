<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Task\ProcessPhotoHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
class ProcessPhotoHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;
    private MediaRepository $mediaRepository;
    private int $albumId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/gallery_task_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        $settingRepository = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepository);
        $settingService->register('gallery_photo_max_dimension', '3000', 'number', 'label', 'desc', 'gallery');
        $settingService->register('gallery_storage_backend', 'local', 'select', 'label', 'desc', 'gallery');
        $settingService->register('gallery_storage_local_subdir', 'gallery', 'text', 'label', 'desc', 'gallery');

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $locationId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );
        $this->albumId = (new AlbumRepository($this->pdo))->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $scoutYearId, null, $locationId, $authorId);
        $this->mediaRepository = new MediaRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->storagePath);
    }

    private function removeRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    private function createOriginalFile(): int
    {
        $relativePath = 'gallery/' . $this->albumId . '/orig/test.jpg';
        $fullPath = $this->storagePath . '/' . $relativePath;
        mkdir(dirname($fullPath), 0755, true);
        $image = imagecreatetruecolor(2000, 1000);
        imagejpeg($image, $fullPath);
        imagedestroy($image);

        $fileRepository = new FileRepository($this->pdo);
        return $fileRepository->create($relativePath, 'test.jpg', 'image/jpeg', (int) filesize($fullPath), 'identified', 'gallery', null);
    }

    private function buildContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->storagePath
        );
    }

    public function testProcessesAPendingPhotoIntoThreeSizes(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $media = $this->mediaRepository->findById($mediaId);
        $this->assertSame(Media::STATUS_DONE, $media->processingStatus);
        $this->assertSame(2000, $media->width);
        $this->assertSame(1000, $media->height);
        $this->assertTrue(is_file($this->storagePath . '/gallery/' . $media->thumbPath));
        $this->assertTrue(is_file($this->storagePath . '/gallery/' . $media->mediumPath));
        $this->assertTrue(is_file($this->storagePath . '/gallery/' . $media->largePath));
    }

    public function testDeletesTheOriginalAfterProcessing(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $originalPath = $this->storagePath . '/gallery/' . $this->albumId . '/orig/test.jpg';
        $this->assertTrue(is_file($originalPath));

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertFalse(is_file($originalPath));
    }

    public function testMarksFailedWhenTheOriginalFileIsMissing(): void
    {
        $fileRepository = new FileRepository($this->pdo);
        $fileId = $fileRepository->create('gallery/999/orig/missing.jpg', 'missing.jpg', 'image/jpeg', 100, 'identified', 'gallery', null);
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'missing.jpg');

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame(Media::STATUS_FAILED, $this->mediaRepository->findById($mediaId)->processingStatus);
    }

    public function testIsANoOpWhenTheMediaRowDoesNotExist(): void
    {
        (new ProcessPhotoHandler())->handle(['media_id' => 999999], $this->buildContext());
        $this->assertTrue(true);
    }

    public function testIsANoOpWhenAlreadyDone(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $this->mediaRepository->markPhotoDone($mediaId, 'a', 'b', 'c', 1, 1);

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        // Still the original done values — never reprocessed.
        $this->assertSame('a', $this->mediaRepository->findById($mediaId)->thumbPath);
    }
}
