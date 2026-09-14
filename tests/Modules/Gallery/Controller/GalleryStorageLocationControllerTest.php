<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Gallery\Controller\GalleryStorageLocationController;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Modules\Gallery\Service\ObjectStorageErrorExplainerService;
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

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GalleryStorageLocationControllerTest extends TestCase
{
    private \PDO $pdo;
    private GalleryStorageLocationController $controller;
    private StorageLocationRepository $storageLocationRepository;
    private AlbumRepository $albumRepository;
    private int $scoutYearId;
    private int $authorId;
    private EncryptionService $encryption;
    private StorageLocationService $storageLocationService;
    private JournalService $journalService;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->encryption = $encryption;

        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->albumRepository = new AlbumRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $storageBackendFactory = new StorageBackendFactory($this->storageLocationRepository, sys_get_temp_dir());
        $storageWiring = GalleryStorageWiring::build(
                $this->pdo, $encryption, $settingService, sys_get_temp_dir(), $this->albumRepository
            );
        $storageLocationService = $storageWiring->locationService;
        $this->storageLocationService = $storageLocationService;
        $galleryLocationService = $storageWiring->galleryLocations;
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->journalService = $journalService;

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig = $twig;
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new GalleryStorageLocationController(
            $twig,
            $this->storageLocationRepository,
            $storageLocationService,
            $journalService,
            new ObjectStorageErrorExplainerService(),
            $this->albumRepository
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'superadmin');

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
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

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data, string $path = '/config/gallery/locations/1/delete'): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testStoreCreatesALocalLocation(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            'type' => 'local', 'label' => 'Disque du serveur', 'subdir' => 'gallery', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $locations = $this->storageLocationRepository->findAll();
        $this->assertCount(1, $locations);
        $this->assertSame('Disque du serveur', $locations[0]->label);
        $this->assertFalse($locations[0]->type === StorageLocationType::ObjectStorage);
    }

    public function testStoreCreatesAnS3Location(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            'type' => 's3', 'label' => 'Bucket Hetzner', 's3_provider' => 'hetzner',
            's3_endpoint' => 'https://fsn1.your-objectstorage.com', 's3_region' => 'fsn1',
            's3_bucket' => 'scoutmagic', 's3_access_key' => 'AK', 's3_secret_key' => 'secret',
            '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $locations = $this->storageLocationRepository->findAll();
        $this->assertCount(1, $locations);
        $this->assertTrue($locations[0]->type === StorageLocationType::ObjectStorage);
        $this->assertTrue($locations[0]->secretConfigured);
    }

    public function testStoreRejectsADuplicateLabel(): void
    {
        $this->storageLocationRepository->create(StorageLocationType::Local, 'Existant', new LocalLocationConfig('gallery'), null);
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            'type' => 'local', 'label' => 'Existant', 'subdir' => 'other', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->storageLocationRepository->findAll());
    }

    public function testStoreRejectsAnEmptyLabel(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            'type' => 'local', 'label' => '', 'subdir' => 'gallery', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testUpdateChangesTheLabel(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocationType::Local, 'Ancien nom', new LocalLocationConfig('gallery'), null);
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations/' . $id, [], [
            'label' => 'Nouveau nom', 'subdir' => 'gallery', '_csrf_token' => $token,
        ], [], []);

        $response = $this->controller->update($request, ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Nouveau nom', $this->storageLocationRepository->findById($id)->label);
    }

    public function testDeleteRequiresCsrf(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocationType::Local, 'Local', new LocalLocationConfig('gallery'), null);

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => 'bad']), ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDeleteRemovesAnUnreferencedLocation(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocationType::Local, 'Local', new LocalLocationConfig('gallery'), null);
        $token = $this->csrfToken();

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->storageLocationRepository->findById($id));
    }

    public function testDeleteRejectsALocationStillReferencedByAnAlbum(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocationType::Local, 'Local', new LocalLocationConfig('gallery'), null);
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $id, $this->authorId);
        $token = $this->csrfToken();

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull($this->storageLocationRepository->findById($id));
    }

    public function testUpdateRefusesToMakeALocationPublicWhileADelegatedAlbumLivesOnIt(): void
    {
        // The restriction is not new — it is refused at creation and
        // re-asserted when the bytes are served. What was missing is this
        // third moment: create the album on a private location, then edit
        // the location to carry a public URL, and the serve-time guard
        // does its job — every media of that album 404s, for ever, with
        // nothing saying why.
        $id = $this->storageLocationRepository->create(
            StorageLocationType::ObjectStorage,
            'Bucket privé',
            new ObjectStorageLocationConfig('https://fsn1.your-objectstorage.com', 'fsn1', 'scoutmagic', 'AK', null),
            'secret'
        );
        $this->albumRepository->create(
            Album::TYPE_LOCAL,
            'Album délégué',
            null,
            '2026-01-01',
            null,
            $this->scoutYearId,
            null,
            $id,
            $this->authorId,
            'groups',
            42
        );

        $response = $this->controller->update($this->publicUrlRequest($id), ['id' => (string) $id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('albums délégués', $response->getBody());

        $saved = $this->storageLocationRepository->findById($id);
        $this->assertNotNull($saved);
        $this->assertFalse($saved->servesPubliclyWithoutExpiry());
    }

    public function testUpdateAllowsAPublicUrlWhenNoDelegatedAlbumStandsOnTheLocation(): void
    {
        // An ordinary album is not stranded by a public URL — only a
        // delegated one, whose whole access model is the short-lived
        // grant a permanent public link defeats.
        $id = $this->storageLocationRepository->create(
            StorageLocationType::ObjectStorage,
            'Bucket public',
            new ObjectStorageLocationConfig('https://fsn1.your-objectstorage.com', 'fsn1', 'scoutmagic', 'AK', null),
            'secret'
        );
        $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Camp ordinaire', null, '2026-01-01', null,
            $this->scoutYearId, null, $id, $this->authorId
        );

        $response = $this->controller->update($this->publicUrlRequest($id), ['id' => (string) $id]);

        $this->assertNotSame(422, $response->getStatusCode());
        $this->assertTrue($this->storageLocationRepository->findById($id)?->servesPubliclyWithoutExpiry());
    }

    /** The edit form, submitted with a permanent public URL added. */
    private function publicUrlRequest(int $id): Request
    {
        return new Request('POST', '/config/gallery/locations/' . $id, [], [
            'label' => 'Bucket',
            's3_provider' => 'hetzner',
            's3_endpoint' => 'https://fsn1.your-objectstorage.com',
            's3_region' => 'fsn1',
            's3_bucket' => 'scoutmagic',
            's3_access_key' => 'AK',
            's3_public_url' => 'https://cdn.example.org',
            '_csrf_token' => $this->csrfToken(),
        ], [], []);
    }

    public function testSetDefaultRefusesAPublicLocationWhileADelegatedAlbumWouldLandOnIt(): void
    {
        // The same stranding as the edit form, through the other door. A
        // delegated album whose location_id is still null is not on « no »
        // location — it is on the default, and resolving pins it there.
        $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Disque privé', new LocalLocationConfig('gallery'), null
        );
        $publicId = $this->storageLocationRepository->create(
            StorageLocationType::ObjectStorage,
            'Bucket public',
            new ObjectStorageLocationConfig(
                'https://fsn1.your-objectstorage.com', 'fsn1', 'scoutmagic', 'AK', null, 'https://cdn.example.org'
            ),
            'secret'
        );
        $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Album délégué', null, '2026-01-01', null,
            $this->scoutYearId, null, null, $this->authorId, 'groups', 42
        );

        $response = $this->controller->setDefault(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $publicId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($this->storageLocationRepository->findById($publicId)?->isDefault);
    }

    public function testSetDefaultAllowsAPublicLocationWhenNoDelegatedAlbumIsConcerned(): void
    {
        $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Disque privé', new LocalLocationConfig('gallery'), null
        );
        $publicId = $this->storageLocationRepository->create(
            StorageLocationType::ObjectStorage,
            'Bucket public',
            new ObjectStorageLocationConfig(
                'https://fsn1.your-objectstorage.com', 'fsn1', 'scoutmagic', 'AK', null, 'https://cdn.example.org'
            ),
            'secret'
        );
        $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Camp ordinaire', null, '2026-01-01', null,
            $this->scoutYearId, null, null, $this->authorId
        );

        $response = $this->controller->setDefault(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $publicId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->storageLocationRepository->findById($publicId)?->isDefault);
    }

    public function testSetDefaultAnswersInJsonWhenTheLocationVanishedMidRequest(): void
    {
        // The row can disappear between this action's findById() and the
        // promotion — deleted from another session, or this page reopened
        // after a deletion. The repository refuses that rather than
        // demoting everything and promoting nobody; the endpoint answers
        // in JSON, so the refusal has to as well, or a fetch() expecting
        // an object gets an HTML error page.
        $id = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'À promouvoir', new LocalLocationConfig('gallery'), null
        );
        $controller = $this->controllerWhoseLocationVanishes($id);

        $response = $controller->setDefault(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
        $this->assertNotSame('', (string) $decoded['error']);
    }

    /**
     * The controller as it stands, but with the row deleted after its
     * `findById()` has already answered — the race, made deterministic.
     */
    private function controllerWhoseLocationVanishes(int $id): GalleryStorageLocationController
    {
        $repository = new class ($this->pdo, $this->encryption, $id) extends StorageLocationRepository {
            public function __construct(\PDO $pdo, EncryptionService $encryption, private int $vanishing)
            {
                parent::__construct($pdo, $encryption);
            }

            public function findById(int $id): ?StorageLocation
            {
                $found = parent::findById($id);
                if ($id === $this->vanishing && $found !== null) {
                    parent::delete($id);
                }

                return $found;
            }
        };

        return new GalleryStorageLocationController(
            $this->twig,
            $repository,
            $this->storageLocationService,
            $this->journalService,
            new ObjectStorageErrorExplainerService(),
            $this->albumRepository
        );
    }

    public function testSetDefaultPromotesTheGivenLocation(): void
    {
        $firstId = $this->storageLocationRepository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('gallery'), null);
        $secondId = $this->storageLocationRepository->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('gallery2'), null);
        $token = $this->csrfToken();

        $response = $this->controller->setDefault($this->jsonRequest(['_csrf_token' => $token], '/config/gallery/locations/' . $secondId . '/default'), ['id' => (string) $secondId]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->storageLocationRepository->findById($firstId)->isDefault);
        $this->assertTrue($this->storageLocationRepository->findById($secondId)->isDefault);
    }

    public function testSetDefaultRequiresCsrf(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocationType::Local, 'Local', new LocalLocationConfig('gallery'), null);

        $response = $this->controller->setDefault($this->jsonRequest(['_csrf_token' => 'bad'], '/config/gallery/locations/' . $id . '/default'), ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTestActionRecordsAFailedLocalCheckWhenTheDirectoryCannotBeCreated(): void
    {
        $id = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Local', new LocalLocationConfig('/root/impossible-permission-denied'), null
        );
        $token = $this->csrfToken();

        $response = $this->controller->test($this->jsonRequest(['_csrf_token' => $token], '/config/gallery/locations/' . $id . '/test'), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        // Either outcome is environment-dependent (root can write anywhere) —
        // the meaningful assertion is that a result was actually persisted.
        $this->assertNotNull($this->storageLocationRepository->findById($id)->lastCheckOk);
    }

    /**
     * The sub-directory is concatenated straight onto the storage root by
     * Service\Storage\LocalStorageBackend, so a value like "../../public"
     * would put every rendition inside the webroot.
     *
     * @dataProvider unsafeSubdirs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeSubdirs')]
    public function testStoreRejectsAnUnsafeSubdir(string $subdir): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            '_csrf_token' => $token,
            'type' => 'local',
            'label' => 'Traversée',
            'subdir' => $subdir,
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($this->storageLocationRepository->findByLabel('Traversée'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeSubdirs(): array
    {
        return [
            'parent hop' => ['../public'],
            'deep parent hop' => ['gallery/../../public'],
            'absolute path' => ['/etc'],
            'leading slash' => ['/gallery/'],
            'windows drive' => ['C:\\gallery'],
            'backslash hop' => ['..\\public'],
            'bare parent' => ['..'],
            'null byte' => ["gallery\0/x"],
            'space in a segment' => ['mon dossier'],
            'wildcard' => ['gal*ery'],
            'over 255 characters' => [str_repeat('a', 256)],
        ];
    }

    public function testStoreAcceptsAPlainSubdir(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            '_csrf_token' => $token,
            'type' => 'local',
            'label' => 'Disque secondaire',
            'subdir' => 'gallery-2024',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('gallery-2024', $this->localPathOf($this->storageLocationRepository->findByLabel('Disque secondaire')));
    }

    public function testStoreNormalizesATrailingSlash(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            '_csrf_token' => $token,
            'type' => 'local',
            'label' => 'Slash final',
            'subdir' => 'gallery/',
        ], [], []);

        $this->controller->store($request, []);

        $this->assertSame('gallery', $this->localPathOf($this->storageLocationRepository->findByLabel('Slash final')));
    }

    public function testStoreAcceptsANestedSubdir(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            '_csrf_token' => $token,
            'type' => 'local',
            'label' => 'Disque imbriqué',
            'subdir' => 'medias/gallery',
        ], [], []);

        $this->controller->store($request, []);

        $this->assertSame('medias/gallery', $this->localPathOf($this->storageLocationRepository->findByLabel('Disque imbriqué')));
    }

    /**
     * And the fallback is the folder the site ALREADY made, not a second
     * spelling of « the default ». They were two — 'gallery' here,
     * 'modules/gallery' in `ensureDefaultExists()` — so accepting this
     * form blank created a location pointing at a different directory
     * from the automatic one, both presented as the default local
     * storage.
     */
    public function testStoreFallsBackToTheDefaultSubdirWhenBlank(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations', [], [
            '_csrf_token' => $token,
            'type' => 'local',
            'label' => 'Sans sous-dossier',
            'subdir' => '   ',
        ], [], []);

        $this->controller->store($request, []);

        $this->assertSame(
            StorageLocationService::DEFAULT_PATH,
            $this->localPathOf($this->storageLocationRepository->findByLabel('Sans sous-dossier'))
        );
    }

    /**
     * @dataProvider unsafeSubdirs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeSubdirs')]
    public function testUpdateRejectsAnUnsafeSubdir(string $subdir): void
    {
        $id = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'À modifier', new LocalLocationConfig('gallery'), null
        );
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations/' . $id, [], [
            '_csrf_token' => $token,
            'label' => 'À modifier',
            'subdir' => $subdir,
        ], [], []);

        $response = $this->controller->update($request, ['id' => (string) $id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('gallery', $this->localPathOf($this->storageLocationRepository->findById($id)));
    }

    /**
     * The folder a local location points at, or null when the location is
     * missing or is not a local one — so a test that means « the path was
     * not changed » cannot pass by accident on a location that turned into
     * something else entirely.
     */
    private function localPathOf(?StorageLocation $location): ?string
    {
        return $location?->config instanceof LocalLocationConfig ? $location->config->path : null;
    }

    /**
     * The « nouvel emplacement » form and the location the site creates by
     * itself must propose the same folder. Two spellings meant an
     * administrator accepting the form as-is got `storage/gallery` while
     * the automatic default was `storage/modules/gallery` — two
     * directories, both called the default local storage, and the photos
     * in whichever one the album happened to resolve to.
     */
    public function testTheCreationFormProposesTheSameFolderTheSiteWouldCreateByItself(): void
    {
        $body = $this->controller->create(
            new Request('GET', '/config/gallery/locations/new', [], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString(
            'value="' . StorageLocationService::DEFAULT_PATH . '"',
            $body
        );
    }
}
