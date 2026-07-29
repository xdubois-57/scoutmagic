<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
class StorageLocationServiceTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $storageLocationRepository;
    private AlbumRepository $albumRepository;
    private SettingService $settingService;
    private S3SecretRepository $legacyS3SecretRepository;
    private StorageLocationService $service;
    private string $storagePath;
    private int $scoutYearId;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->legacyS3SecretRepository = new S3SecretRepository($this->pdo, $encryption);
        $this->storagePath = sys_get_temp_dir() . '/gallery_storage_location_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);
        $storageBackendFactory = new StorageBackendFactory($this->storageLocationRepository, $this->storagePath);

        $this->service = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $storageBackendFactory,
            $this->settingService, $this->legacyS3SecretRepository, $this->storagePath
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testBackfillOnAFreshInstallCreatesALocalLocation(): void
    {
        $this->service->ensureLegacyLocationBackfilled();

        $locations = $this->storageLocationRepository->findAll();
        $this->assertCount(1, $locations);
        $this->assertFalse($locations[0]->isS3());
    }

    public function testBackfillIsIdempotent(): void
    {
        $this->service->ensureLegacyLocationBackfilled();
        $this->service->ensureLegacyLocationBackfilled();

        $this->assertCount(1, $this->storageLocationRepository->findAll());
    }

    public function testBackfillFromLegacyS3SettingsCarriesOverTheSecretAndPointsExistingAlbumsAtIt(): void
    {
        $this->settingService->register('gallery_storage_backend', 'local', 'select', 'l', 'd', 'gallery');
        $this->settingService->set('gallery_storage_backend', 's3', 'gallery');
        $this->settingService->register('gallery_s3_provider', 'custom', 'select', 'l', 'd', 'gallery');
        $this->settingService->set('gallery_s3_provider', 'hetzner', 'gallery');
        $this->settingService->register('gallery_s3_endpoint', '', 'text', 'l', 'd', 'gallery');
        $this->settingService->set('gallery_s3_endpoint', 'https://fsn1.your-objectstorage.com', 'gallery');
        $this->settingService->register('gallery_s3_bucket', '', 'text', 'l', 'd', 'gallery');
        $this->settingService->set('gallery_s3_bucket', 'scoutmagic', 'gallery');
        $this->legacyS3SecretRepository->set('legacy-secret');

        $albumId = $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);

        $this->service->ensureLegacyLocationBackfilled();

        $locations = $this->storageLocationRepository->findAll();
        $this->assertCount(1, $locations);
        $this->assertTrue($locations[0]->isS3());
        $this->assertSame('hetzner', $locations[0]->s3Provider);
        $this->assertSame('legacy-secret', $this->storageLocationRepository->getSecret($locations[0]->id));

        $album = $this->albumRepository->findById($albumId);
        $this->assertSame($locations[0]->id, $album->storageLocationId);
    }

    public function testCheckNowMarksALocalLocationOkWhenTheDirectoryIsWritable(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'writable-subdir', null, null, null, null, null, null, null);

        $this->service->checkNow($this->storageLocationRepository->findById($id));

        $location = $this->storageLocationRepository->findById($id);
        $this->assertTrue($location->lastCheckOk);
        $this->assertNull($location->lastCheckError);
    }

    public function testCheckFreshSkipsARecentlyCheckedLocation(): void
    {
        // A local location pointing at a subdir that a real checkNow() would
        // always mark writable/ok — record a FAILING result for it directly
        // (bypassing checkNow()) with a fresh timestamp, so that if
        // checkFresh() incorrectly re-checked instead of trusting the cache,
        // the assertion below would flip from false to true and fail.
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $this->storageLocationRepository->recordCheckResult($id, false, 'stale cached failure');
        $location = $this->storageLocationRepository->findById($id);

        $result = $this->service->checkFresh($location);

        $this->assertFalse($result->lastCheckOk);
        $this->assertSame('stale cached failure', $result->lastCheckError);
    }

    public function testResolveLocationForAlbumReturnsTheAlbumsOwnLocation(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $albumId = $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $id, $this->authorId);
        $album = $this->albumRepository->findById($albumId);

        $location = $this->service->resolveLocationForAlbum($album);

        $this->assertNotNull($location);
        $this->assertSame($id, $location->id);
    }
}
