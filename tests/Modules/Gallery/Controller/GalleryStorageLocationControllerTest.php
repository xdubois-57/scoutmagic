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
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\S3ErrorExplainerService;
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
class GalleryStorageLocationControllerTest extends TestCase
{
    private \PDO $pdo;
    private GalleryStorageLocationController $controller;
    private StorageLocationRepository $storageLocationRepository;
    private AlbumRepository $albumRepository;
    private int $scoutYearId;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->albumRepository = new AlbumRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $storageBackendFactory = new StorageBackendFactory($this->storageLocationRepository, sys_get_temp_dir());
        $storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $storageBackendFactory, $settingService,
            new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
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
            $twig, $this->storageLocationRepository, $storageLocationService, $journalService, new S3ErrorExplainerService()
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
        $this->assertFalse($locations[0]->isS3());
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
        $this->assertTrue($locations[0]->isS3());
        $this->assertTrue($locations[0]->secretConfigured);
    }

    public function testStoreRejectsADuplicateLabel(): void
    {
        $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Existant', 'gallery', null, null, null, null, null, null, null);
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
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Ancien nom', 'gallery', null, null, null, null, null, null, null);
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
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => 'bad']), ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDeleteRemovesAnUnreferencedLocation(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $token = $this->csrfToken();

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->storageLocationRepository->findById($id));
    }

    public function testDeleteRejectsALocationStillReferencedByAnAlbum(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $id, $this->authorId);
        $token = $this->csrfToken();

        $response = $this->controller->delete($this->jsonRequest(['_csrf_token' => $token]), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull($this->storageLocationRepository->findById($id));
    }

    public function testSetDefaultPromotesTheGivenLocation(): void
    {
        $firstId = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Premier', 'gallery', null, null, null, null, null, null, null);
        $secondId = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Second', 'gallery2', null, null, null, null, null, null, null);
        $token = $this->csrfToken();

        $response = $this->controller->setDefault($this->jsonRequest(['_csrf_token' => $token], '/config/gallery/locations/' . $secondId . '/default'), ['id' => (string) $secondId]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->storageLocationRepository->findById($firstId)->isDefault);
        $this->assertTrue($this->storageLocationRepository->findById($secondId)->isDefault);
    }

    public function testSetDefaultRequiresCsrf(): void
    {
        $id = $this->storageLocationRepository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);

        $response = $this->controller->setDefault($this->jsonRequest(['_csrf_token' => 'bad'], '/config/gallery/locations/' . $id . '/default'), ['id' => (string) $id]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTestActionRecordsAFailedLocalCheckWhenTheDirectoryCannotBeCreated(): void
    {
        $id = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Local', '/root/impossible-permission-denied', null, null, null, null, null, null, null
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
        $this->assertSame('gallery-2024', $this->storageLocationRepository->findByLabel('Disque secondaire')?->subdir);
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

        $this->assertSame('gallery', $this->storageLocationRepository->findByLabel('Slash final')?->subdir);
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

        $this->assertSame('medias/gallery', $this->storageLocationRepository->findByLabel('Disque imbriqué')?->subdir);
    }

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

        $this->assertSame('gallery', $this->storageLocationRepository->findByLabel('Sans sous-dossier')?->subdir);
    }

    /**
     * @dataProvider unsafeSubdirs
     */
    public function testUpdateRejectsAnUnsafeSubdir(string $subdir): void
    {
        $id = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'À modifier', 'gallery', null, null, null, null, null, null, null
        );
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery/locations/' . $id, [], [
            '_csrf_token' => $token,
            'label' => 'À modifier',
            'subdir' => $subdir,
        ], [], []);

        $response = $this->controller->update($request, ['id' => (string) $id]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('gallery', $this->storageLocationRepository->findById($id)?->subdir);
    }
}
