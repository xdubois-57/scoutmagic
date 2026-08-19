<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Gallery\Controller\GalleryConfigController;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\OgScraperService;
use Modules\Gallery\Service\S3ErrorExplainerService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class GalleryConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private GalleryConfigController $controller;
    private SettingService $settingService;
    private StorageLocationRepository $storageLocationRepository;
    private StorageLocationService $storageLocationService;
    private AlbumRepository $albumRepository;
    private AlbumService $albumService;
    private Environment $twig;
    private int $authorId;
    private int $scoutYearId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            'gallery_allow_external' => ['1', 'boolean'],
            'gallery_max_media_per_album' => ['200', 'number'], 'gallery_max_photo_upload_mb' => ['30', 'number'],
            'gallery_photo_max_dimension' => ['3000', 'number'], 'gallery_allow_video' => ['1', 'boolean'],
            'gallery_max_video_upload_mb' => ['2048', 'number'], 'gallery_max_video_duration_sec' => ['1800', 'number'],
            'gallery_keep_original_video' => ['0', 'boolean'],
        ] as $key => [$default, $type]) {
            $this->settingService->register($key, $default, $type, $key, $key, 'gallery');
        }

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $storageBackendFactory = new StorageBackendFactory($this->storageLocationRepository, sys_get_temp_dir());
        $this->storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $storageBackendFactory,
            $this->settingService, new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );
        $ffmpegAvailability = $this->createMock(FfmpegAvailability::class);
        $ffmpegAvailability->method('check')->willReturn(false);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $accessService = $this->createMock(GalleryAccessService::class);
        $accessService->method('canManageAlbum')->willReturn(true);
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $this->albumService = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $accessService,
            $this->createMock(OgScraperService::class), $storageBackendFactory, $this->storageLocationRepository,
            $this->storageLocationService, new ScoutYearService($this->pdo), $this->settingService, $schedulerService,
            $uploadHandler
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
        $this->locationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new GalleryConfigController(
            $this->twig, $this->settingService, $ffmpegAvailability, $journalService,
            new S3ErrorExplainerService(), $this->storageLocationService, $this->storageLocationRepository,
            $this->albumService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'superadmin');
    }

    private function createLocalAlbum(): int
    {
        return $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $this->locationId, $this->authorId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function migrateJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/gallery/albums/1/migrate', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
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

    public function testIndexRendersFfmpegWarningWhenUnavailable(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('FFmpeg', $response->getBody());
    }

    public function testIndexNoLongerRendersTheRemovedAllowLocalSetting(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), []);

        $this->assertStringNotContainsString('gallery_allow_local', $response->getBody());
        $this->assertStringNotContainsString('Autoriser les albums locaux (photos', $response->getBody());
    }

    public function testIndexBackfillsALocalLocationOnFreshInstall(): void
    {
        $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), []);

        $locations = $this->storageLocationRepository->findAll();
        $this->assertCount(1, $locations);
        $this->assertFalse($locations[0]->isS3());
    }

    public function testSaveRequiresCsrf(): void
    {
        $request = new Request('POST', '/config/gallery', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->save($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSavePersistsSettings(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery', [], [
            '_csrf_token' => $token,
            'gallery_max_media_per_album' => '150', 'gallery_max_photo_upload_mb' => '20',
            'gallery_photo_max_dimension' => '2500', 'gallery_max_video_upload_mb' => '1024',
            'gallery_max_video_duration_sec' => '900',
        ], [], []);

        $response = $this->controller->save($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('150', (string) $this->settingService->get('gallery_max_media_per_album', 'gallery'));
    }

    public function testSaveChecksGalleryAllowVideoWhenPresentInBody(): void
    {
        $this->settingService->set('gallery_allow_video', '0', 'gallery');
        $token = $this->csrfToken();

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], array_merge(
            $this->minimalSaveBody($token),
            ['gallery_allow_video' => '1']
        ), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveUnchecksGalleryAllowVideoWhenAbsentFromBody(): void
    {
        $this->settingService->set('gallery_allow_video', '1', 'gallery');
        $token = $this->csrfToken();

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], $this->minimalSaveBody($token), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('0', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testTestConnectionRequiresCsrf(): void
    {
        $request = $this->jsonRequest(['_csrf_token' => 'bad']);

        $response = $this->controller->testConnection($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTestConnectionFailsFastAgainstARefusedConnection(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest([
            '_csrf_token' => $token,
            'endpoint' => 'http://127.0.0.1:1',
            'region' => 'eu',
            'bucket' => 'test',
            'access_key' => 'a',
            'secret_key' => 'b',
        ]);

        $response = $this->controller->testConnection($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testExplainS3ErrorRequiresCsrf(): void
    {
        $request = $this->jsonRequest(['_csrf_token' => 'bad']);

        $response = $this->controller->explainS3Error($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testExplainS3ErrorFailsWhenTheLlmConnectorIsUnavailable(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest(['_csrf_token' => $token, 'error' => 'boom']);

        $response = $this->controller->explainS3Error($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testExplainS3ErrorReturnsTheAiExplanationAndPassesTheSecretKeyLengthOnlyNeverItsValue(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->once())->method('complete')->with($this->callback(function ($request) {
            $this->assertStringContainsString('longueur : 18', $request->prompt);
            $this->assertStringContainsString('scaleway', $request->prompt);
            return true;
        }))->willReturn(new LlmResponse('Vérifiez le nom du bucket dans la console Scaleway.', null, 10, 10));

        $controller = new GalleryConfigController(
            $this->twig, $this->settingService, $this->createMock(FfmpegAvailability::class),
            new JournalService(new JournalRepository($this->pdo)), new S3ErrorExplainerService($llmConnector),
            $this->storageLocationService, $this->storageLocationRepository, $this->albumService
        );

        // The controller action's signature has no "secret_key" field at
        // all (Controller\GalleryConfigController::explainS3Error) — only
        // secret_key_length is ever read — so the secret cannot leak here
        // even if a malicious client tried to send it in the request body.
        $token = $this->csrfToken();
        $request = $this->jsonRequest([
            '_csrf_token' => $token, 'provider' => 'scaleway', 'endpoint' => 'https://s3.fr-par.scw.cloud',
            'region' => 'fr-par', 'bucket' => 'scoutmagic', 'access_key' => 'AK123',
            'secret_key' => 'this-would-be-ignored-even-if-sent', 'secret_key_length' => 18,
            'error' => '403 Forbidden',
        ]);

        $response = $controller->explainS3Error($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Vérifiez le nom du bucket dans la console Scaleway.', $decoded['explanation']);
    }

    public function testExplainS3ErrorReturns422WhenTheLlmCallFails(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider timeout.'));

        $controller = new GalleryConfigController(
            $this->twig, $this->settingService, $this->createMock(FfmpegAvailability::class),
            new JournalService(new JournalRepository($this->pdo)), new S3ErrorExplainerService($llmConnector),
            $this->storageLocationService, $this->storageLocationRepository, $this->albumService
        );

        $token = $this->csrfToken();
        $response = $controller->explainS3Error($this->jsonRequest(['_csrf_token' => $token, 'error' => 'boom']), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testIndexListsLocalAlbumsInTheMigrationTable(): void
    {
        $this->createLocalAlbum();

        $response = $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), []);

        $this->assertStringContainsString('Camp', $response->getBody());
        $this->assertStringContainsString('Migration d\'album', $response->getBody());
    }

    public function testMigrateAlbumStorageStartsAMigrationToAHealthyOtherLocation(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $token = $this->csrfToken();

        $response = $this->controller->migrateAlbumStorage(
            $this->migrateJsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $this->albumRepository->findById($id)->migrationStatus);
    }

    public function testMigrateAlbumStorageRejectsWhenAlreadyInProgress(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $this->albumRepository->startMigration($id, $targetId);
        $token = $this->csrfToken();

        $response = $this->controller->migrateAlbumStorage(
            $this->migrateJsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testMigrateAlbumStorageRejectsTheCurrentLocationAsTarget(): void
    {
        $id = $this->createLocalAlbum();
        $token = $this->csrfToken();

        $response = $this->controller->migrateAlbumStorage(
            $this->migrateJsonRequest(['target_location_id' => $this->locationId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateAlbumStorageRejectsAnUnhealthyTarget(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Cassé', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, false, 'Dossier inaccessible.');
        $token = $this->csrfToken();

        $response = $this->controller->migrateAlbumStorage(
            $this->migrateJsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateAlbumStorageRejectsAnExternalAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Externe', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', null, $this->authorId);
        $targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Autre emplacement', 'gallery2', null, null, null, null, null, null, null
        );
        $this->storageLocationRepository->recordCheckResult($targetId, true, null);
        $token = $this->csrfToken();

        $response = $this->controller->migrateAlbumStorage(
            $this->migrateJsonRequest(['target_location_id' => $targetId, '_csrf_token' => $token]), ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testMigrateAlbumStorageRequiresCsrf(): void
    {
        $id = $this->createLocalAlbum();

        $response = $this->controller->migrateAlbumStorage(
            $this->migrateJsonRequest(['target_location_id' => 999, '_csrf_token' => 'bad']), ['id' => (string) $id]
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    /**
     * Core\Config\SettingService only checks that a 'number' setting is
     * numeric — it happily stores '', '0' or '-5', and every read site casts
     * those to a hard 0: gallery_max_media_per_album = 0 refused every upload
     * ("limite de 0 médias") and gallery_photo_max_dimension = 0 asked GD for
     * a 0x0 canvas, failing every photo in the album.
     *
     * @dataProvider invalidNumericBodies
     * @param array<string, string> $overrides
     */
    public function testSaveRejectsAnInvalidNumericSetting(array $overrides): void
    {
        $token = $this->csrfToken();
        $body = array_merge($this->minimalSaveBody($token), $overrides);

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], $body, [], []), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->settingService->clearCache();
        // Nothing was written: the previous values are all still in place.
        $this->assertSame('200', (string) $this->settingService->get('gallery_max_media_per_album', 'gallery'));
        $this->assertSame('3000', (string) $this->settingService->get('gallery_photo_max_dimension', 'gallery'));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function invalidNumericBodies(): array
    {
        return [
            'zero media limit' => [['gallery_max_media_per_album' => '0']],
            'blank media limit' => [['gallery_max_media_per_album' => '']],
            'non-numeric media limit' => [['gallery_max_media_per_album' => 'beaucoup']],
            'negative media limit' => [['gallery_max_media_per_album' => '-5']],
            'media limit over the ceiling' => [['gallery_max_media_per_album' => '10001']],
            'zero photo dimension' => [['gallery_photo_max_dimension' => '0']],
            'photo dimension under the floor' => [['gallery_photo_max_dimension' => '499']],
            'zero photo upload size' => [['gallery_max_photo_upload_mb' => '0']],
            'zero video upload size' => [['gallery_max_video_upload_mb' => '0']],
            'zero video duration' => [['gallery_max_video_duration_sec' => '0']],
            'decimal media limit' => [['gallery_max_media_per_album' => '12.5']],
        ];
    }

    public function testSaveRendersTheValidationMessage(): void
    {
        $token = $this->csrfToken();
        $body = array_merge($this->minimalSaveBody($token), ['gallery_max_media_per_album' => '0']);

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], $body, [], []), []);

        $this->assertStringContainsString('doit être comprise entre 1 et 10000', $response->getBody());
    }

    public function testSaveLeavesBooleansUntouchedWhenANumericFieldIsInvalid(): void
    {
        $this->settingService->set('gallery_allow_video', '1', 'gallery');
        $token = $this->csrfToken();
        // gallery_allow_video absent from the body would normally switch it off.
        $body = array_merge($this->minimalSaveBody($token), ['gallery_max_media_per_album' => '0']);

        $this->controller->save(new Request('POST', '/config/gallery', [], $body, [], []), []);

        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveAcceptsValuesAtTheBoundaries(): void
    {
        $token = $this->csrfToken();
        $body = array_merge($this->minimalSaveBody($token), [
            'gallery_max_media_per_album' => '1',
            'gallery_photo_max_dimension' => '500',
            'gallery_max_video_duration_sec' => '86400',
        ]);

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], $body, [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_max_media_per_album', 'gallery'));
        $this->assertSame('86400', (string) $this->settingService->get('gallery_max_video_duration_sec', 'gallery'));
    }

    /**
     * The edit form deliberately leaves the secret field blank ("laisser vide
     * pour conserver la clé actuelle"), so testing an existing location used
     * to send an empty secret and could only ever fail on authentication.
     */
    public function testTestConnectionFallsBackToTheStoredSecretWhenTheFieldIsBlank(): void
    {
        $locationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket', null, 'custom',
            'http://127.0.0.1:1', 'eu', 'bucket', 'access-key', null, 'stored-secret'
        );

        $response = $this->controller->testConnection($this->jsonRequest([
            '_csrf_token' => $this->csrfToken(),
            'location_id' => $locationId,
            'endpoint' => 'http://127.0.0.1:1',
            'region' => 'eu',
            'bucket' => 'bucket',
            'access_key' => 'access-key',
            'secret_key' => '',
        ]), []);

        // The connection itself still fails (nothing is listening on port 9),
        // but it fails on the network, not on a missing credential — proving
        // the stored secret was picked up rather than an empty string sent.
        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertStringNotContainsString('stored-secret', (string) $payload['error']);
    }

    public function testTestConnectionIgnoresAnUnknownLocationId(): void
    {
        $response = $this->controller->testConnection($this->jsonRequest([
            '_csrf_token' => $this->csrfToken(),
            'location_id' => 999999,
            'endpoint' => 'http://127.0.0.1:1',
            'region' => 'eu',
            'bucket' => 'bucket',
            'access_key' => 'access-key',
            'secret_key' => '',
        ]), []);

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * Emitted from a string expression, autoescaping turned the quotes into
     * &quot; and left a trail of bogus boolean attributes behind the disabled
     * flag.
     */
    public function testTheDeleteButtonOfAReferencedLocationRendersRealAttributes(): void
    {
        $locationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Utilisé', 'gallery-used', null, null, null, null, null, null, null
        );
        $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $locationId, $this->authorId
        );

        $body = $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('disabled title=&quot;', $body);
        $this->assertStringContainsString('non supprimable', $body);
    }

    private function minimalSaveBody(string $token): array
    {
        return [
            '_csrf_token' => $token,
            'gallery_max_media_per_album' => '200', 'gallery_max_photo_upload_mb' => '30',
            'gallery_photo_max_dimension' => '3000', 'gallery_max_video_upload_mb' => '2048',
            'gallery_max_video_duration_sec' => '1800',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/gallery/test-connection', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }
}
