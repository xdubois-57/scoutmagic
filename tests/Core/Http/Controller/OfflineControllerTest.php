<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\Http\Controller\OfflineController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Module\StaffDirectoryProvider;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class OfflineControllerTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private MemberPhotoService $memberPhotoService;
    private ScoutYearService $scoutYearService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relative_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            module_id TEXT,
            role_min TEXT NOT NULL DEFAULT "public",
            custom_resolver TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            owner_member_id INTEGER,
            owner_type TEXT,
            owner_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');
        $this->pdo->exec('CREATE TABLE member_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            UNIQUE(member_id, scout_year_id)
        )');
        $this->pdo->exec('CREATE TABLE scout_years (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            is_current INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->fileRepository = new FileRepository($this->pdo);
        $this->memberPhotoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
    }

    /** @param int[] $staffMemberIds */
    private function buildController(array $staffMemberIds): OfflineController
    {
        $provider = new class ($staffMemberIds) implements StaffDirectoryProvider {
            public function __construct(private array $ids) {}
            public function getAllEligibleStaffMemberIds(int $scoutYearId): array { return $this->ids; }
        };

        return new OfflineController(
            new Environment(new ArrayLoader([])),
            $this->memberPhotoService,
            $this->scoutYearService,
            $provider
        );
    }

    private function createPhotoFile(int $memberId, string $roleMin = 'identified'): int
    {
        $fileId = $this->fileRepository->create('photo.jpg', 'photo.jpg', 'image/jpeg', 100, $roleMin, null, null);
        $stmt = $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberId, $this->scoutYearId, $fileId]);

        return $fileId;
    }

    // --- photoManifest() ---

    public function testPhotoManifestListsOnlyEligibleStaffWithAPhoto(): void
    {
        $fileId = $this->createPhotoFile(1);
        // Member 2 is eligible staff but has no photo — must be omitted.
        $controller = $this->buildController([1, 2]);

        $response = $controller->photoManifest(new Request('GET', '/api/offline/photo-manifest', [], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertCount(1, $data['photos']);
        $this->assertSame(1, $data['photos'][0]['member_id']);
        $this->assertSame('/files/' . $fileId . '/thumb', $data['photos'][0]['url']);
    }

    public function testPhotoManifestIsEmptyWhenNoStaffDirectoryProvider(): void
    {
        $controller = new OfflineController(
            new Environment(new ArrayLoader([])), $this->memberPhotoService, $this->scoutYearService, null
        );

        $response = $controller->photoManifest(new Request('GET', '/api/offline/photo-manifest', [], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame([], $data['photos']);
    }

    // --- RBAC ---

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/api/offline/photo-manifest', OfflineController::class, 'photoManifest', 'identified');

        $configFile = sys_get_temp_dir() . '/test_offline_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, new Environment(new ArrayLoader([])), $config);
        $fc->registerController(OfflineController::class, $this->buildController([]));

        return $fc;
    }

    public function testPublicVisitorIsRedirectedToLogin(): void
    {
        AuthSession::logout();

        $response = $this->buildFrontController()->handle(new Request('GET', '/api/offline/photo-manifest', [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testIdentifiedUserIsAllowedThrough(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'member@test.example', 'identified');

        $response = $this->buildFrontController()->handle(new Request('GET', '/api/offline/photo-manifest', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}
