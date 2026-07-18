<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Controller\ConfigGeneralController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class ConfigGeneralControllerTest extends TestCase
{
    private ConfigGeneralController $controller;
    private ModuleManager $moduleManager;
    private ModuleRegistryRepository $registryRepo;
    private BadgeRepository $badgeRepository;
    private MemberBadgeRepository $memberBadgeRepository;
    private BadgeService $badgeService;
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

        $this->badgeRepository = new BadgeRepository($this->pdo);
        $this->memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->badgeService = new BadgeService($this->badgeRepository, $this->memberBadgeRepository);

        $this->controller = new ConfigGeneralController($twig, $this->moduleManager, $this->badgeService, $journalService);
    }

    public function testIndexRendersWithModuleList(): void
    {
        $request = new Request('GET', '/config/general', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Configuration générale', $response->getBody());
        $this->assertStringContainsString('Mode configuration', $response->getBody());
        $this->assertStringContainsString('Modules', $response->getBody());
        // Should show valid module from fixtures
        $this->assertStringContainsString('Module de test valide', $response->getBody());
    }

    public function testIndexSeedsAndRendersDefaultBadges(): void
    {
        $request = new Request('GET', '/config/general', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Badges', $body);
        $this->assertStringContainsString('Infirmier', $body);
        $this->assertStringContainsString('Trésorier', $body);
    }

    public function testIndexDisablesDeleteButtonForAssignedBadge(): void
    {
        $badge = $this->badgeService->create('Communication');
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'enc', 'enc']);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $this->memberBadgeRepository->assign($memberYearId, $badge->id, null);

        $request = new Request('GET', '/config/general', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertMatchesRegularExpression(
            '/data-id="' . $badge->id . '".*?badge-delete-btn[^>]*disabled/s',
            $body
        );
    }

    public function testAddBadgeCreatesBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $request = $this->createJsonRequest(['name' => 'Communication', '_csrf_token' => $token]);
        $response = $this->controller->addBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Communication', $decoded['badge']['name']);
        $this->assertNotNull($this->badgeRepository->findByName('Communication'));
    }

    public function testAddBadgeRejectsDuplicateName(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->badgeService->create('Communication');

        $request = $this->createJsonRequest(['name' => 'Communication', '_csrf_token' => $token]);
        $response = $this->controller->addBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testAddBadgeWithInvalidCsrfReturns403(): void
    {
        $request = $this->createJsonRequest(['name' => 'Communication', '_csrf_token' => 'bad']);
        $response = $this->controller->addBadge($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateBadgeRenames(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest([
            'badge_id' => $badge->id, 'name' => 'Com Interne', '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Com Interne', $this->badgeRepository->findById($badge->id)->name);
    }

    public function testUpdateBadgeRejectsRenamingDefaultBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $this->badgeService->ensureDefaults();
        $infirmier = array_values(array_filter($this->badgeService->getAll(), fn($b) => $b->name === 'Infirmier'))[0];

        $request = $this->createJsonRequest([
            'badge_id' => $infirmier->id, 'name' => 'Nurse', '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('Infirmier', $this->badgeRepository->findById($infirmier->id)->name);
    }

    public function testToggleBadgeActiveDeactivatesBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest(['badge_id' => $badge->id, 'active' => false, '_csrf_token' => $token]);
        $response = $this->controller->toggleBadgeActive($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->badgeRepository->findById($badge->id)->isActive);
    }

    public function testDeleteBadgeRejectsDefaultBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $this->badgeService->ensureDefaults();
        $infirmier = array_values(array_filter($this->badgeService->getAll(), fn($b) => $b->name === 'Infirmier'))[0];

        $request = $this->createJsonRequest(['badge_id' => $infirmier->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertNotNull($this->badgeRepository->findById($infirmier->id));
    }

    public function testDeleteBadgeSucceedsForUnusedCustomBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest(['badge_id' => $badge->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->badgeRepository->findById($badge->id));
    }

    public function testToggleModuleActivates(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

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
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

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
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'admin'];

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

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/general/module-toggle', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
