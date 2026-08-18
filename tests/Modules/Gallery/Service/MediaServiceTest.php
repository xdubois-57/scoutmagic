<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MediaServiceTest extends TestCase
{
    private \PDO $pdo;
    private MediaRepository $mediaRepository;
    private AlbumRepository $albumRepository;
    private StorageLocationRepository $storageLocationRepository;
    private StorageLocationService $storageLocationService;
    private StorageBackendFactory $storageBackendFactory;
    private MediaService $service;
    private GalleryAccessService $accessService;
    private SettingService $settingService;
    private int $albumId;
    private int $authorId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);

        $this->mediaRepository = new MediaRepository($this->pdo);
        $this->albumRepository = new AlbumRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->storagePath = sys_get_temp_dir() . '/gallery_media_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);
        $this->storageBackendFactory = new StorageBackendFactory($this->storageLocationRepository, $this->storagePath);
        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->settingService = $this->createMock(SettingService::class);
        $this->settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        $this->accessService = $this->createMock(GalleryAccessService::class);
        $this->accessService->method('canManageAlbum')->willReturn(true);
        $this->storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $this->storageBackendFactory,
            $this->settingService, new S3SecretRepository($this->pdo, $encryption), $this->storagePath
        );
        $ffmpegAvailability = $this->createMock(FfmpegAvailability::class);
        $ffmpegAvailability->method('check')->willReturn(false);

        $this->service = new MediaService(
            $this->mediaRepository, $this->albumRepository, $uploadHandler, $schedulerService,
            $this->settingService, $this->accessService, $this->storageBackendFactory,
            $this->storageLocationService, $ffmpegAvailability
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $locationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );
        $this->albumId = $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $scoutYearId, null, $locationId, $this->authorId);
    }

    private function fakeUploadedImage(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gallery_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path);
        imagedestroy($image);

        return ['name' => 'photo.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'type' => 'image/jpeg'];
    }

    public function testUploadCreatesAPendingMediaRowAndSchedulesProcessing(): void
    {
        $album = $this->albumRepository->findById($this->albumId);

        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $this->assertSame(Media::STATUS_PENDING, $media->processingStatus);
        $this->assertSame(Media::TYPE_PHOTO, $media->mediaType);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn());
    }

    public function testFirstUploadBecomesTheAlbumCover(): void
    {
        $album = $this->albumRepository->findById($this->albumId);

        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $this->assertSame($media->id, $this->albumRepository->findById($this->albumId)->coverMediaId);
    }

    public function testSecondUploadDoesNotOverrideTheExistingCover(): void
    {
        $album = $this->albumRepository->findById($this->albumId);
        $first = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $album = $this->albumRepository->findById($this->albumId);
        $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $this->assertSame($first->id, $this->albumRepository->findById($this->albumId)->coverMediaId);
    }

    public function testUploadRejectsAnExternalAlbum(): void
    {
        $externalId = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, 1, 'https://example.com', null, $this->authorId);
        $album = $this->albumRepository->findById($externalId);

        $this->expectException(GalleryException::class);
        $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);
    }

    public function testUploadRejectsWhenOverQuota(): void
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $key === 'gallery_max_media_per_album' ? 0 : $default);
        $service = new MediaService(
            $this->mediaRepository, $this->albumRepository, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new SchedulerService(new SchedulerRepository($this->pdo)), $settingService, $this->accessService,
            $this->storageBackendFactory, $this->storageLocationService, $this->createMock(FfmpegAvailability::class)
        );
        $album = $this->albumRepository->findById($this->albumId);

        $this->expectException(GalleryException::class);
        $service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);
    }

    public function testDeleteRemovesTheMediaRow(): void
    {
        $album = $this->albumRepository->findById($this->albumId);
        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $this->service->delete($media, $this->albumRepository->findById($this->albumId), Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->mediaRepository->findById($media->id));
    }

    public function testDeleteClearsTheCoverWhenDeletingTheCoverMedia(): void
    {
        $album = $this->albumRepository->findById($this->albumId);
        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);
        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame($media->id, $album->coverMediaId);

        $this->service->delete($media, $album, Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->albumRepository->findById($this->albumId)->coverMediaId);
    }

    public function testReorderRejectsAListThatDoesNotMatchExactly(): void
    {
        $album = $this->albumRepository->findById($this->albumId);
        $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $this->expectException(GalleryException::class);
        $this->service->reorder($album, [999999], Role::CHIEF, 'chief@test.com');
    }

    public function testResolveUrlReturnsNullWhenNotYetProcessed(): void
    {
        $album = $this->albumRepository->findById($this->albumId);
        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $this->assertNull($this->service->resolveUrl($media, $album, 'thumb'));
    }

    public function testResolveUrlBuildsTheAppRouteForLocalStorage(): void
    {
        $album = $this->albumRepository->findById($this->albumId);
        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);
        $this->mediaRepository->markPhotoDone($media->id, 'thumb.jpg', 'med.jpg', 'lg.jpg', 100, 100);

        $url = $this->service->resolveUrl($this->mediaRepository->findById($media->id), $album, 'thumb');

        $this->assertSame('/gallery/media/' . $media->id . '/thumb', $url);
    }

    public function testVideoUploadAllowedIsFalseWithoutFfmpeg(): void
    {
        $this->assertFalse($this->service->videoUploadAllowed());
    }

    private function createDelegatedAlbum(): int
    {
        return $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Album délégué', null, '2026-01-01', null, 1, null,
            $this->storageLocationRepository->findDefault()->id, $this->authorId, 'some_owner_type', 42
        );
    }

    public function testUploadThrowsForADelegatedAlbum(): void
    {
        $album = $this->albumRepository->findById($this->createDelegatedAlbum());

        $this->expectException(GalleryException::class);
        $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);
    }

    public function testAddWithoutAuthorisationCheckStoresMediaOnADelegatedAlbum(): void
    {
        $album = $this->albumRepository->findById($this->createDelegatedAlbum());

        $media = $this->service->addWithoutAuthorisationCheck($album, $this->fakeUploadedImage(), $this->authorId);

        $this->assertSame(Media::STATUS_PENDING, $media->processingStatus);
        $this->assertNotNull($this->mediaRepository->findById($media->id));
    }

    public function testAddWithoutAuthorisationCheckPropagatesTheAlbumsOwnerOntoTheUploadedFile(): void
    {
        // So Core\File\FileAccessGuard's ownership registry — not just
        // 'identified' — governs /files/{id} for a delegated album's raw
        // original too, closing the same gap /gallery/media/{id}/{size}
        // already closes for its renditions.
        $album = $this->albumRepository->findById($this->createDelegatedAlbum());

        $media = $this->service->addWithoutAuthorisationCheck($album, $this->fakeUploadedImage(), $this->authorId);

        $stmt = $this->pdo->prepare('SELECT owner_type, owner_id FROM files WHERE id = ?');
        $stmt->execute([$media->fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('some_owner_type', $row['owner_type']);
        $this->assertSame(42, (int) $row['owner_id']);
    }

    public function testUploadLeavesTheFilesOwnerColumnsNullForAnOrdinaryAlbum(): void
    {
        $album = $this->albumRepository->findById($this->albumId);

        $media = $this->service->upload($album, $this->fakeUploadedImage(), Role::CHIEF, 'chief@test.com', $this->authorId);

        $stmt = $this->pdo->prepare('SELECT owner_type, owner_id FROM files WHERE id = ?');
        $stmt->execute([$media->fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['owner_type']);
        $this->assertNull($row['owner_id']);
    }

    public function testDeleteThrowsForADelegatedAlbum(): void
    {
        $albumId = $this->createDelegatedAlbum();
        $album = $this->albumRepository->findById($albumId);
        $media = $this->service->addWithoutAuthorisationCheck($album, $this->fakeUploadedImage(), $this->authorId);

        $this->expectException(GalleryException::class);
        $this->service->delete($media, $album, Role::CHIEF, 'chief@test.com');
    }

    public function testDeleteWithoutAuthorisationCheckRemovesMediaFromADelegatedAlbum(): void
    {
        $albumId = $this->createDelegatedAlbum();
        $album = $this->albumRepository->findById($albumId);
        $media = $this->service->addWithoutAuthorisationCheck($album, $this->fakeUploadedImage(), $this->authorId);

        $this->service->deleteWithoutAuthorisationCheck($media, $album);

        $this->assertNull($this->mediaRepository->findById($media->id));
    }

    public function testReorderThrowsForADelegatedAlbum(): void
    {
        $album = $this->albumRepository->findById($this->createDelegatedAlbum());

        $this->expectException(GalleryException::class);
        $this->service->reorder($album, [], Role::CHIEF, 'chief@test.com');
    }

    public function testResolveUrlReturnsNullForADelegatedAlbum(): void
    {
        $albumId = $this->createDelegatedAlbum();
        $album = $this->albumRepository->findById($albumId);
        $media = $this->service->addWithoutAuthorisationCheck($album, $this->fakeUploadedImage(), $this->authorId);
        $this->mediaRepository->markPhotoDone($media->id, 'thumb.jpg', 'med.jpg', 'lg.jpg', 100, 100);

        $url = $this->service->resolveUrl($this->mediaRepository->findById($media->id), $album, 'thumb');

        $this->assertNull($url);
    }
}
