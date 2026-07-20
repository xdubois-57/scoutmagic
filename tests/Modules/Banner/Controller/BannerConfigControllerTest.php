<?php

declare(strict_types=1);

namespace Tests\Modules\Banner\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Banner\Controller\BannerConfigController;
use Modules\Banner\Repository\BannerRepository;
use Modules\Banner\Service\BannerService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Banner\BannerTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class BannerConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private BannerConfigController $controller;
    private BannerService $bannerService;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        BannerTestHelper::createTables($this->pdo);
        $this->pdo->exec("CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            modified_at TEXT,
            modified_by INTEGER
        )");
        $this->pdo->exec("CREATE TABLE event_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            logged_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_account_id INTEGER,
            ip_address TEXT,
            category TEXT NOT NULL,
            event_type TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT 'info',
            description TEXT NOT NULL,
            context TEXT
        )");

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->bannerService = new BannerService(new BannerRepository($this->pdo), $editableContentService);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/banner/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'banner');
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

        $this->controller = new BannerConfigController($this->twig, $this->bannerService, $journalService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/banner/x', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testIndexRendersEmptyState(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/banner', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Bannière', $response->getBody());
        $this->assertStringContainsString('Aucune bannière configurée', $response->getBody());
    }

    public function testIndexRendersExistingBanners(): void
    {
        $banner = $this->bannerService->create();

        $response = $this->controller->index(new Request('GET', '/config/banner', [], [], [], []), []);

        $this->assertStringContainsString('banner_content_' . $banner->id, $response->getBody());
    }

    public function testIndexRendersEachBannerWithItsOwnDistinctContent(): void
    {
        // Proves the generic list_editor's item_content embed block is
        // correctly re-rendered per loop iteration (with `item` in scope
        // each time) rather than reusing the first item's content.
        $first = $this->bannerService->create();
        $second = $this->bannerService->create();
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $editableContentService->set('banner_content_' . $first->id, '<p>First banner text</p>', 'rich_text', 1);
        $editableContentService->set('banner_content_' . $second->id, '<p>Second banner text</p>', 'rich_text', 1);

        $response = $this->controller->index(new Request('GET', '/config/banner', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('First banner text', $body);
        $this->assertStringContainsString('Second banner text', $body);
        $this->assertStringContainsString('banner_content_' . $first->id, $body);
        $this->assertStringContainsString('banner_content_' . $second->id, $body);
    }

    public function testAddCreatesBanner(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->add($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertCount(1, $this->bannerService->getAllForConfig());
    }

    public function testAddValidatesCsrf(): void
    {
        $response = $this->controller->add($this->jsonRequest(['_csrf_token' => 'bad']), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateActiveTogglesFlag(): void
    {
        $banner = $this->bannerService->create();
        $token = $this->csrfToken();

        $response = $this->controller->updateActive(
            $this->jsonRequest(['id' => $banner->id, 'active' => false, '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->bannerService->getAllForConfig()[0]['is_active']);
    }

    public function testUpdateActiveRejectsUnknownBanner(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateActive(
            $this->jsonRequest(['id' => 999, 'active' => false, '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testReorderPersistsNewOrder(): void
    {
        $first = $this->bannerService->create();
        $second = $this->bannerService->create();
        $token = $this->csrfToken();

        $response = $this->controller->reorder(
            $this->jsonRequest(['ids' => [$second->id, $first->id], '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame($second->id, $this->bannerService->getAllForConfig()[0]['id']);
    }

    public function testDeleteRemovesBanner(): void
    {
        $banner = $this->bannerService->create();
        $token = $this->csrfToken();

        $response = $this->controller->delete(
            $this->jsonRequest(['id' => $banner->id, '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame([], $this->bannerService->getAllForConfig());
    }

    public function testDeleteRejectsUnknownBanner(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->delete(
            $this->jsonRequest(['id' => 999, '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    /**
     * RBAC boundary for /config/banner (Configuration menu, role_min
     * superadmin): superadmin -> 200, admin ("Chef d'Unité", the
     * espace_admin ceiling) -> 403.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/banner', BannerConfigController::class, 'index', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_banner_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(BannerConfigController::class, $this->controller);

        return $fc;
    }

    public function testSuperadminGetsPage(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/banner', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminIsDenied(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/banner', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
