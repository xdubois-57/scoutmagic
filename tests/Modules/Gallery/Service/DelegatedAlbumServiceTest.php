<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\DelegatedAlbumService;
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
class DelegatedAlbumServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albumRepository;
    private MediaRepository $mediaRepository;
    private StorageLocationRepository $storageLocationRepository;
    private StorageBackendFactory $storageBackendFactory;
    private DelegatedAlbumService $service;
    private int $authorId;
    private int $localLocationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->mediaRepository = new MediaRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        $storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $this->storageBackendFactory,
            $settingService, new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );
        $mediaService = $this->createMock(MediaService::class);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $this->localLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $this->service = new DelegatedAlbumService(
            $this->albumRepository, $this->mediaRepository, $mediaService, $this->storageLocationRepository,
            $storageLocationService, $this->storageBackendFactory, new ScoutYearService($this->pdo)
        );
    }

    public function testEnsureAlbumCreatesADelegatedAlbumOnFirstCall(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);

        $this->assertSame('Titre', $album->title);
        $stored = $this->albumRepository->findByOwner('some_owner_type', 42);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isDelegated());
        $this->assertSame($this->localLocationId, $stored->storageLocationId);
    }

    public function testEnsureAlbumIsIdempotentAndReturnsTheSameAlbumOnASecondCall(): void
    {
        $first = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $second = $this->service->ensureAlbum('some_owner_type', 42, 'Titre différent', '2026-02-02', $this->authorId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Titre', $second->title);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
    }

    public function testEnsureAlbumRefusesAStorageLocationWithAPublicUrlConfigured(): void
    {
        $publicLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket public', null, 'custom', 'https://s3.example.com', 'eu',
            'bucket', 'ak', 'https://cdn.example.com', 'sk'
        );

        $this->expectException(GalleryException::class);
        $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId, $publicLocationId);
    }

    public function testEnsureAlbumAcceptsAPrivateS3Location(): void
    {
        $privateLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket privé', null, 'custom', 'https://s3.example.com', 'eu',
            'bucket', 'ak', null, 'sk'
        );

        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId, $privateLocationId);

        $stored = $this->albumRepository->findById($album->id);
        $this->assertSame($privateLocationId, $stored->storageLocationId);
    }

    public function testListMediaThrowsForAnUnknownAlbum(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->listMedia(999999);
    }

    public function testListMediaThrowsForAnOrdinaryNonDelegatedAlbum(): void
    {
        $ordinaryId = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Ordinaire', null, '2026-01-01', null, 1, null, $this->localLocationId, $this->authorId
        );

        $this->expectException(GalleryException::class);
        $this->service->listMedia($ordinaryId);
    }

    public function testListMediaReturnsTheAlbumsMedia(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $this->mediaRepository->create($album->id, 'photo', $fileId, 0, 'photo.jpg');

        $media = $this->service->listMedia($album->id);

        $this->assertCount(1, $media);
        $this->assertSame('photo.jpg', $media[0]->originalFilename);
    }

    public function testDeleteMediaThrowsWhenTheMediaBelongsToAnotherAlbum(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $otherAlbum = $this->service->ensureAlbum('some_owner_type', 43, 'Autre', '2026-01-01', $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($otherAlbum->id, 'photo', $fileId, 0, null);

        $this->expectException(GalleryException::class);
        $this->service->deleteMedia($album->id, $mediaId);
    }

    public function testDeleteAlbumRemovesTheAlbumRowAndCleansUpStorage(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->once())->method('deletePrefix')->with((string) $album->id);
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $this->service->deleteAlbum($album->id);

        $this->assertNull($this->albumRepository->findById($album->id));
    }

    public function testDeleteAlbumThrowsForAnUnknownAlbum(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->deleteAlbum(999999);
    }
}
