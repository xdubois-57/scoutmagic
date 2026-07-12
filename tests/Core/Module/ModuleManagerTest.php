<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleException;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ModuleManagerTest extends TestCase
{
    private ModuleManager $manager;
    private ModuleRegistryRepository $registryRepo;
    private SettingService $settingService;
    private CookieConsentService $cookieConsentService;
    private MenuBuilder $menuBuilder;
    private Router $router;
    private string $fixturesDir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->fixturesDir = dirname(__DIR__, 2) . '/fixtures/modules';

        $settingRepo = new SettingRepository($this->pdo);
        $this->settingService = new SettingService($settingRepo);
        $this->cookieConsentService = new CookieConsentService([]);
        $this->menuBuilder = new MenuBuilder(Role::fromString('admin'));
        $this->registryRepo = new ModuleRegistryRepository($this->pdo);
        $this->router = new Router();

        $migrationRunner = $this->createMock(MigrationRunner::class);
        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);

        $this->manager = new ModuleManager(
            $this->fixturesDir,
            $this->settingService,
            $this->cookieConsentService,
            $this->menuBuilder,
            $this->registryRepo,
            $migrationRunner,
            $journalService,
            $this->router
        );
    }

    public function testDiscoverModulesFindsModulesInDirectory(): void
    {
        $modules = $this->manager->discoverModules();

        $this->assertGreaterThanOrEqual(2, count($modules));
        $ids = array_map(fn($m) => $m->manifest->id, $modules);
        $this->assertContains('valid_module', $ids);
    }

    public function testDiscoverModulesDetectsValidationErrors(): void
    {
        $modules = $this->manager->discoverModules();

        $invalid = null;
        foreach ($modules as $m) {
            if ($m->manifest->id === 'invalid_module') {
                $invalid = $m;
                break;
            }
        }

        $this->assertNotNull($invalid);
        $this->assertNotNull($invalid->validationError);
        $this->assertTrue($invalid->presentOnDisk);
    }

    public function testDiscoverModulesDetectsModulesMissingFromDisk(): void
    {
        // Add a registry entry for a module not on disk
        $this->registryRepo->upsert('phantom_module', true, '1.0.0', null);

        $modules = $this->manager->discoverModules();

        $phantom = null;
        foreach ($modules as $m) {
            if ($m->manifest->id === 'phantom_module') {
                $phantom = $m;
                break;
            }
        }

        $this->assertNotNull($phantom);
        $this->assertFalse($phantom->presentOnDisk);
        $this->assertTrue($phantom->enabled);
    }

    public function testActivateCreatesRegistryEntryAndRegistersSettings(): void
    {
        $this->manager->activate('valid_module', 1);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['enabled']);
        $this->assertSame('1.0.0', $entry['installed_version']);

        // Settings should be registered
        $this->settingService->clearCache();
        $this->assertSame('default', $this->settingService->get('test_setting', 'valid_module'));
    }

    public function testActivateWithInvalidManifestThrowsException(): void
    {
        $this->expectException(ModuleException::class);
        $this->manager->activate('invalid_module', 1);
    }

    public function testDeactivateSetsEnabledFalse(): void
    {
        $this->manager->activate('valid_module', 1);
        $this->manager->deactivate('valid_module', 1);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertFalse($entry['enabled']);
    }

    public function testLoadEnabledModulesRegistersRoutesForEnabledOnly(): void
    {
        // Activate the module
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        // The router should have routes from the valid module
        $request = new \Core\Http\Request('GET', '/test-module', [], [], [], []);
        $resolved = $this->router->resolve($request);
        $this->assertNotNull($resolved);
        $this->assertSame('Modules\\ValidModule\\Controller\\TestController', $resolved->controllerClass);
    }

    public function testLoadEnabledModulesSkipsDisabledModules(): void
    {
        // Module is in registry but disabled
        $this->registryRepo->upsert('valid_module', false, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $request = new \Core\Http\Request('GET', '/test-module', [], [], [], []);
        $resolved = $this->router->resolve($request);
        $this->assertNull($resolved);
    }

    public function testLoadEnabledModulesRegistersSettings(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $this->settingService->clearCache();
        $this->assertSame('default', $this->settingService->get('test_setting', 'valid_module'));
    }

    public function testLoadEnabledModulesRegistersCookies(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $declared = $this->cookieConsentService->getAllDeclaredCookies();
        $functionalNames = array_map(fn($c) => $c['name'], $declared['functional']['cookies']);
        $this->assertContains('test_pref', $functionalNames);
    }

    public function testLoadEnabledModulesRegistersMenuPages(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);

        // Add a minimum core page so the menu has something
        $this->menuBuilder->addPage('espace_animes', 'Placeholder', '/placeholder', 'identified', 10);

        $this->manager->loadEnabledModules();

        $menus = $this->menuBuilder->build();

        // Find 'espace_animes' menu
        $espaceAnimes = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === 'espace_animes') {
                $espaceAnimes = $menu;
                break;
            }
        }
        $this->assertNotNull($espaceAnimes);

        $labels = array_map(fn($p) => $p['label'] ?? '', $espaceAnimes['pages']);
        $this->assertContains('Test Module', $labels);
    }

    public function testGetTaskHandlerReturnsCorrectClass(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->manager->loadEnabledModules();

        $handler = $this->manager->getTaskHandler('valid_module', 'test_task');
        $this->assertSame('Modules\\ValidModule\\Task\\TestHandler', $handler);
    }

    public function testGetTaskHandlerReturnsNullForUnknownTask(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->manager->loadEnabledModules();

        $handler = $this->manager->getTaskHandler('valid_module', 'nonexistent');
        $this->assertNull($handler);
    }

    public function testGetEnabledModuleIds(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->manager->loadEnabledModules();

        $ids = $this->manager->getEnabledModuleIds();
        $this->assertContains('valid_module', $ids);
    }
}
