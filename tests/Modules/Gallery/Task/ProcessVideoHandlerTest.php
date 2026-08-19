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
use Modules\Gallery\Task\ProcessVideoHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 *
 * FFmpeg isn't installed in this environment (Service\FfmpegAvailability
 * correctly reports it as unavailable — see FfmpegAvailabilityTest), so
 * only the graceful-failure paths are exercisable here; the actual
 * transcode pipeline needs a real server with ffmpeg/ffprobe to verify
 * end-to-end.
 */
class ProcessVideoHandlerTest extends TestCase
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
        $this->storagePath = sys_get_temp_dir() . '/gallery_video_task_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

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

    public function testIsANoOpWhenTheMediaRowDoesNotExist(): void
    {
        (new ProcessVideoHandler())->handle(['media_id' => 999999], $this->buildContext());
        $this->assertTrue(true);
    }

    public function testMarksFailedWithoutFfmpegAvailable(): void
    {
        $relativePath = 'gallery/' . $this->albumId . '/orig/test.mp4';
        $fullPath = $this->storagePath . '/' . $relativePath;
        mkdir(dirname($fullPath), 0755, true);
        file_put_contents($fullPath, 'not a real video');

        $fileId = (new FileRepository($this->pdo))->create($relativePath, 'test.mp4', 'video/mp4', 17, 'identified', 'gallery', null);
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_VIDEO, $fileId, 0, 'test.mp4');

        (new ProcessVideoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame(Media::STATUS_FAILED, $this->mediaRepository->findById($mediaId)->processingStatus);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'video_processing_failed'")->fetchColumn());
    }

    /**
     * Same invariant as Task\ProcessPhotoHandler: never write renditions into
     * a location Task\MigrateAlbumStorageHandler is moving away from — the
     * post-migration source cleanup would delete them. Testable without ffmpeg
     * because the guard sits ahead of every transcode.
     */
    public function testDefersAndKeepsTheMediaPendingWhileTheAlbumIsMigrating(): void
    {
        $mediaId = $this->createPendingVideo();
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocation::TYPE_LOCAL, 'Cible', 'gallery2', null, null, null, null, null, null, null
        );
        (new AlbumRepository($this->pdo))->startMigration($this->albumId, $targetId);

        (new ProcessVideoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame(Media::STATUS_PENDING, $this->mediaRepository->findById($mediaId)->processingStatus);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'process_video'")->fetchColumn()
        );
    }

    public function testGivesUpAndFailsTheMediaOnceTheDeferralBudgetIsSpent(): void
    {
        $mediaId = $this->createPendingVideo();
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocation::TYPE_LOCAL, 'Cible', 'gallery2', null, null, null, null, null, null, null
        );
        (new AlbumRepository($this->pdo))->startMigration($this->albumId, $targetId);

        (new ProcessVideoHandler())->handle(['media_id' => $mediaId, 'migration_deferrals' => 10], $this->buildContext());

        $this->assertSame(Media::STATUS_FAILED, $this->mediaRepository->findById($mediaId)->processingStatus);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'process_video'")->fetchColumn()
        );
    }

    /**
     * No temp directory may be created on the deferral path — the guard returns
     * before any staging work starts.
     */
    public function testDeferringLeavesNoTemporaryDirectoryBehind(): void
    {
        $mediaId = $this->createPendingVideo();
        $targetId = (new StorageLocationRepository($this->pdo, $this->encryption))->create(
            StorageLocation::TYPE_LOCAL, 'Cible', 'gallery2', null, null, null, null, null, null, null
        );
        (new AlbumRepository($this->pdo))->startMigration($this->albumId, $targetId);

        (new ProcessVideoHandler())->handle(['media_id' => $mediaId], $this->buildContext());

        $this->assertSame([], glob(sys_get_temp_dir() . '/gallery_video_' . $mediaId . '_*') ?: []);
    }

    private function createPendingVideo(): int
    {
        $relativePath = 'gallery/' . $this->albumId . '/orig/test.mp4';
        $fullPath = $this->storagePath . '/' . $relativePath;
        mkdir(dirname($fullPath), 0755, true);
        file_put_contents($fullPath, 'not a real video');
        $fileId = (new FileRepository($this->pdo))->create($relativePath, 'test.mp4', 'video/mp4', 17, 'identified', 'gallery', null);

        return $this->mediaRepository->create($this->albumId, Media::TYPE_VIDEO, $fileId, 0, 'test.mp4');
    }
}
