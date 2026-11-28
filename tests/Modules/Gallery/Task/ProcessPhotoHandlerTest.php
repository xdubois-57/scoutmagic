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
use Modules\Gallery\Service\ImageProcessingService;
use Modules\Gallery\Service\PhotoMemoryBudget;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Modules\Gallery\Task\ProcessPhotoHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationService;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
            StorageLocationType::Local, 'Stockage local', new LocalLocationConfig('gallery'), null
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
            $this->createStub(MailService::class),
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
        // A photo deleted between the queueing and the run: no other
        // media row is processed in its place, and nothing is written.
        $fileId = $this->createOriginalFile();
        $untouched = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');

        (new ProcessPhotoHandler())->handle(['media_id' => 999999], $this->buildContext());

        $this->assertSame(Media::STATUS_PENDING, $this->mediaRepository->findById($untouched)->processingStatus);
        $this->assertFileDoesNotExist($this->storagePath . '/gallery/' . $this->albumId . '/thumb_' . $untouched . '.jpg');
    }

    /**
     * Task\MigrateAlbumStorageHandler copies the album's renditions and then
     * deletes the source prefix. A processing task running in that window
     * writes its renditions to the source — already copied past — and the
     * final cleanup deletes them, leaving a permanently broken media row. The
     * task must stand aside and come back instead.
     */
    public function testDefersAndKeepsTheMediaPendingWhileTheAlbumIsMigrating(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $albumRepository = new AlbumRepository($this->pdo);
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocationType::Local, 'Cible', new LocalLocationConfig('gallery2'), null
        );
        $albumRepository->startMigration($this->albumId, $targetId);

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame(Media::STATUS_PENDING, $this->mediaRepository->findById($mediaId)->processingStatus);
        $this->assertNull($this->mediaRepository->findById($mediaId)->thumbPath);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'process_photo'")->fetchColumn()
        );
    }

    public function testTheDeferredTaskCarriesAnIncrementedCounter(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocationType::Local, 'Cible', new LocalLocationConfig('gallery2'), null
        );
        (new AlbumRepository($this->pdo))->startMigration($this->albumId, $targetId);

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId, 'migration_deferrals' => 3], $this->buildContext());

        $payload = (string) $this->pdo->query("SELECT payload FROM scheduled_actions WHERE task_key = 'process_photo'")->fetchColumn();
        $this->assertStringContainsString('"migration_deferrals":4', $payload);
    }

    /**
     * A migration wedged on 'in_progress' (its handler killed mid-run) must not
     * re-queue the same task forever.
     */
    public function testGivesUpAndFailsTheMediaOnceTheDeferralBudgetIsSpent(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocationType::Local, 'Cible', new LocalLocationConfig('gallery2'), null
        );
        (new AlbumRepository($this->pdo))->startMigration($this->albumId, $targetId);

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId, 'migration_deferrals' => 10], $this->buildContext());

        $this->assertSame(Media::STATUS_FAILED, $this->mediaRepository->findById($mediaId)->processingStatus);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'process_photo'")->fetchColumn()
        );
    }

    public function testProcessesNormallyOnceAMigrationHasFailed(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $albumRepository = new AlbumRepository($this->pdo);
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocationType::Local, 'Cible', new LocalLocationConfig('gallery2'), null
        );
        $albumRepository->startMigration($this->albumId, $targetId);
        // A failed migration leaves the source fully intact, so processing is
        // safe again immediately.
        $albumRepository->failMigration($this->albumId, 'boom');

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame(Media::STATUS_DONE, $this->mediaRepository->findById($mediaId)->processingStatus);
    }

    /**
     * An album with no location at all, and no location declared on the
     * installation either. Reading gallery_albums.location_id directly
     * failed every single media with « Emplacement de stockage
     * introuvable »; resolving through Service\GalleryLocationService
     * creates the default and puts the album on it instead.
     */
    public function testPutsAnAlbumWithNoLocationOnTheDefaultOneRatherThanFailing(): void
    {
        $this->pdo->prepare('UPDATE gallery_albums SET location_id = NULL WHERE id = ?')->execute([$this->albumId]);
        $this->pdo->prepare('DELETE FROM storage_locations')->execute();
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $media = $this->mediaRepository->findById($mediaId);
        $this->assertSame(Media::STATUS_DONE, $media->processingStatus);
        $this->assertNotNull((new AlbumRepository($this->pdo))->findById($this->albumId)?->locationId);
        $this->assertTrue(is_file(
            $this->storagePath . '/' . StorageLocationService::DEFAULT_PATH . '/' . $media->thumbPath
        ));
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

    /**
     * Issue #1449: a photo whose decode would exhaust memory_limit used to
     * kill the process — a fatal, so the catch below never ran, the media
     * stayed « en traitement » for good and the cron pass died. Refused
     * before decoding, it becomes an ordinary failed media with a reason the
     * animateur can read, a warning in the journal, and a handler that
     * returns normally so the pass carries on.
     */
    public function testAPhotoTooHeavyForTheMemoryLeftIsFailedWithAReasonAndJournaled(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        // 4 MB left under a simulated 64 MB limit; the 2000×1000 original
        // needs 8 MB for its bitmap alone.
        $handler = new ProcessPhotoHandler(
            new ImageProcessingService(PhotoMemoryBudget::fixed('64M', 60 * 1024 * 1024))
        );

        $handler->handle(['media_id' => $mediaId], $this->buildContext());

        $media = $this->mediaRepository->findById($mediaId);
        $this->assertSame(Media::STATUS_FAILED, $media->processingStatus);
        $this->assertNotNull($media->processingError);
        $this->assertStringContainsString('trop lourde', $media->processingError);
        $this->assertNull($media->thumbPath);
        // Kept, as on every other failure: nothing was produced from it.
        $this->assertFileExists($this->storagePath . '/gallery/' . $this->albumId . '/orig/test.jpg');

        $row = $this->pdo->query(
            "SELECT category, level, context FROM event_log WHERE event_type = 'photo_processing_refused_memory'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $row);
        $this->assertSame('gallery', $row[0]['category']);
        $this->assertSame('warning', $row[0]['level']);
        $context = json_decode((string) $row[0]['context'], true);
        $this->assertSame(
            ['media_id', 'album_id', 'megapixels', 'required_bytes', 'available_bytes'],
            array_keys($context)
        );
        $this->assertSame($mediaId, $context['media_id']);
        $this->assertSame($this->albumId, $context['album_id']);
        $this->assertEquals(2.0, $context['megapixels']);
        $this->assertSame(4 * 1024 * 1024, $context['available_bytes']);
        $this->assertGreaterThan($context['available_bytes'], $context['required_bytes']);
        $this->assertStringNotContainsString('test.jpg', (string) $row[0]['context']);
        // Not the generic failure line: one refusal, one line.
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'photo_processing_failed'")->fetchColumn()
        );
    }

    public function testAnOrdinaryPhotoIsProcessedUnderAnOrdinarySimulatedLimit(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $handler = new ProcessPhotoHandler(
            new ImageProcessingService(PhotoMemoryBudget::fixed('256M', 32 * 1024 * 1024))
        );

        $handler->handle(['media_id' => $mediaId], $this->buildContext());

        $media = $this->mediaRepository->findById($mediaId);
        $this->assertSame(Media::STATUS_DONE, $media->processingStatus);
        $this->assertNull($media->processingError);
    }

    /**
     * The failed tile shows processing_error, so what reaches it is gated at
     * the write site: a technical cause becomes the generic sentence, a
     * GalleryException keeps its own.
     */
    public function testAFailureWithAUserFacingCauseKeepsItsSentence(): void
    {
        $fileRepository = new FileRepository($this->pdo);
        $fileId = $fileRepository->create('gallery/999/orig/missing.jpg', 'missing.jpg', 'image/jpeg', 100, 'identified', 'gallery', null);
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'missing.jpg');

        (new ProcessPhotoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame('Fichier original illisible.', $this->mediaRepository->findById($mediaId)->processingError);
    }

    public function testAFailureWithATechnicalCauseShowsTheGenericSentence(): void
    {
        $fileId = $this->createOriginalFile();
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, $fileId, 0, 'test.jpg');
        $processing = $this->createStub(ImageProcessingService::class);
        $processing->method('process')->willThrowException(new \RuntimeException('GD: out of something at /var/www/x.php'));

        (new ProcessPhotoHandler($processing))->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame(
            ProcessPhotoHandler::GENERIC_FAILURE_MESSAGE,
            $this->mediaRepository->findById($mediaId)->processingError
        );
    }
}
