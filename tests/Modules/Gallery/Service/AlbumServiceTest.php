<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Service\OgScraperService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use Modules\Gallery\Service\StoredFileCleaner;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
    private FileRepository $fileRepository;
    private StoredFileCleaner $storedFileCleaner;
    private MediaRepository $mediaRepository;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->mediaRepository = new MediaRepository($this->pdo);
        $mediaRepository = $this->mediaRepository;
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

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}')");
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
        $this->fileRepository = new FileRepository($this->pdo);
        $this->uploadHandler = new UploadHandler($this->fileRepository, sys_get_temp_dir());
        $this->storedFileCleaner = new StoredFileCleaner($this->fileRepository, sys_get_temp_dir());

        $this->service = new AlbumService(
            $this->albumRepository, $mediaRepository, $this->accessService, $ogScraperService,
            $this->storageBackendFactory, $this->storageLocationRepository, $this->storageLocationService,
            $scoutYearService, $settingService, $this->schedulerService, $this->uploadHandler,
            null, null, $this->storedFileCleaner
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
        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}')");

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

    public function testADelegatedAlbumCanBeMovedByAnAdministrator(): void
    {
        // The whole reason delegated albums were made visible on the storage
        // page: they occupy a real location and somebody has to be able to
        // move them. Gallery still neither browses nor edits them.
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Photos du groupe', null, '2026-01-01', null,
            $this->scoutYearId, null, $this->locationId, $this->authorId,
            'discussion_group', 7
        );
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->service->startMigration($id, $targetId, Role::SUPERADMIN, 'admin@test.com');

        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $this->albumRepository->findById($id)->migrationStatus);
    }

    public function testADelegatedAlbumIsNotAChiefsToMove(): void
    {
        // Section management says nothing about an album another module owns
        // and access-controls: this is an administrator's call, and the rule
        // lives in the service rather than only in the route.
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Photos du groupe', null, '2026-01-01', null,
            $this->scoutYearId, null, $this->locationId, $this->authorId,
            'discussion_group', 7
        );
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->expectException(GalleryException::class);
        $this->expectExceptionMessageMatches('/administrateur/');

        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');
    }

    public function testADelegatedAlbumIsNeverMovedOntoAPublicUrlLocation(): void
    {
        // The invariant DelegatedAlbumService::ensureAlbum() enforces at
        // creation, enforced again at every move: migrating a group's photos
        // onto a public-prefix bucket would publish them to anyone holding
        // the URL, silently and for ever.
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Photos du groupe', null, '2026-01-01', null,
            $this->scoutYearId, null, $this->locationId, $this->authorId,
            'discussion_group', 7
        );
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket public', null, 'custom', 'https://s3.test', 'eu',
            'bucket', 'ak', 'https://cdn.test', 'sk'
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->expectException(GalleryException::class);
        $this->expectExceptionMessageMatches('/URL publique/');

        $this->service->startMigration($id, $targetId, Role::SUPERADMIN, 'admin@test.com');
    }

    public function testAnOrdinaryAlbumMayStillGoToAPublicUrlLocation(): void
    {
        // The restriction is about delegated albums, not about public
        // locations: an ordinary album on a CDN is a supported setup.
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null,
            $this->scoutYearId, null, $this->locationId, $this->authorId
        );
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket public', null, 'custom', 'https://s3.test', 'eu',
            'bucket', 'ak', 'https://cdn.test', 'sk'
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');

        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $this->albumRepository->findById($id)->migrationStatus);
    }

    public function testDelegatedAlbumsAreListedForAdministrationAndNowhereElse(): void
    {
        $ordinary = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null,
            $this->scoutYearId, null, $this->locationId, $this->authorId
        );
        $delegated = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Photos du groupe', null, '2026-01-01', null,
            $this->scoutYearId, null, $this->locationId, $this->authorId,
            'discussion_group', 7
        );

        $manageIds = array_map(fn(Album $a) => $a->id, $this->service->findAllForManage());
        $adminIds = array_map(fn(Album $a) => $a->id, $this->service->findDelegatedForAdministration());

        $this->assertContains($ordinary, $manageIds);
        $this->assertNotContains($delegated, $manageIds, 'Gallery never browses or edits a delegated album.');
        $this->assertSame([$delegated], $adminIds);
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

    public function testUpdateThrowsForADelegatedAlbum(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId,
            $this->authorId, 'some_owner_type', 42
        );

        $this->expectException(GalleryException::class);
        $this->service->update($id, 'Nouveau titre', null, '2026-01-01', null, null, Role::CHIEF, 'chief@test.com');
    }

    public function testDeleteThrowsForADelegatedAlbum(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId,
            $this->authorId, 'some_owner_type', 42
        );

        $this->expectException(GalleryException::class);
        $this->service->delete($id, Role::CHIEF, 'chief@test.com');
    }

    public function testSetCoverThrowsForADelegatedAlbum(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId,
            $this->authorId, 'some_owner_type', 42
        );
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = (new MediaRepository($this->pdo))->create($id, 'photo', $fileId, 0, null);

        $this->expectException(GalleryException::class);
        $this->service->setCover($id, $mediaId, Role::CHIEF, 'chief@test.com');
    }

    public function testStartMigrationThrowsForADelegatedAlbum(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId,
            $this->authorId, 'some_owner_type', 42
        );
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);

        $this->expectException(GalleryException::class);
        $this->service->startMigration($id, $targetId, Role::CHIEF, 'chief@test.com');
    }

    private function settingServiceAllowingEverything(): SettingService
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        return $settingService;
    }

    /**
     * An external album's link is rendered as an ordinary href on the member
     * gallery and the member page. FILTER_VALIDATE_URL accepts
     * "javascript:alert(1)", so the scheme has to be an explicit allowlist —
     * otherwise a chief-level account can store XSS aimed at every identified
     * member.
     *
     * @dataProvider unsafeExternalUrls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeExternalUrls')]
    public function testCreateRejectsAnExternalUrlThatIsNotHttp(string $url): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, $url, $this->authorId, Role::CHIEF, 'chief@test.com'
        );
    }

    /**
     * @dataProvider unsafeExternalUrls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeExternalUrls')]
    public function testUpdateRejectsAnExternalUrlThatIsNotHttp(string $url): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com/album', null, $this->authorId
        );

        $this->expectException(GalleryException::class);
        $this->service->update($id, 'Titre', null, '2026-01-01', null, $url, Role::CHIEF, 'chief@test.com');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeExternalUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript with newline' => ["java\nscript:alert(1)"],
            'data url' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file' => ['file:///etc/passwd'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'scheme-relative' => ['//evil.example/album'],
            'no scheme at all' => ['evil.example/album'],
            'scheme without host' => ['https:///album'],
        ];
    }

    public function testCreateKeepsAValidHttpsExternalUrl(): void
    {
        $album = $this->service->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, '  https://photos.example.org/album  ',
            $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $this->assertSame('https://photos.example.org/album', $album->externalUrl);
    }

    public function testCreateAcceptsAPlainHttpExternalUrl(): void
    {
        $album = $this->service->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, 'http://photos.example.org/album',
            $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $this->assertSame('http://photos.example.org/album', $album->externalUrl);
    }

    /**
     * A local album has no link of its own — one posted on the side must not
     * be persisted.
     */
    public function testCreateDropsAnExternalUrlPostedForALocalAlbum(): void
    {
        $album = $this->service->create(
            Album::TYPE_LOCAL, 'Camp', null, '2026-07-01', null, 'javascript:alert(1)',
            $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $this->assertNull($album->externalUrl);
    }

    public function testCreateRejectsAnOverLongTitle(): void
    {
        $this->expectException(GalleryException::class);
        $this->expectExceptionMessageMatches('/255/');
        $this->service->create(
            Album::TYPE_LOCAL, str_repeat('a', 256), null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com'
        );
    }

    public function testCreateRejectsAnOverLongSubtitle(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(
            Album::TYPE_LOCAL, 'Camp', str_repeat('b', 256), '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com'
        );
    }

    public function testCreateRejectsAnOverLongExternalUrl(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, 'https://example.org/' . str_repeat('c', 500),
            $this->authorId, Role::CHIEF, 'chief@test.com'
        );
    }

    public function testUpdateRejectsAnOverLongTitle(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId
        );

        $this->expectException(GalleryException::class);
        $this->service->update($id, str_repeat('a', 256), null, '2026-01-01', null, null, Role::CHIEF, 'chief@test.com');
    }

    /**
     * A scraped title is third-party text of unbounded length; truncating it
     * beats a PDOException on a VARCHAR(255) column, and the chief never
     * typed it in the first place.
     */
    public function testAnOverLongScrapedTitleIsTruncatedRatherThanRejected(): void
    {
        $longImageUrl = 'https://example.com/' . str_repeat('d', 600) . '.jpg';
        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn([
            'title' => str_repeat('é', 400),
            'description' => 'Desc',
            'image' => $longImageUrl,
        ]);
        // The image download must be attempted with the FULL url, never the
        // clipped one — a truncated url fetches nothing.
        $ogScraperService->expects($this->once())->method('fetchImageBytes')->with($longImageUrl)->willReturn(null);
        $service = $this->serviceWithScraper($ogScraperService);

        $album = $service->create(
            Album::TYPE_EXTERNAL, '', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $this->assertSame(255, mb_strlen($album->title));
        $this->assertSame(255, mb_strlen((string) $album->ogTitle));
        $this->assertSame(500, mb_strlen((string) $album->ogImageUrl));
    }

    public function testSetCoverIsRejectedWhileTheAlbumIsMigrating(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId
        );
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('cover', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $mediaId = $this->mediaRepository->create($id, 'photo', (int) $this->pdo->lastInsertId(), 0, null);
        $this->albumRepository->startMigration($id, $this->locationId);

        $this->expectException(GalleryException::class);
        $this->expectExceptionMessageMatches('/migration/i');
        $this->service->setCover($id, $mediaId, Role::CHIEF, 'chief@test.com');
    }

    /**
     * Deleting an album removed its renditions from the storage backend but
     * never the staging originals behind its media rows, nor the cached
     * og:image of an external album — both survived indefinitely.
     */
    public function testDeleteAlsoRemovesEveryMediaOriginal(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId
        );
        $fileIds = [];
        foreach (['one', 'two'] as $name) {
            $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute(["gallery/{$id}/orig/{$name}.jpg", "{$name}.jpg", 'image/jpeg', 10, 'identified']);
            $fileId = (int) $this->pdo->lastInsertId();
            $fileIds[] = $fileId;
            $this->mediaRepository->create($id, 'photo', $fileId, 0, null);
        }
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $this->service->delete($id, Role::CHIEF, 'chief@test.com');

        $this->assertSame([], $this->mediaRepository->findByAlbumId($id));
        foreach ($fileIds as $fileId) {
            $this->assertNull($this->fileRepository->findById($fileId), 'original file row still present');
        }
    }

    public function testDeleteAlsoRemovesTheCachedOgImageOfAnExternalAlbum(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(['gallery/9/og/cover.jpg', 'og_image', 'image/jpeg', 10, 'identified']);
        $ogFileId = (int) $this->pdo->lastInsertId();
        $id = $this->albumRepository->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com/album', null, $this->authorId
        );
        $this->albumRepository->updateOgMetadata($id, 'Titre', 'Desc', 'https://example.com/img.jpg', $ogFileId);

        $this->service->delete($id, Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->fileRepository->findById($ogFileId));
    }

    /**
     * Every "Rafraîchir l'aperçu" click cached a new og:image and abandoned
     * the previous one.
     */
    public function testRefreshOgDiscardsThePreviousCachedImage(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($image);

        $ogScraperService = $this->createMock(OgScraperService::class);
        $ogScraperService->method('fetch')->willReturn(['title' => 'Titre', 'description' => 'Desc', 'image' => 'https://example.com/img.jpg']);
        $ogScraperService->method('fetchImageBytes')->willReturn($jpegBytes);
        $service = $this->serviceWithScraper($ogScraperService);

        $album = $service->create(
            Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, 'https://example.com/album', $this->authorId, Role::CHIEF, 'chief@test.com'
        );
        $firstFileId = $album->ogImageFileId;
        $this->assertNotNull($firstFileId);

        $refreshed = $service->refreshOg($album->id, Role::CHIEF, 'chief@test.com');

        $this->assertNotSame($firstFileId, $refreshed->ogImageFileId);
        $this->assertNull($this->fileRepository->findById($firstFileId), 'superseded og image still present');
        $this->assertNotNull($this->fileRepository->findById((int) $refreshed->ogImageFileId));
    }

    private function serviceWithScraper(OgScraperService $ogScraperService): AlbumService
    {
        return new AlbumService(
            $this->albumRepository, $this->mediaRepository, $this->accessService, $ogScraperService,
            $this->storageBackendFactory, $this->storageLocationRepository, $this->storageLocationService,
            new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(), $this->schedulerService,
            $this->uploadHandler, null, null, $this->storedFileCleaner
        );
    }

    public function testCreateDispatchesAlbumPublishedToEveryAccountExceptTheAuthor(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $notificationRepository = new NotificationRepository($this->pdo, $encryption);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $notificationService = new NotificationService(
            $notificationRepository,
            new PushSubscriptionRepository($this->pdo, $encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $userAccountRepository
        );
        $notificationService->registerModuleTypes('gallery', [[
            'id' => 'gallery.album_published', 'label' => 'Nouvel album', 'description' => 'd',
            'group' => 'Galerie', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);

        $other = $userAccountRepository->create('other-member@test.com')->id;

        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $this->accessService, $this->createMock(OgScraperService::class),
            $this->storageBackendFactory, $this->storageLocationRepository, $this->storageLocationService,
            new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything(), $this->schedulerService, $this->uploadHandler,
            $notificationService, $userAccountRepository
        );

        $album = $service->create(
            Album::TYPE_LOCAL, 'Camp', null, '2026-07-01', $this->sectionId, null, $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $authorNotifications = $notificationRepository->findByUserAccountId($this->authorId);
        $otherNotifications = $notificationRepository->findByUserAccountId($other);
        $this->assertCount(1, $authorNotifications); // row created, just no push (actor exclusion)
        $this->assertCount(1, $otherNotifications);
        $this->assertSame('gallery.album_published', $otherNotifications[0]->typeId);
        $this->assertSame($album->title, $otherNotifications[0]->body);
    }
}
