<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\OgScraperService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
class AlbumServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albumRepository;
    private StorageLocationRepository $storageLocationRepository;
    private StorageLocationService $storageLocationService;
    private AlbumService $service;
    private GalleryAccessService $accessService;
    private StorageBackendFactory $storageBackendFactory;
    private SchedulerService $schedulerService;
    private UploadHandler $uploadHandler;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $mediaRepository = new MediaRepository($this->pdo);
        $this->accessService = $this->createMock(GalleryAccessService::class);
        $this->accessService->method('canManageAlbum')->willReturn(true);
        $ogScraperService = $this->createMock(OgScraperService::class);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        $this->storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $this->storageBackendFactory,
            $settingService, new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $scoutYearService = new ScoutYearService($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'MEUTE_A', 'Meute A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->locationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $this->schedulerService = $this->createMock(SchedulerService::class);
        $this->uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());

        $this->service = new AlbumService(
            $this->albumRepository, $mediaRepository, $this->accessService, $ogScraperService,
            $this->storageBackendFactory, $this->storageLocationRepository, $this->storageLocationService,
            $scoutYearService, $settingService, $this->schedulerService, $this->uploadHandler
        );
    }

    public function testCreateLocalAlbum(): void
    {
        $album = $this->service->create(
            Album::TYPE_LOCAL, 'Camp', 'Semaine 1', '2026-07-01', $this->sectionId, null, $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $this->assertSame('Camp', $album->title);
        $this->assertSame($this->scoutYearId, $album->scoutYearId);
        $this->assertSame($this->locationId, $album->storageLocationId);
    }

    public function testCreateAlwaysUsesTheDefaultLocationRegardlessOfHowManyExist(): void
    {
        $otherLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        // $this->locationId (created first in setUp) stays the default —
        // creating a second location never changes that.
        $this->assertNotSame($otherLocationId, $this->locationId);

        $album = $this->service->create(Album::TYPE_LOCAL, 'Titre', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');

        $this->assertSame($this->locationId, $album->storageLocationId);
    }

    public function testCreateRejectsEmptyTitle(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(Album::TYPE_LOCAL, '   ', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateRejectsInvalidType(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create('bogus', 'Titre', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateExternalAlbumRequiresAUrl(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateExternalAlbumFetchesTitleFromOgDataWhenTitleIsBlank(): void
    {
        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn(['title' => 'Album de la famille Dupont', 'description' => 'Desc', 'image' => 'https://example.com/img.jpg']);
        $settingService = $this->settingServiceAllowingEverything();
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $this->accessService,
            $ogScraperService, $this->storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $settingService, $this->schedulerService, $this->uploadHandler
        );

        $album = $service->create(Album::TYPE_EXTERNAL, '', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com');

        $this->assertSame('Album de la famille Dupont', $album->title);
        $this->assertSame('Album de la famille Dupont', $album->ogTitle);
    }

    public function testCreateExternalAlbumCachesTheOgImageLocallyInsteadOfHotlinkingIt(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);
        $jpegBytes = ob_get_clean();
        imagedestroy($image);

        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn(['title' => 'Titre', 'description' => 'Desc', 'image' => 'https://example.com/img.jpg']);
        $ogScraperService->method('fetchImageBytes')->with('https://example.com/img.jpg')->willReturn($jpegBytes);
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $this->accessService,
            $ogScraperService, $this->storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(),
            $this->schedulerService, $this->uploadHandler
        );

        $album = $service->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com');

        $this->assertNotNull($album->ogImageFileId);
        $stmt = $this->pdo->prepare('SELECT mime_type FROM files WHERE id = ?');
        $stmt->execute([$album->ogImageFileId]);
        $this->assertSame('image/jpeg', $stmt->fetchColumn());
    }

    public function testCreateExternalAlbumLeavesOgImageFileIdNullWhenTheImageDownloadFails(): void
    {
        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn(['title' => 'Titre', 'description' => 'Desc', 'image' => 'https://example.com/img.jpg']);
        $ogScraperService->method('fetchImageBytes')->willReturn(null);
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $this->accessService,
            $ogScraperService, $this->storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(),
            $this->schedulerService, $this->uploadHandler
        );

        $album = $service->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com');

        $this->assertNull($album->ogImageFileId);
    }

    public function testCreateExternalAlbumRequiresATitleWhenOgFetchYieldsNoTitle(): void
    {
        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn(['title' => '', 'description' => '', 'image' => '']);
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $this->accessService,
            $ogScraperService, $this->storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(), $this->schedulerService, $this->uploadHandler
        );

        $this->expectException(GalleryException::class);
        $service->create(Album::TYPE_EXTERNAL, '', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateExternalAlbumKeepsAnExplicitTitleEvenWhenOgDataDiffers(): void
    {
        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn(['title' => 'Titre de la page', 'description' => '', 'image' => '']);
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $this->accessService,
            $ogScraperService, $this->storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(), $this->schedulerService, $this->uploadHandler
        );

        $album = $service->create(Album::TYPE_EXTERNAL, 'Mon titre à moi', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com');

        $this->assertSame('Mon titre à moi', $album->title);
    }

    public function testCreateRejectsWhenTheChiefDoesNotManageTheSection(): void
    {
        $accessService = $this->createMock(GalleryAccessService::class);
        $accessService->method('canManageAlbum')->willReturn(false);
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $accessService,
            $this->createMock(OgScraperService::class), $this->storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(), $this->schedulerService, $this->uploadHandler
        );

        $this->expectException(GalleryException::class);
        $service->create(Album::TYPE_LOCAL, 'Titre', null, '2026-07-01', $this->sectionId, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testUpdateChangesTitle(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);

        $updated = $this->service->update($id, 'Nouveau titre', null, '2026-01-01', null, null, Role::CHIEF, 'chief@test.com');

        $this->assertSame('Nouveau titre', $updated->title);
    }

    public function testUpdateThrowsWhenAlbumDoesNotExist(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->update(999999, 'Titre', null, '2026-01-01', null, null, Role::CHIEF, 'chief@test.com');
    }

    public function testDeleteRemovesTheAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->once())->method('deletePrefix')->with((string) $id);
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $this->service->delete($id, Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->albumRepository->findById($id));
    }

    public function testDeleteExternalAlbumNeverTouchesStorage(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);
        $this->storageBackendFactory->expects($this->never())->method('create');

        $this->service->delete($id, Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->albumRepository->findById($id));
    }

    public function testSetCoverRejectsMediaFromAnotherAlbum(): void
    {
        $id1 = $this->albumRepository->create(Album::TYPE_LOCAL, 'Album 1', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $id2 = $this->albumRepository->create(Album::TYPE_LOCAL, 'Album 2', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaRepository = new MediaRepository($this->pdo);
        $mediaId = $mediaRepository->create($id2, 'photo', $fileId, 0, null);

        $this->expectException(GalleryException::class);
        $this->service->setCover($id1, $mediaId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateLocalAlbumUsesTheAutoBackfilledLocationWhenNoneExistsYet(): void
    {
        // A brand new PDO/tables with zero locations — createTestDatabase()
        // gives an independent in-memory DB so no backfill can silently
        // create one behind this test's back. create() itself must trigger
        // ensureLegacyLocationBackfilled() so a fresh/upgraded install
        // always has somewhere to put a local album's media.
        $pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($pdo);
        $stmt = $pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $storageLocationRepository = new StorageLocationRepository($pdo, $encryption);
        $albumRepository = new AlbumRepository($pdo);
        $storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $settingService = $this->settingServiceAllowingEverything();
        $storageLocationService = new StorageLocationService(
            $storageLocationRepository, $albumRepository, $storageBackendFactory,
            $settingService, new S3SecretRepository($pdo, $encryption), sys_get_temp_dir()
        );
        $service = new AlbumService(
            $albumRepository, new MediaRepository($pdo), $this->accessService,
            $this->createMock(OgScraperService::class), $storageBackendFactory, $storageLocationRepository,
            $storageLocationService, new ScoutYearService($pdo), $settingService, $this->schedulerService,
            new UploadHandler(new FileRepository($pdo), sys_get_temp_dir())
        );

        $this->assertSame([], $storageLocationRepository->findAll());

        $album = $service->create(Album::TYPE_LOCAL, 'Titre', null, '2026-07-01', null, null, $authorId, Role::CHIEF, 'chief@test.com');

        $backfilled = $storageLocationRepository->findDefault();
        $this->assertNotNull($backfilled);
        $this->assertSame($backfilled->id, $album->storageLocationId);
    }

    public function testStartMigrationSchedulesTheTaskAndMarksInProgress(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->schedulerService->expects($this->once())->method('scheduleAfter')
            ->with('gallery', 'migrate_album_storage', 0, ['album_id' => $id], 'album_migration_' . $id);

        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');

        $album = $this->albumRepository->findById($id);
        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $album->migrationStatus);
        $this->assertSame($targetId, $album->migrationTargetLocationId);
    }

    public function testStartMigrationRejectsAnUnhealthyTarget(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket cassé', null, 'custom', 'https://x', 'eu', 'bucket', 'ak', null, 'sk'
        );
        $this->storageLocationRepository->recordCheckResult($targetId, false, 'Connexion refusée.');

        $this->expectException(GalleryException::class);
        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');
    }

    public function testStartMigrationRejectsTheSameLocationAsCurrent(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);

        $this->expectException(GalleryException::class);
        $this->service->startMigration($id, $this->locationId, Role::CHIEF, 'chief@test.com');
    }

    public function testStartMigrationRejectsWhenAlreadyInProgress(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $this->albumRepository->startMigration($id, $targetId);

        $this->expectException(GalleryException::class);
        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');
    }

    public function testStartMigrationRejectsAnExternalAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->expectException(GalleryException::class);
        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');
    }

    public function testDeleteRejectsAnAlbumMidMigration(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $this->albumRepository->startMigration($id, $this->locationId);

        $this->expectException(GalleryException::class);
        $this->service->delete($id, Role::CHIEF, 'chief@test.com');
    }

    private function settingServiceAllowingEverything(): SettingService
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        return $settingService;
    }
}
