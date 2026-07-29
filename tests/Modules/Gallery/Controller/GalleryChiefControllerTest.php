<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Gallery\Controller\GalleryChiefController;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\OgScraperService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class GalleryChiefControllerTest extends TestCase
{
    private \PDO $pdo;
    private GalleryChiefController $controller;
    private AlbumRepository $albumRepository;
    private MediaRepository $mediaRepository;
    private StorageLocationRepository $storageLocationRepository;
    private int $authorId;
    private int $scoutYearId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->mediaRepository = new MediaRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $memberService = new MemberService($memberYearRepo, $encryption, $connection);
        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);

        $accessService = $this->createMock(GalleryAccessService::class);
        $accessService->method('canManageAlbum')->willReturn(true);
        $accessService->method('getManagedSectionIds')->willReturn([]);
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $storageLocationRepository = $this->storageLocationRepository;
        $storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $storageLocationService = new StorageLocationService(
            $storageLocationRepository, $this->albumRepository, $storageBackendFactory, $settingService,
            new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );

        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $albumService = new AlbumService(
            $this->albumRepository, $this->mediaRepository, $accessService, $this->createMock(OgScraperService::class),
            $storageBackendFactory, $storageLocationRepository, $storageLocationService, $scoutYearService, $settingService,
            $schedulerService
        );
        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $mediaService = new MediaService(
            $this->mediaRepository, $this->albumRepository, $uploadHandler, $schedulerService,
            $settingService, $accessService, $storageBackendFactory, $storageLocationService, $this->createMock(FfmpegAvailability::class)
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->locationId = $storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFilter(new \Twig\TwigFilter('french_date', fn($d) => (string) $d));

        $this->controller = new GalleryChiefController(
            $twig, $albumService, $mediaService, $this->mediaRepository, $accessService, $sectionService, $settingService,
            $storageLocationRepository, $storageLocationService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->authorId, 'chief@test.be', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    private function createLocalAlbum(): int
    {
        return $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data, string $path = '/gallery/1/delete'): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testManageListsAllAlbums(): void
    {
        $this->createLocalAlbum();

        $response = $this->controller->manage(new Request('GET', '/gallery/manage', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Camp', $response->getBody());
    }

    public function testStoreRequiresCsrf(): void
    {
        $request = new Request('POST', '/gallery', [], ['title' => 'Camp', 'album_date' => '2026-01-01', '_csrf_token' => 'bad'], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStoreCreatesLocalAlbumAndRedirects(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery', [], [
            'type' => 'local', 'title' => 'Camp d\'été', 'album_date' => '2026-07-01',
            'storage_location_id' => (string) $this->locationId, '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
    }

    public function testStoreRejectsEmptyTitleWith422(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery', [], [
            'type' => 'local', 'title' => '', 'album_date' => '2026-07-01', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testEditReturns404ForUnknownAlbum(): void
    {
        $response = $this->controller->edit(new Request('GET', '/gallery/999/edit', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateChangesTitle(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery/' . $id, [], [
            'title' => 'Nouveau titre', 'album_date' => '2026-01-01', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->update($request, ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Nouveau titre', $this->albumRepository->findById($id)->title);
    }

    public function testDeleteRequiresCsrf(): void
    {
        $id = $this->createLocalAlbum();
        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => 'bad']), ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDeleteRemovesTheAlbum(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->albumRepository->findById($id));
    }

    public function testUploadMediaReturns404ForUnknownAlbum(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery/999/media', [], ['_csrf_token' => $token], [], []);

        $response = $this->controller->uploadMedia($request, ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUploadMediaReturns400WhenNoFileSent(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery/' . $id . '/media', [], ['_csrf_token' => $token], [], []);

        $response = $this->controller->uploadMedia($request, ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUploadMediaSucceedsWithAFile(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();

        $path = tempnam(sys_get_temp_dir(), 'gallery_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path);
        imagedestroy($image);
        $_FILES['file'] = ['name' => 'photo.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'type' => 'image/jpeg'];

        $request = new Request('POST', '/gallery/' . $id . '/media', [], ['_csrf_token' => $token], [], []);
        $response = $this->controller->uploadMedia($request, ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame(1, $this->mediaRepository->countByAlbumId($id));

        unset($_FILES['file']);
    }

    public function testReorderMediaValidatesIds(): void
    {
        $id = $this->createLocalAlbum();
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $this->mediaRepository->create($id, 'photo', $fileId, 0, null);
        $token = $this->csrfToken();

        $response = $this->controller->reorderMedia($this->jsonRequest(['ordered_ids' => [999999], '_csrf_token' => $token]), ['id' => (string) $id]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testDeleteMediaReturns404WhenMediaBelongsToAnotherAlbum(): void
    {
        $id1 = $this->createLocalAlbum();
        $id2 = $this->albumRepository->create(Album::TYPE_LOCAL, 'Autre', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($id2, 'photo', $fileId, 0, null);
        $token = $this->csrfToken();

        $response = $this->controller->deleteMedia($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id1, 'media_id' => (string) $mediaId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSetCoverRequiresCsrf(): void
    {
        $id = $this->createLocalAlbum();
        $response = $this->controller->setCover($this->jsonRequest(['media_id' => 1, '_csrf_token' => 'bad']), ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRefreshOgReturnsErrorForLocalAlbum(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();

        $response = $this->controller->refreshOg($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateStorageStartsAMigrationToAHealthyOtherLocation(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $token = $this->csrfToken();

        $response = $this->controller->migrateStorage(
            $this->jsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $this->albumRepository->findById($id)->migrationStatus);
    }

    public function testMigrateStorageRejectsWhenAlreadyInProgress(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $this->albumRepository->startMigration($id, $targetId);
        $token = $this->csrfToken();

        $response = $this->controller->migrateStorage(
            $this->jsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testMigrateStorageRejectsTheCurrentLocationAsTarget(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();

        $response = $this->controller->migrateStorage(
            $this->jsonRequest(['target_location_id' => $this->locationId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateStorageRejectsAnUnhealthyTarget(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Cassé', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, false, 'Dossier inaccessible.');
        $token = $this->csrfToken();

        $response = $this->controller->migrateStorage(
            $this->jsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateStorageRejectsAnExternalAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Externe', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $token = $this->csrfToken();

        $response = $this->controller->migrateStorage(
            $this->jsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateStorageRequiresCsrf(): void
    {
        $id = $this->createLocalAlbum();

        $response = $this->controller->migrateStorage(
            $this->jsonRequest(['target_location_id' => 999, '_csrf_token' => 'bad']), ['id' => (string) $id]
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testEditRendersNoMigrationUiForAnExternalAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Externe', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertStringNotContainsString('gallery-migrate-start', $response->getBody());
    }

    public function testEditRendersMigrationUiForALocalAlbumWithAnotherLocationAvailable(): void
    {
        $id = $this->createLocalAlbum();
        $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertStringContainsString('gallery-migrate-start', $response->getBody());
    }

    public function testEditHidesUploadZoneAndShowsUnavailableWhileMigrating(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->albumRepository->startMigration($id, $targetId);

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertStringContainsString('Migration en cours', $response->getBody());
        $this->assertStringNotContainsString('gallery-upload-zone', $response->getBody());
    }
}
