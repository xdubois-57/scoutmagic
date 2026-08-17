<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Controller\ConfigModulesController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ConfigModulesControllerTest extends TestCase
{
    private ConfigModulesController $controller;
    private ModuleManager $moduleManager;
    private ModuleRegistryRepository $registryRepo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $fixturesDir = dirname(__DIR__, 3) . '/fixtures/modules';

        $settingRepo = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepo);
        $cookieConsentService = new CookieConsentService([]);
        $menuBuilder = new MenuBuilder(Role::fromString('admin'));
        $this->registryRepo = new ModuleRegistryRepository($this->pdo);
        $router = new Router();

        $migrationRunner = $this->createMock(MigrationRunner::class);
        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);

        $this->moduleManager = new ModuleManager(
            $fixturesDir,
            $settingService,
            $cookieConsentService,
            $menuBuilder,
            $this->registryRepo,
            $migrationRunner,
            $journalService,
            $router
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));

        $this->controller = new ConfigModulesController($twig, $this->moduleManager, $journalService);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testIndexRendersWithModuleList(): void
    {
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Modules', $response->getBody());
        // Should show valid module from fixtures
        $this->assertStringContainsString('Module de test valide', $response->getBody());
    }

    public function testIndexDoesNotRenderBadgesContent(): void
    {
        // The whole point of the split — no leftover badges markup on the
        // modules page.
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('badge-list', $response->getBody());
        $this->assertStringNotContainsString('Mode configuration', $response->getBody());
    }

    public function testToggleModuleActivates(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['enabled']);
    }

    public function testToggleModuleDeactivates(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        // First activate
        $this->registryRepo->upsert('valid_module', true, '1.0.0', 1);

        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => false,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertFalse($entry['enabled']);
    }

    public function testToggleModuleWithInvalidModuleReturnsError(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest([
            'module_id' => 'invalid_module',
            'enabled' => true,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleModule($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testToggleModuleWithInvalidCsrfReturns403(): void
    {
        $request = $this->createJsonRequest([
            'module_id' => 'valid_module',
            'enabled' => true,
            '_csrf_token' => 'invalid',
        ]);
        $response = $this->controller->toggleModule($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testIndexRendersModuleListAsADraggableListEditor(): void
    {
        $request = new Request('GET', '/config/modules', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('id="module-list"', $body);
        $this->assertStringContainsString('data-reorder-url="/config/modules/reorder"', $body);
        $this->assertStringContainsString('list-editor-move-up', $body);
        $this->assertStringContainsString('list-editor-move-down', $body);
        // No active/delete/add affordances for modules — only reorder chrome.
        $this->assertStringNotContainsString('list-editor-delete-btn', $body);
        $this->assertStringNotContainsString('list-editor-add-btn', $body);
    }

    public function testReorderModulesPersistsNewOrder(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $this->registryRepo->upsert('valid_module', true, '1.0.0', 1);

        $request = $this->createJsonRequest([
            'ids' => ['second_module', 'valid_module'],
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->reorderModules($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $this->assertSame(0, $this->registryRepo->findByModuleId('second_module')['sort_order']);
        $this->assertSame(1, $this->registryRepo->findByModuleId('valid_module')['sort_order']);
    }

    public function testReorderModulesWithInvalidCsrfReturns403(): void
    {
        $request = $this->createJsonRequest([
            'ids' => ['valid_module'],
            '_csrf_token' => 'invalid',
        ]);
        $response = $this->controller->reorderModules($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/modules/toggle', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
