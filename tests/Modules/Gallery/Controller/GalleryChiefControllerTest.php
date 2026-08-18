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
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $albumService = new AlbumService(
            $this->albumRepository, $this->mediaRepository, $accessService, $this->createMock(OgScraperService::class),
            $storageBackendFactory, $storageLocationRepository, $storageLocationService, $scoutYearService, $settingService,
            $schedulerService, $uploadHandler
        );
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
        $twig->addGlobal('current_path', '/gallery/create');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Nouvel album', 'parents' => ["Espace chefs"]]);
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

    private function createDelegatedAlbum(): int
    {
        return $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Album délégué', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId,
            $this->authorId, 'some_owner_type', 42
        );
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
            'type' => 'local', 'title' => 'Camp d\'été', 'album_date' => '2026-07-01', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
    }

    public function testStoreAlwaysUsesTheDefaultStorageLocationEvenIfClientSendsAnother(): void
    {
        $otherLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $token = $this->csrfToken();
        // A malicious/buggy client sending storage_location_id must never
        // influence which location is actually used — the field simply
        // isn't read by the controller anymore.
        $request = new Request('POST', '/gallery', [], [
            'type' => 'local', 'title' => 'Camp d\'été', 'album_date' => '2026-07-01',
            'storage_location_id' => (string) $otherLocationId, '_csrf_token' => $token,
        ], [], []);

        $this->controller->store($request, []);

        $albumId = (int) $this->pdo->query('SELECT id FROM gallery_albums ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame($this->locationId, $this->albumRepository->findById($albumId)->storageLocationId);
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

    public function testEditBreadcrumbShowsTheRealAlbumTitle(): void
    {
        // modules/gallery/module.json's /gallery/{id}/edit route only
        // declares the static "Modifier l'album" label — the edit page's
        // own breadcrumb_current (album title) is what actually surfaces
        // in the trail, same pattern as News's editorContext().
        $id = $this->createLocalAlbum();

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Camp\s*</',
            $response->getBody()
        );
    }

    public function testCreateBreadcrumbFallsBackToStaticLabel(): void
    {
        // No album yet on the create page — breadcrumb_current is null,
        // so the route's static breadcrumb.label applies via Twig's
        // default() filter.
        $response = $this->controller->create(new Request('GET', '/gallery/create', [], [], [], []), []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Nouvel album\s*</',
            $response->getBody()
        );
    }

    public function testAlbumFormNoLongerHasABackButton(): void
    {
        // The breadcrumb now covers navigating back to the album list —
        // the page's own "Retour" button to /gallery/manage was removed.
        $id = $this->createLocalAlbum();

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertStringNotContainsString('Retour', $response->getBody());
        $this->assertStringNotContainsString('/gallery/manage', $response->getBody());
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

    public function testEditNeverRendersMigrationUiOnTheChiefPage(): void
    {
        // Migration is triggered from Configuration > Galerie (superadmin)
        // now — see GalleryConfigControllerTest — never from this page,
        // even when another location is available to migrate to.
        $id = $this->createLocalAlbum();
        $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertStringNotContainsString('gallery-migrate-start', $response->getBody());
    }

    public function testEditHidesUploadZoneAndShowsUnavailableWhileMigrating(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->albumRepository->startMigration($id, $targetId);

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertStringContainsString('Album indisponible', $response->getBody());
        $this->assertStringNotContainsString('gallery-upload-zone', $response->getBody());
    }

    public function testEditReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum();

        $response = $this->controller->edit(new Request('GET', '/gallery/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum();
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery/' . $id, [], [
            'title' => 'Nouveau titre', 'album_date' => '2026-01-01', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->update($request, ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUploadMediaReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum();
        $token = $this->csrfToken();
        $request = new Request('POST', '/gallery/' . $id . '/media', [], ['_csrf_token' => $token], [], []);

        $response = $this->controller->uploadMedia($request, ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReorderMediaReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum();
        $token = $this->csrfToken();

        $response = $this->controller->reorderMedia($this->jsonRequest(['ordered_ids' => [], '_csrf_token' => $token]), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteMediaReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum();
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($id, 'photo', $fileId, 0, null);
        $token = $this->csrfToken();

        $response = $this->controller->deleteMedia($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id, 'media_id' => (string) $mediaId]);

        $this->assertSame(404, $response->getStatusCode());
    }
}
