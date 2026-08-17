<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Http\Controller\OfflineController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Offline\OfflineManifestService;
use Core\Offline\OfflineWhitelist;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OfflineControllerTest extends TestCase
{
    private \PDO $pdo;
    private OfflineManifestService $offlineManifestService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $connection = Connection::withPdo($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $offlineWhitelist = new OfflineWhitelist();
        $this->offlineManifestService = new OfflineManifestService(
            $offlineWhitelist,
            new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection),
            new MemberPhotoService(new MemberPhotoRepository($this->pdo)),
            new SectionPhotoService(new SectionPhotoRepository($this->pdo)),
            new SectionService($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo)),
            new UnitStaffSectionService($this->pdo),
            new ScoutYearService($this->pdo),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new AgeBranchRepository($this->pdo),
            null
        );
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testManifestReturnsPublicPagesForAGuest(): void
    {
        AuthSession::logout();
        $controller = new OfflineController(new Environment(new ArrayLoader([])), $this->offlineManifestService);

        $response = $controller->manifest(new Request('GET', '/api/offline/manifest', [], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('/', $data['pages']);
        $this->assertContains('/contact', $data['pages']);
        $this->assertArrayHasKey('images', $data);
    }

    // --- RBAC boundary ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/api/offline/manifest', OfflineController::class, 'manifest', 'public');

        $configFile = sys_get_temp_dir() . '/test_offline_manifest_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, new Environment(new ArrayLoader([])), $config);
        $fc->registerController(
            OfflineController::class,
            new OfflineController(new Environment(new ArrayLoader([])), $this->offlineManifestService)
        );

        return $fc;
    }

    public function testPublicRoleIsAllowedThroughWithoutASession(): void
    {
        AuthSession::logout();

        $response = $this->buildFrontController()->handle(new Request('GET', '/api/offline/manifest', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIdentifiedUserIsAllowedThrough(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'member@test.example', 'identified');

        $response = $this->buildFrontController()->handle(new Request('GET', '/api/offline/manifest', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}
