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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

    /**
     * `lastCheckError` is rendered on the gallery configuration page, and
     * checkNow() used to store S3StorageBackend::testConnection()'s return
     * value straight from the AWS SDK — "Error executing \"HeadBucket\" …
     * AWS HTTP error: cURL error 7", endpoint and request id included.
     */
    public function testAFailedS3CheckStoresAFrenchReasonNotAnSdkMessage(): void
    {
        $id = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket', null, 'custom',
            'http://127.0.0.1:1', 'eu', 'bucket', 'access-key', null, 'secret'
        );

        $this->service->checkNow($this->storageLocationRepository->findById($id));

        $location = $this->storageLocationRepository->findById($id);
        $this->assertFalse($location->lastCheckOk);
        $stored = (string) $location->lastCheckError;
        foreach (['AWS', 'cURL', 'HeadBucket', 'Error executing', 'GuzzleHttp', 'http://'] as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $stored);
        }
        $this->assertStringContainsString('Connexion au bucket impossible', $stored);
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

    // --- remaining disk space (Galerie configuration page) ---

    public function testDiskSpaceIsReportedForALocalLocation(): void
    {
        mkdir($this->storagePath . '/gallery', 0755, true);
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);

        $space = $this->service->diskSpaceFor($this->storageLocationRepository->findById($id));

        $this->assertNotNull($space);
        $this->assertGreaterThan(0, $space->freeBytes);
        $this->assertGreaterThanOrEqual($space->freeBytes, $space->totalBytes);
        $this->assertNotSame('', $space->freeLabel());
    }

    /** A bucket's capacity belongs to its provider — this page cannot know it. */
    public function testDiskSpaceIsNotReportedForAnS3Location(): void
    {
        $id = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket', null, 'custom',
            'https://example.invalid', 'eu', 'bucket', 'access-key', null, 'secret'
        );

        $this->assertNull($this->service->diskSpaceFor($this->storageLocationRepository->findById($id)));
    }

    /**
     * A location configured a minute ago has no directory until the first
     * upload or the first health check — and its volume is the storage
     * root's volume, so answering "unknown" there would hide the number on
     * exactly the location whose administrator is asking.
     */
    public function testDiskSpaceFallsBackToTheStorageRootWhenTheSubdirDoesNotExistYet(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Neuf', 'jamais-cree', null, null, null, null, null, null, null);

        $space = $this->service->diskSpaceFor($this->storageLocationRepository->findById($id));

        $this->assertNotNull($space);
        $this->assertGreaterThan(0, $space->freeBytes);
    }

    /**
     * The "running out" threshold is the biggest file the gallery would
     * actually accept, so it follows the two upload limits rather than an
     * arbitrary number of gigabytes.
     */
    public function testTheLowThresholdFollowsTheLargestAllowedUpload(): void
    {
        $this->settingService->register('gallery_allow_video', '1', 'boolean', 'l', 'd', 'gallery');
        $this->settingService->register('gallery_max_photo_upload_mb', '30', 'number', 'l', 'd', 'gallery');
        $this->settingService->register('gallery_max_video_upload_mb', '2048', 'number', 'l', 'd', 'gallery');
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $location = $this->storageLocationRepository->findById($id);

        $this->assertSame(2048 * 1024 * 1024, $this->service->diskSpaceFor($location)->warnBelowBytes);

        // Video off: the largest file this installation accepts is a photo,
        // and warning about a 2 Go limit it refuses would warn about
        // something that cannot happen.
        $this->settingService->set('gallery_allow_video', '0', 'gallery');
        $this->settingService->clearCache();

        $this->assertSame(30 * 1024 * 1024, $this->service->diskSpaceFor($location)->warnBelowBytes);
    }
}
