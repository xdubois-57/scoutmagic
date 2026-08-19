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
use Core\Security\Role;
use Modules\Gallery\Controller\GalleryController;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\DelegatedAlbumAccessRegistry;
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
class GalleryControllerTest extends TestCase
{
    private \PDO $pdo;
    private GalleryController $controller;
    private AlbumRepository $albumRepository;
    private MediaRepository $mediaRepository;
    private MediaService $mediaService;
    private AlbumService $albumService;
    private StorageBackendFactory $storageBackendFactory;
    private StorageLocationService $storageLocationService;
    private StorageLocationRepository $storageLocationRepository;
    private Environment $twig;
    private MemberService $memberService;
    private SectionService $sectionService;
    private ScoutYearService $scoutYearService;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;
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
        $this->sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->memberService = new MemberService($memberYearRepo, $encryption, $connection);
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);

        $accessService = new GalleryAccessService($this->memberService, $this->sectionService, $this->scoutYearService);
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $this->storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $this->storageBackendFactory, $settingService,
            new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );

        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $this->albumService = new AlbumService(
            $this->albumRepository, $this->mediaRepository, $accessService, $this->createMock(OgScraperService::class),
            $this->storageBackendFactory, $this->storageLocationRepository, $this->storageLocationService, $this->scoutYearService, $settingService,
            $this->createMock(SchedulerService::class), $uploadHandler
        );
        $this->mediaService = new MediaService(
            $this->mediaRepository, $this->albumRepository, $uploadHandler, new SchedulerService(new SchedulerRepository($this->pdo)),
            $settingService, $accessService, $this->storageBackendFactory, $this->storageLocationService, $this->createMock(FfmpegAvailability::class)
        );

        $this->locationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'MEUTE_A', 'Meute A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'identified');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $this->twig->addFilter(new \Twig\TwigFilter('french_date', fn($d) => (string) $d));

        $this->controller = new GalleryController(
            $this->twig, $this->albumService, $this->mediaService, $this->mediaRepository, $this->memberService,
            $this->sectionService, $this->scoutYearService, $this->storageBackendFactory, $this->storageLocationService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'member@test.be', 'identified');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function createLocalAlbum(?int $sectionId = null): int
    {
        return $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', $sectionId, $this->scoutYearId, null, $this->locationId, $this->authorId);
    }

    private function createDelegatedAlbum(int $locationId): int
    {
        return $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Album délégué', null, '2026-01-01', null, $this->scoutYearId, null, $locationId,
            $this->authorId, 'some_owner_type', 42
        );
    }

    private function controllerWithRegistry(DelegatedAlbumAccessRegistry $registry): GalleryController
    {
        return new GalleryController(
            $this->twig, $this->albumService, $this->mediaService, $this->mediaRepository, $this->memberService,
            $this->sectionService, $this->scoutYearService, $this->storageBackendFactory, $this->storageLocationService,
            $registry
        );
    }

    private function allowingRegistry(): DelegatedAlbumAccessRegistry
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->method('supports')->willReturn(true);
        $checker->method('isAllowed')->willReturn(true);
        return new DelegatedAlbumAccessRegistry([$checker]);
    }

    private function denyingRegistry(): DelegatedAlbumAccessRegistry
    {
        $checker = $this->createMock(DelegatedAlbumAccessChecker::class);
        $checker->method('supports')->willReturn(true);
        $checker->method('isAllowed')->willReturn(false);
        return new DelegatedAlbumAccessRegistry([$checker]);
    }

    public function testShowReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum($this->locationId);

        $response = $this->controller->show(new Request('GET', '/gallery/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDownloadZipReturns404ForADelegatedAlbum(): void
    {
        $id = $this->createDelegatedAlbum($this->locationId);

        $response = $this->controller->downloadZip(new Request('GET', '/gallery/' . $id . '/download', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * @return array<string, string[]>
     */
    public static function mediaSizeProvider(): array
    {
        return [
            'thumb' => ['thumb'],
            'medium' => ['medium'],
            'large' => ['large'],
            'original' => ['original'],
        ];
    }

    /**
     * A photo media never gets an original_path (Repository\
     * MediaRepository::markPhotoDone() sets thumb/medium/large only; only
     * markVideoDone() also sets original) — so exercising "original"
     * across the data-provider tests below needs a video-typed media
     * instead, transparently to the caller.
     */
    private function createDoneMediaForSize(int $albumId, string $size): int
    {
        if ($size !== 'original') {
            return $this->createDoneMedia($albumId);
        }

        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'video/mp4', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($albumId, 'video', $fileId, 0, null);
        $this->mediaRepository->markVideoDone($mediaId, 'thumb.jpg', 'med.mp4', 'lg.mp4', 'orig.mp4', 100, 100, 30);
        return $mediaId;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mediaSizeProvider')]
    public function testServeMediaOnLocalStorageStreamsForAnAllowedMemberOfADelegatedAlbum(string $size): void
    {
        $albumId = $this->createDelegatedAlbum($this->locationId);
        $mediaId = $this->createDoneMediaForSize($albumId, $size);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-delegated-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);
        $controller = $this->controllerWithRegistry($this->allowingRegistry());

        $response = $controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/' . $size, [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => $size]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fake-delegated-bytes', $response->getBody());
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mediaSizeProvider')]
    public function testServeMediaOnLocalStorageReturns404ForANonMemberOfADelegatedAlbum(string $size): void
    {
        $albumId = $this->createDelegatedAlbum($this->locationId);
        $mediaId = $this->createDoneMediaForSize($albumId, $size);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->never())->method('get');
        $this->storageBackendFactory->method('create')->willReturn($backend);
        $controller = $this->controllerWithRegistry($this->denyingRegistry());

        $response = $controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/' . $size, [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => $size]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testServeMediaReturns404ForADelegatedAlbumWithAnUnregisteredOwnerType(): void
    {
        $albumId = $this->createDelegatedAlbum($this->locationId);
        $mediaId = $this->createDoneMedia($albumId);
        // No checker at all in the registry — same fail-closed contract as
        // a checker that actively denies (Service\DelegatedAlbumAccessRegistryTest).
        $controller = $this->controllerWithRegistry(new DelegatedAlbumAccessRegistry([]));

        $response = $controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => 'thumb']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    private function createPrivateS3Location(): int
    {
        return $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket privé', null, 'custom', 'https://s3.example.com', 'eu',
            'bucket', 'ak', null, 'sk'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mediaSizeProvider')]
    public function testServeMediaOnS3RedirectsToAFreshPresignedUrlForAnAllowedMemberOfADelegatedAlbum(string $size): void
    {
        $locationId = $this->createPrivateS3Location();
        $albumId = $this->createDelegatedAlbum($locationId);
        $mediaId = $this->createDoneMediaForSize($albumId, $size);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->once())->method('url')
            ->with($this->anything(), '+5 minutes')
            ->willReturn('https://s3.example.com/bucket/presigned?sig=abc');
        $this->storageBackendFactory->method('create')->willReturn($backend);
        $controller = $this->controllerWithRegistry($this->allowingRegistry());

        $response = $controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/' . $size, [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => $size]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://s3.example.com/bucket/presigned?sig=abc', $response->getHeaders()['Location']);
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mediaSizeProvider')]
    public function testServeMediaOnS3Returns404ForANonMemberOfADelegatedAlbum(string $size): void
    {
        $locationId = $this->createPrivateS3Location();
        $albumId = $this->createDelegatedAlbum($locationId);
        $mediaId = $this->createDoneMediaForSize($albumId, $size);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->never())->method('url');
        $this->storageBackendFactory->method('create')->willReturn($backend);
        $controller = $this->controllerWithRegistry($this->denyingRegistry());

        $response = $controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/' . $size, [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => $size]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testServeMediaOnDelegatedAlbumUsesAShorterPresignTtlThanTheOrdinaryDefault(): void
    {
        // Module spec: the delegated TTL must be short — distinct from and
        // shorter than S3StorageBackend::url()'s ordinary +1 hour default.
        $locationId = $this->createPrivateS3Location();
        $albumId = $this->createDelegatedAlbum($locationId);
        $mediaId = $this->createDoneMedia($albumId);
        $usedTtl = null;
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('url')->willReturnCallback(function (string $key, string $ttl = '+1 hour') use (&$usedTtl) {
            $usedTtl = $ttl;
            return 'https://s3.example.com/x';
        });
        $this->storageBackendFactory->method('create')->willReturn($backend);
        $controller = $this->controllerWithRegistry($this->allowingRegistry());

        $controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => 'thumb']
        );

        $this->assertNotNull($usedTtl);
        $this->assertNotSame('+1 hour', $usedTtl);
        $this->assertSame('+5 minutes', $usedTtl);
    }

    public function testIndexShowsUnitWideAlbum(): void
    {
        $this->createLocalAlbum(null);

        $response = $this->controller->index(new Request('GET', '/gallery', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Camp', $response->getBody());
    }

    public function testIndexHidesSectionScopedAlbumFromUnlinkedMember(): void
    {
        $this->createLocalAlbum($this->sectionId);

        $response = $this->controller->index(new Request('GET', '/gallery', [], [], [], []), []);

        $this->assertStringNotContainsString('Camp', $response->getBody());
    }

    public function testIndexShowsSectionScopedAlbumToAChiefRegardlessOfTheirOwnSections(): void
    {
        $this->createLocalAlbum($this->sectionId);
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->controller->index(new Request('GET', '/gallery', [], [], [], []), []);

        $this->assertStringContainsString('Camp', $response->getBody());
    }

    public function testShowReturns404ForUnknownAlbum(): void
    {
        $response = $this->controller->show(new Request('GET', '/gallery/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowReturns403ForSectionScopedAlbumWhenNotLinked(): void
    {
        $id = $this->createLocalAlbum($this->sectionId);

        $response = $this->controller->show(new Request('GET', '/gallery/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowRendersLocalAlbumMediaGrid(): void
    {
        $id = $this->createLocalAlbum(null);

        $response = $this->controller->show(new Request('GET', '/gallery/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Camp', $response->getBody());
    }

    public function testShowRedirectsToExternalUrlForExternalAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Album externe', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com/album', null, $this->authorId);

        $response = $this->controller->show(new Request('GET', '/gallery/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/album', $response->getHeaders()['Location']);
    }

    public function testServeMediaReturns404WhenMediaDoesNotExist(): void
    {
        $response = $this->controller->serveMedia(new Request('GET', '/gallery/media/999/thumb', [], [], [], []), ['media_id' => '999', 'size' => 'thumb']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testServeMediaReturns404WhenSizeNotYetProcessed(): void
    {
        $albumId = $this->createLocalAlbum(null);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($albumId, 'photo', $fileId, 0, null);

        $response = $this->controller->serveMedia(new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []), ['media_id' => (string) $mediaId, 'size' => 'thumb']);

        $this->assertSame(404, $response->getStatusCode());
    }

    private function createDoneMedia(int $albumId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($albumId, 'photo', $fileId, 0, null);
        $this->mediaRepository->markPhotoDone($mediaId, 'thumb.jpg', 'med.jpg', 'lg.jpg', 100, 100);
        return $mediaId;
    }

    public function testServeMediaReturnsTheFileWithAnEtagOnFirstRequest(): void
    {
        $albumId = $this->createLocalAlbum(null);
        $mediaId = $this->createDoneMedia($albumId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->serveMedia(new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []), ['media_id' => (string) $mediaId, 'size' => 'thumb']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fake-image-bytes', $response->getBody());
        $this->assertArrayHasKey('ETag', $response->getHeaders());
    }

    public function testServeMediaReturns304AndSkipsStorageWhenEtagMatches(): void
    {
        $albumId = $this->createLocalAlbum(null);
        $mediaId = $this->createDoneMedia($albumId);
        $etag = '"' . md5('thumb.jpg') . '"';
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->never())->method('get');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $request = new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $response = $this->controller->serveMedia($request, ['media_id' => (string) $mediaId, 'size' => 'thumb']);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
    }

    public function testDownloadZipReturns404ForExternalAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Externe', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);

        $response = $this->controller->downloadZip(new Request('GET', '/gallery/' . $id . '/download', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDownloadZipReturns403ForSectionScopedAlbumWhenNotLinked(): void
    {
        $id = $this->createLocalAlbum($this->sectionId);
        $this->createDoneMedia($id);

        $response = $this->controller->downloadZip(new Request('GET', '/gallery/' . $id . '/download', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDownloadZipReturns404WhenNoMediaIsDone(): void
    {
        $id = $this->createLocalAlbum(null);

        $response = $this->controller->downloadZip(new Request('GET', '/gallery/' . $id . '/download', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDownloadZipReturnsAZipContainingEachDoneMedia(): void
    {
        $id = $this->createLocalAlbum(null);
        $this->createDoneMedia($id);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-large-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->downloadZip(new Request('GET', '/gallery/' . $id . '/download', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->getHeaders()['Content-Type']);

        $tmp = tempnam(sys_get_temp_dir(), 'zip_assert_');
        file_put_contents($tmp, $response->getBody());
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('fake-large-image-bytes', $zip->getFromIndex(0));
        $zip->close();
        unlink($tmp);
    }

    public function testShowMarksAMigratingAlbumUnavailableAndHidesMedia(): void
    {
        $id = $this->createLocalAlbum(null);
        $this->createDoneMedia($id);
        $this->albumRepository->startMigration($id, $this->locationId);

        $response = $this->controller->show(new Request('GET', '/gallery/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('migration', $response->getBody());
    }

    public function testShowServesNormallyWhenMigrationHasFailed(): void
    {
        $id = $this->createLocalAlbum(null);
        $this->createDoneMedia($id);
        $this->albumRepository->startMigration($id, $this->locationId);
        $this->albumRepository->failMigration($id, 'boom');
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->show(new Request('GET', '/gallery/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('migration en cours', $response->getBody());
    }

    public function testServeMediaReturns503WhileMigrating(): void
    {
        $albumId = $this->createLocalAlbum(null);
        $mediaId = $this->createDoneMedia($albumId);
        $this->albumRepository->startMigration($albumId, $this->locationId);

        $response = $this->controller->serveMedia(new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []), ['media_id' => (string) $mediaId, 'size' => 'thumb']);

        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * module.json's role_min on this route only establishes that the caller
     * is an identified member — WHICH albums they may see is this module's own
     * section scoping, which show() and downloadZip() both applied and
     * serveMedia() did not. Media ids are sequential, so every section-scoped
     * album's photos were readable by any logged-in member.
     */
    public function testServeMediaReturns403ForSectionScopedAlbumWhenNotLinked(): void
    {
        $albumId = $this->createLocalAlbum($this->sectionId);
        $mediaId = $this->createDoneMedia($albumId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->never())->method('get');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => 'thumb']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testServeMediaSpendsNoStorageCallOnAForbiddenAlbumEvenWithAMatchingEtag(): void
    {
        $albumId = $this->createLocalAlbum($this->sectionId);
        $mediaId = $this->createDoneMedia($albumId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $this->storageBackendFactory->method('create')->willReturn($backend);

        // The 304 short-circuit must sit behind the authorization check, not
        // in front of it — otherwise it confirms the rendition exists.
        $request = new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], [
            'HTTP_IF_NONE_MATCH' => '"' . md5('thumb.jpg') . '"',
        ]);
        $response = $this->controller->serveMedia($request, ['media_id' => (string) $mediaId, 'size' => 'thumb']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testServeMediaServesASectionScopedAlbumToAChief(): void
    {
        $albumId = $this->createLocalAlbum($this->sectionId);
        $mediaId = $this->createDoneMedia($albumId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => 'thumb']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testServeMediaStillServesAUnitWideAlbumToAPlainMember(): void
    {
        $albumId = $this->createLocalAlbum(null);
        $mediaId = $this->createDoneMedia($albumId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => 'thumb']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fake-image-bytes', $response->getBody());
    }

    /**
     * @param array<string, string> $server
     */
    private function serveMediaWithRange(int $mediaId, string $size, array $server, int $objectSize, string $slice): \Core\Http\Response
    {
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('size')->willReturn($objectSize);
        $backend->method('getRange')->willReturn($slice);
        $backend->method('get')->willReturn(str_repeat('x', $objectSize));
        $this->storageBackendFactory->method('create')->willReturn($backend);

        return $this->controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/' . $size, [], [], [], $server),
            ['media_id' => (string) $mediaId, 'size' => $size]
        );
    }

    private function createDoneVideo(int $albumId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('v', 'v', 'video/mp4', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($albumId, 'video', $fileId, 0, null);
        $this->mediaRepository->markVideoDone($mediaId, 'poster.jpg', 'med.mp4', 'lg.mp4', null, 1920, 1080, 30);

        return $mediaId;
    }

    /**
     * Without Range support a video was only ever served whole: the entire
     * transcode into memory per request, and no seeking in the player (every
     * scrub restarted the download at byte zero).
     */
    public function testServeMediaAnswersARangeRequestWithA206(): void
    {
        $mediaId = $this->createDoneVideo($this->createLocalAlbum(null));

        $response = $this->serveMediaWithRange($mediaId, 'medium', ['HTTP_RANGE' => 'bytes=10-19'], 1000, '0123456789');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 10-19/1000', $response->getHeaders()['Content-Range']);
        $this->assertSame('bytes', $response->getHeaders()['Accept-Ranges']);
        $this->assertSame('video/mp4', $response->getHeaders()['Content-Type']);
        $this->assertSame('0123456789', $response->getBody());
    }

    /**
     * Browsers open a video with "Range: bytes=0-", i.e. everything. Honouring
     * that literally is what pulled a whole 1080p transcode into memory.
     */
    public function testServeMediaCapsAnOpenEndedRange(): void
    {
        $mediaId = $this->createDoneVideo($this->createLocalAlbum(null));
        $hugeObject = 100 * 1024 * 1024;

        $response = $this->serveMediaWithRange($mediaId, 'medium', ['HTTP_RANGE' => 'bytes=0-'], $hugeObject, 'sliced');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 0-8388607/' . $hugeObject, $response->getHeaders()['Content-Range']);
    }

    public function testServeMediaAnswersASuffixRange(): void
    {
        $mediaId = $this->createDoneVideo($this->createLocalAlbum(null));

        $response = $this->serveMediaWithRange($mediaId, 'medium', ['HTTP_RANGE' => 'bytes=-100'], 1000, 'tail');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 900-999/1000', $response->getHeaders()['Content-Range']);
    }

    public function testServeMediaRejectsAnUnsatisfiableRangeWith416(): void
    {
        $mediaId = $this->createDoneVideo($this->createLocalAlbum(null));

        $response = $this->serveMediaWithRange($mediaId, 'medium', ['HTTP_RANGE' => 'bytes=5000-'], 1000, '');

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */1000', $response->getHeaders()['Content-Range']);
    }

    /**
     * RFC 9110 lets a server ignore a Range it does not implement, so an
     * unsupported form must fall through to the ordinary full response rather
     * than fail.
     *
     * @dataProvider unsupportedRangeHeaders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedRangeHeaders')]
    public function testServeMediaFallsBackToAFullResponseForAnUnsupportedRange(string $header): void
    {
        $mediaId = $this->createDoneVideo($this->createLocalAlbum(null));

        $response = $this->serveMediaWithRange($mediaId, 'medium', ['HTTP_RANGE' => $header], 20, 'unused');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(str_repeat('x', 20), $response->getBody());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedRangeHeaders(): array
    {
        return [
            'multi-range' => ['bytes=0-1,4-5'],
            'other unit' => ['items=0-1'],
            'malformed' => ['bytes=abc'],
            'no bounds' => ['bytes=-'],
        ];
    }

    public function testServeMediaAdvertisesRangeSupportOnAFullResponse(): void
    {
        $albumId = $this->createLocalAlbum(null);
        $mediaId = $this->createDoneMedia($albumId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->serveMedia(
            new Request('GET', '/gallery/media/' . $mediaId . '/thumb', [], [], [], []),
            ['media_id' => (string) $mediaId, 'size' => 'thumb']
        );

        $this->assertSame('bytes', $response->getHeaders()['Accept-Ranges']);
    }

    /**
     * Emitted from a string expression, autoescaping turned the quotes into
     * &quot;, leaving the browser with a named target of «"_blank"» and no
     * noopener protection at all — on a link to an arbitrary third-party site.
     */
    public function testExternalAlbumLinkCarriesRealTargetAndNoopenerAttributes(): void
    {
        $this->albumRepository->create(
            Album::TYPE_EXTERNAL, 'Album externe', null, '2026-01-01', null, $this->scoutYearId,
            'https://photos.example.org/album', null, $this->authorId
        );

        $body = $this->controller->index(new Request('GET', '/gallery', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('target="_blank"', $body);
        $this->assertStringContainsString('rel="noopener noreferrer"', $body);
        $this->assertStringNotContainsString('target=&quot;_blank&quot;', $body);
    }

    public function testLocalAlbumLinkCarriesNoTargetAttribute(): void
    {
        $this->createLocalAlbum(null);

        $body = $this->controller->index(new Request('GET', '/gallery', [], [], [], []), [])->getBody();

        // Scoped to the album's own anchor, not the whole page: the site
        // footer carries a GitHub link that legitimately has
        // target="_blank", so a page-wide assertion can never pass no
        // matter how the album link is rendered.
        $this->assertSame(
            1,
            preg_match('#<a href="/gallery/\d+"[^>]*>#', $body, $albumAnchor),
            'the local album link should be rendered as a plain in-app href'
        );
        $this->assertStringNotContainsString('target=', $albumAnchor[0]);
    }

    /**
     * A title made entirely of non-ASCII characters slugified to the empty
     * string, so the browser was handed a bare ".zip".
     */
    public function testDownloadZipFallsBackToAUsableFilenameForANonAsciiTitle(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, '夏', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId
        );
        $this->createDoneMedia($id);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->downloadZip(
            new Request('GET', '/gallery/' . $id . '/download', [], [], [], []),
            ['id' => (string) $id]
        );

        $this->assertSame(
            'attachment; filename="album-' . $id . '.zip"',
            $response->getHeaders()['Content-Disposition']
        );
    }

    public function testDownloadZipSlugifiesAnAccentedTitle(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Camp d\'été 2026 !', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId
        );
        $this->createDoneMedia($id);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $response = $this->controller->downloadZip(
            new Request('GET', '/gallery/' . $id . '/download', [], [], [], []),
            ['id' => (string) $id]
        );

        $this->assertSame('attachment; filename="camp-d-t-2026.zip"', $response->getHeaders()['Content-Disposition']);
    }

    /**
     * Every entry used to be buffered in memory by addFromString() until
     * close(); each rendition is now spooled to a temp file and added by path.
     * The archive's contents must be identical either way — and no spool file
     * may be left behind.
     */
    public function testDownloadZipLeavesNoTemporaryFilesBehind(): void
    {
        $id = $this->createLocalAlbum(null);
        $this->createDoneMedia($id);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->method('get')->willReturn('fake-large-image-bytes');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $before = glob(sys_get_temp_dir() . '/gallery_zip*') ?: [];
        $response = $this->controller->downloadZip(
            new Request('GET', '/gallery/' . $id . '/download', [], [], [], []),
            ['id' => (string) $id]
        );
        $after = glob(sys_get_temp_dir() . '/gallery_zip*') ?: [];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($before, $after);
    }

    public function testIndexMarksAMigratingAlbumUnavailable(): void
    {
        $id = $this->createLocalAlbum(null);
        $this->albumRepository->startMigration($id, $this->locationId);

        $response = $this->controller->index(new Request('GET', '/gallery', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('indisponible', mb_strtolower($response->getBody()));
    }
}
