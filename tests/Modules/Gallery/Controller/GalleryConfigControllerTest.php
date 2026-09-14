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
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\OgScraperService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Modules\Gallery\Service\GalleryStorageWiring;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Modules\Gallery\Service\GalleryLocationService;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GalleryConfigControllerTest extends TestCase
{
    private GalleryLocationService $galleryLocationService;

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
            // « Emplacement des nouveaux albums » — 0 means « the site's
            // default », which is what a fresh installation has.
            GalleryLocationService::NEW_ALBUM_LOCATION_SETTING => ['0', 'number'],
        ] as $key => [$default, $type]) {
            $this->settingService->register($key, $default, $type, $key, $key, 'gallery');
        }

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $storageBackendFactory = new StorageBackendFactory($this->storageLocationRepository, sys_get_temp_dir());
        $storageWiring = GalleryStorageWiring::build(
                $this->pdo, $encryption, $this->settingService, sys_get_temp_dir(), $this->albumRepository
            );
        $this->storageLocationService = $storageWiring->locationService;
        $this->galleryLocationService = $storageWiring->galleryLocations;
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
            $this->storageLocationService, $this->galleryLocationService, new ScoutYearService($this->pdo), $this->settingService, $schedulerService,
            $uploadHandler
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
        $this->locationId = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Stockage local', new LocalLocationConfig('gallery'), null
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
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
            $this->storageLocationService, $this->galleryLocationService, $this->storageLocationRepository,
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

    /**
     * "Is there still room?" is the question an administrator asks this page
     * right before an album of camp photos, and the answer used to be
     * nowhere on it.
     */
    public function testIndexBackfillsALocalLocationOnFreshInstall(): void
    {
        $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), []);

        $locations = $this->storageLocationRepository->findAll();
        $this->assertCount(1, $locations);
        $this->assertFalse($locations[0]->type === StorageLocationType::ObjectStorage);
    }

    public function testSaveRequiresCsrf(): void
    {
        $request = new Request('POST', '/config/gallery', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->save($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSavePersistsTheSettingsOfTheTabItWasSubmittedFrom(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->save($this->saveRequest([
            '_csrf_token' => $token,
            'gallery_max_media_per_album' => '150',
        ], 'general'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('150', (string) $this->settingService->get('gallery_max_media_per_album', 'gallery'));
    }

    public function testSavePersistsThePhotoTabsOwnSettings(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->save($this->saveRequest([
            '_csrf_token' => $token,
            'gallery_max_photo_upload_mb' => '20',
            'gallery_photo_max_dimension' => '2500',
        ], 'photos'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('20', (string) $this->settingService->get('gallery_max_photo_upload_mb', 'gallery'));
        $this->assertSame('2500', (string) $this->settingService->get('gallery_photo_max_dimension', 'gallery'));
    }

    /**
     * The reason the tabs own their keys. An unchecked checkbox submits
     * NOTHING, so a page writing every boolean it knows about on every
     * save would switch « autoriser les vidéos » off each time somebody
     * edited the photo limits on another tab — silently, and with no way
     * to tell it from a deliberate change.
     */
    public function testSavingOneTabLeavesAnotherTabsCheckboxAlone(): void
    {
        $this->settingService->set('gallery_allow_video', '1', 'gallery');
        $token = $this->csrfToken();

        $this->controller->save($this->saveRequest($this->minimalSaveBody($token, 'photos'), 'photos'), []);

        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveChecksGalleryAllowVideoWhenPresentInBody(): void
    {
        $this->settingService->set('gallery_allow_video', '0', 'gallery');
        $token = $this->csrfToken();

        $response = $this->controller->save($this->saveRequest(array_merge(
            $this->minimalSaveBody($token, 'videos'),
            ['gallery_allow_video' => '1']
        ), 'videos'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveUnchecksGalleryAllowVideoWhenAbsentFromBody(): void
    {
        $this->settingService->set('gallery_allow_video', '1', 'gallery');
        $token = $this->csrfToken();

        $response = $this->controller->save(
            $this->saveRequest($this->minimalSaveBody($token, 'videos'), 'videos'),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('0', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testIndexListsLocalAlbumsInTheMigrationTable(): void
    {
        $this->createLocalAlbum();

        $response = $this->controller->index($this->indexRequest('albums'), []);

        $this->assertStringContainsString('Camp', $response->getBody());
        $this->assertStringContainsString('Migration d\'album', $response->getBody());
    }

    /**
     * A delegated album must stay somewhere ScoutMagic can serve through an
     * access-controlled path: a location with a permanent public URL would
     * publish a group's photos to anyone holding the link.
     *
     * `AlbumService::startMigration()` refuses it server-side either way —
     * so this is about the OTHER half, the one nothing else defends: the
     * « Migrer vers » list must not offer the destination at all. That
     * guard is one Twig expression, and it was inert for a while without
     * anything noticing: it read a property that had moved, Twig resolved
     * the missing attribute to null with `strict_variables` off, and
     * « not null » is true for every location there is.
     */
    /**
     * An album that pins no location is not an album without one: it sits
     * on the default and has not been told so. Comparing the raw column
     * made the table say « Non défini » about such an album AND offer it
     * the very location it is already on as somewhere to move to — the
     * same defect already fixed in `AlbumService::startMigration()`,
     * surviving on the path that draws the screen.
     */
    public function testAnUnpinnedAlbumShowsTheDefaultAsItsCurrentLocationAndIsNotOfferedIt(): void
    {
        $id = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId
        );
        $this->storageLocationRepository->setDefault($this->locationId);
        $elsewhereId = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Ailleurs', new LocalLocationConfig('gallery2'), null
        );

        $body = $this->controller->index($this->indexRequest('albums'), [])->getBody();
        $row = $this->albumRow($body, 'Camp');

        $this->assertStringContainsString('Stockage local', $row);
        $this->assertStringNotContainsString('Non défini', $row);
        // Its own location is not somewhere to move to; the other one is.
        $this->assertStringNotContainsString('value="' . $this->locationId . '"', $row);
        $this->assertStringContainsString('value="' . $elsewhereId . '"', $row);

        // And nothing was written: a page that merely lists albums must
        // not pin a row per line on a GET.
        $this->assertNull($this->albumRepository->findById($id)?->locationId);
    }

    /** One album's own table row, by the label the table prints for it. */
    private function albumRow(string $html, string $label): string
    {
        $start = strpos($html, htmlspecialchars($label, ENT_QUOTES));
        $this->assertNotFalse($start, "The album « {$label} » is not listed at all.");
        $end = strpos($html, '</tr>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function testAPublicLocationIsNotOfferedAsAMigrationTargetForADelegatedAlbum(): void
    {
        $publicId = $this->storageLocationRepository->create(
            StorageLocationType::ObjectStorage,
            'Bucket public',
            new ObjectStorageLocationConfig(
                'https://s3.example.test',
                'eu',
                'bucket',
                'ak',
                'custom',
                'https://cdn.example.test'
            ),
            'sk'
        );
        $privateId = $this->storageLocationRepository->create(
            StorageLocationType::ObjectStorage,
            'Bucket privé',
            new ObjectStorageLocationConfig('https://s3.example.test', 'eu', 'bucket', 'ak', 'custom'),
            'sk'
        );
        $this->albumRepository->create(
            Album::TYPE_LOCAL,
            'Photos du groupe',
            null,
            '2026-01-01',
            null,
            $this->scoutYearId,
            null,
            $this->locationId,
            $this->authorId,
            'discussion_group',
            7
        );
        // With no describer registered, the page names a delegated album
        // by its owner — which is what the row is found by below.
        $rowLabel = 'discussion_group #7';

        $body = $this->controller->index($this->indexRequest('albums'), [])->getBody();

        $row = $this->delegatedAlbumRow($body, $rowLabel);
        $this->assertStringContainsString('Bucket privé', $row, 'A private location must still be offered.');
        $this->assertStringNotContainsString(
            'value="' . $publicId . '"',
            $row,
            'A location serving permanent public URLs must not be offered as a target for a delegated album.'
        );
        $this->assertStringContainsString('value="' . $privateId . '"', $row);
    }

    /**
     * The delegated album's own table row, isolated so an assertion about
     * what is NOT offered cannot be satisfied by some other row of the
     * page that legitimately names the same location.
     */
    private function delegatedAlbumRow(string $html, string $label): string
    {
        $start = strpos($html, htmlspecialchars($label, ENT_QUOTES));
        $this->assertNotFalse($start, 'The delegated album is not listed at all.');
        $end = strpos($html, '</tr>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function testMigrateAlbumStorageStartsAMigrationToAHealthyOtherLocation(): void
    {
        $id = $this->createLocalAlbum();
        $targetId = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Autre emplacement', new LocalLocationConfig('gallery2'), null
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
            StorageLocationType::Local, 'Autre emplacement', new LocalLocationConfig('gallery2'), null
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
            StorageLocationType::Local, 'Cassé', new LocalLocationConfig('gallery2'), null
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
            StorageLocationType::Local, 'Autre emplacement', new LocalLocationConfig('gallery2'), null
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
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNumericBodies')]
    public function testSaveRejectsAnInvalidNumericSetting(array $overrides): void
    {
        $token = $this->csrfToken();
        $tab = self::tabOwning((string) array_key_first($overrides));
        $body = array_merge($this->minimalSaveBody($token, $tab), $overrides);

        $response = $this->controller->save($this->saveRequest($body, $tab), []);

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

        $response = $this->controller->save($this->saveRequest($body), []);

        $this->assertStringContainsString('doit être comprise entre 1 et 10000', $response->getBody());
    }

    public function testSaveLeavesBooleansUntouchedWhenANumericFieldIsInvalid(): void
    {
        $this->settingService->set('gallery_allow_video', '1', 'gallery');
        $token = $this->csrfToken();
        // gallery_allow_video absent from the Vidéos tab's own body would
        // normally switch it off; an invalid number on that tab must stop
        // before anything is written.
        $body = array_merge($this->minimalSaveBody($token, 'videos'), ['gallery_max_video_upload_mb' => '0']);

        $this->controller->save($this->saveRequest($body, 'videos'), []);

        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveAcceptsValuesAtTheBoundaries(): void
    {
        $token = $this->csrfToken();

        $this->assertSame(302, $this->controller->save($this->saveRequest(
            ['_csrf_token' => $token, 'gallery_max_media_per_album' => '1'],
            'general'
        ), [])->getStatusCode());
        $this->assertSame(302, $this->controller->save($this->saveRequest(array_merge(
            $this->minimalSaveBody($this->csrfToken(), 'videos'),
            ['gallery_max_video_duration_sec' => '86400']
        ), 'videos'), [])->getStatusCode());

        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_max_media_per_album', 'gallery'));
        $this->assertSame('86400', (string) $this->settingService->get('gallery_max_video_duration_sec', 'gallery'));
    }

    /**
     * The edit form deliberately leaves the secret field blank ("laisser vide
     * pour conserver la clé actuelle"), so testing an existing location used
     * to send an empty secret and could only ever fail on authentication.
     */
    /**
     * A bare `catch (\Throwable)` used to render `$e->getMessage()` as
     * submit_error — a PDOException naming a column, a SettingException
     * naming a key.
     */
    public function testSaveShowsAWrittenSentenceRatherThanAThrowablesOwnMessage(): void
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('set')->willThrowException(
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'value' in 'field list'")
        );

        $controller = new GalleryConfigController(
            $this->twig, $settingService, $this->createMock(FfmpegAvailability::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->storageLocationService, $this->galleryLocationService, $this->storageLocationRepository, $this->albumService
        );

        $response = $controller->save(new Request('POST', '/config/gallery', [], [
            '_csrf_token' => $this->csrfToken(),
            'gallery_max_media_per_album' => '200',
            'gallery_max_photo_upload_mb' => '30',
            'gallery_photo_max_dimension' => '3000',
            'gallery_max_video_upload_mb' => '2048',
            'gallery_max_video_duration_sec' => '1800',
        ], [], []), []);

        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Unknown column', $body);
        // Twig autoescaping turns the apostrophe into an entity, so assert
        // on the half of the sentence that has none.
        $this->assertStringContainsString('vérifiez les valeurs saisies', $body);
    }

    /**
     * The body a save needs, per tab: each tab writes ITS keys and no
     * others, so a « minimal » body is only minimal for one of them.
     *
     * That split is not tidiness — an unchecked checkbox submits nothing,
     * so a page writing every boolean it knows about on every save would
     * silently switch off « autoriser les vidéos » each time somebody
     * edited the photo limits.
     *
     * @return array<string, mixed>
     */
    private function minimalSaveBody(string $token, string $tab = 'general'): array
    {
        $bodies = [
            'general' => ['gallery_max_media_per_album' => '200'],
            'photos' => ['gallery_max_photo_upload_mb' => '30', 'gallery_photo_max_dimension' => '3000'],
            'videos' => ['gallery_max_video_upload_mb' => '2048', 'gallery_max_video_duration_sec' => '1800'],
            'albums' => [],
        ];

        return ['_csrf_token' => $token] + $bodies[$tab];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function saveRequest(array $body, string $tab = 'general'): Request
    {
        return new Request('POST', '/config/gallery', ['onglet' => $tab], $body, [], []);
    }

    private function indexRequest(string $tab = 'general'): Request
    {
        return new Request('GET', '/config/gallery', ['onglet' => $tab], [], [], []);
    }

    /** Which tab owns a setting — read from the controller's own declaration. */
    private static function tabOwning(string $key): string
    {
        foreach (GalleryConfigController::TABS as $tab => $definition) {
            if (in_array($key, $definition['numeric'], true) || in_array($key, $definition['boolean'], true)) {
                return $tab;
            }
        }

        return 'general';
    }
}
