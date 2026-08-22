<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\InstallationProfile;
use Core\Module\ModuleException;
use Core\Module\ModuleInfo;
use Core\Module\ModuleManager;
use Core\Module\ModuleManifest;
use Core\Module\ModuleRegistryRepository;
use Core\Offline\OfflineWhitelist;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ModuleManagerTest extends TestCase
{
    private ModuleManager $manager;
    private ModuleRegistryRepository $registryRepo;
    private SettingService $settingService;
    private CookieConsentService $cookieConsentService;
    private OfflineWhitelist $offlineWhitelist;
    private MenuBuilder $menuBuilder;
    private Router $router;
    private string $fixturesDir;
    private \PDO $pdo;
    private MigrationRunner&\PHPUnit\Framework\MockObject\MockObject $migrationRunner;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->fixturesDir = dirname(__DIR__, 2) . '/fixtures/modules';

        $settingRepo = new SettingRepository($this->pdo);
        $this->settingService = new SettingService($settingRepo);
        $this->cookieConsentService = new CookieConsentService([]);
        $this->offlineWhitelist = new OfflineWhitelist();
        $this->menuBuilder = new MenuBuilder(Role::fromString('admin'));
        $this->registryRepo = new ModuleRegistryRepository($this->pdo);
        $this->router = new Router();

        $this->migrationRunner = $this->createMock(MigrationRunner::class);
        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);

        $this->manager = new ModuleManager(
            $this->fixturesDir,
            $this->settingService,
            $this->cookieConsentService,
            $this->menuBuilder,
            $this->registryRepo,
            $this->migrationRunner,
            $journalService,
            $this->router,
            null,
            $this->offlineWhitelist
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

    public function testLoadEnabledModulesRegistersOfflineEntries(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $paths = array_column($this->offlineWhitelist->getAllEntries(), 'path');
        $this->assertContains('/test-module', $paths);
    }

    public function testADisabledModuleNeverRegistersItsOfflineEntry(): void
    {
        // valid_module is discovered on disk but never enabled here — no
        // registry row, so loadEnabledModules() skips it entirely.
        $this->manager->loadEnabledModules();

        $paths = array_column($this->offlineWhitelist->getAllEntries(), 'path');
        $this->assertNotContains('/test-module', $paths);
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

    public function testLoadEnabledModulesAutoActivatesEnabledByDefaultOnFirstDiscovery(): void
    {
        // No registry row at all for auto_enabled_module — first discovery ever.
        $this->manager->loadEnabledModules();

        $entry = $this->registryRepo->findByModuleId('auto_enabled_module');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['enabled']);
        $this->assertContains('auto_enabled_module', $this->manager->getEnabledModuleIds());

        $request = new \Core\Http\Request('GET', '/auto-enabled', [], [], [], []);
        $resolved = $this->router->resolve($request);
        $this->assertNotNull($resolved);
    }

    public function testLoadEnabledModulesRespectsExplicitDeactivation(): void
    {
        // Admin already deactivated it once — a registry row exists with enabled=false.
        $this->registryRepo->upsert('auto_enabled_module', false, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $this->assertNotContains('auto_enabled_module', $this->manager->getEnabledModuleIds());
        $entry = $this->registryRepo->findByModuleId('auto_enabled_module');
        $this->assertFalse($entry['enabled']);
    }

    public function testLoadEnabledModulesUsesCustomMenuOrderOnlyWithinTheModuleGroup(): void
    {
        // auto_enabled_module declares menu_order: 3 — a real, very low
        // value (same shape as trombinoscope's 5 / gallery's 6, see
        // ARCHITECTURE §7.1). Core\View\MenuBuilder::buildPages() now
        // sorts by entry type first (dynamic, then core, then module), so
        // however low this module's menu_order is, it can never sort
        // ahead of a core page anymore — only against other modules.
        $this->menuBuilder->addPage('espace_animes', 'Placeholder', '/placeholder', 'identified', 10);

        $this->manager->loadEnabledModules();

        $menus = $this->menuBuilder->build();
        $espaceAnimes = null;
        foreach ($menus as $menu) {
            if ($menu['id'] === 'espace_animes') {
                $espaceAnimes = $menu;
                break;
            }
        }
        $this->assertNotNull($espaceAnimes);

        $labels = array_map(fn($p) => $p['label'] ?? '', $espaceAnimes['pages']);
        $this->assertSame('Placeholder', $labels[0], 'core page must sort before the module page regardless of the module\'s low menu_order');
        $this->assertContains('Auto Enabled', $labels);
    }

    public function testGetEnabledModuleIds(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->manager->loadEnabledModules();

        $ids = $this->manager->getEnabledModuleIds();
        $this->assertContains('valid_module', $ids);
    }

    public function testDiscoverModulesOrdersBySortOrder(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->registryRepo->upsert('second_module', true, '1.0.0', null);
        // Flip the natural (append) order: second_module first.
        $this->registryRepo->reorder(['second_module', 'valid_module']);

        $ids = array_map(fn($m) => $m->manifest->id, $this->manager->discoverModules());
        $known = array_values(array_intersect($ids, ['second_module', 'valid_module']));

        $this->assertSame(['second_module', 'valid_module'], $known);
    }

    public function testDiscoverModulesSortsUntouchedModulesLastAlphabetically(): void
    {
        // No registry rows for either — neither has ever been toggled.
        $ids = array_map(fn($m) => $m->manifest->id, $this->manager->discoverModules());
        $known = array_values(array_intersect($ids, ['second_module', 'valid_module']));

        $this->assertSame(['second_module', 'valid_module'], $known);
    }

    public function testReorderCreatesRegistryRowForNeverToggledModuleWithoutEnablingIt(): void
    {
        $this->assertNull($this->registryRepo->findByModuleId('valid_module'));

        $this->manager->reorder(['valid_module', 'second_module']);

        $entry = $this->registryRepo->findByModuleId('valid_module');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['enabled']);
        $this->assertSame(0, $entry['sort_order']);
        $this->assertSame('1.0.0', $entry['installed_version']);

        $second = $this->registryRepo->findByModuleId('second_module');
        $this->assertSame(1, $second['sort_order']);
    }

    public function testModuleReorderChangesDefaultMenuOrderAcrossModules(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->registryRepo->upsert('second_module', true, '1.0.0', null);
        // Both routes use the plain default menu_order, in the
        // 'configuration' menu — only the module's own position (append
        // order here: valid_module then second_module) should decide which
        // sorts first.

        $labelsBefore = $this->configurationMenuLabels();
        $this->assertLessThan(
            array_search('Second Module Config', $labelsBefore, true),
            array_search('Test Module Config', $labelsBefore, true)
        );

        $this->manager->reorder(['second_module', 'valid_module']);

        $labelsAfter = $this->configurationMenuLabels();
        $this->assertLessThan(
            array_search('Test Module Config', $labelsAfter, true),
            array_search('Second Module Config', $labelsAfter, true)
        );
    }

    public function testLoadEnabledModulesLoadsAModuleWhoseHardDependencyIsEnabled(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->registryRepo->upsert('dependent_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $this->assertContains('dependent_module', $this->manager->getEnabledModuleIds());
        $this->assertNotNull($this->router->resolve(new \Core\Http\Request('GET', '/dependent-module', [], [], [], [])));
    }

    public function testLoadEnabledModulesSkipsAModuleWhoseHardDependencyIsDisabled(): void
    {
        $this->registryRepo->upsert('valid_module', false, '1.0.0', null);
        $this->registryRepo->upsert('dependent_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $this->assertNotContains('dependent_module', $this->manager->getEnabledModuleIds());
        $this->assertNull($this->router->resolve(new \Core\Http\Request('GET', '/dependent-module', [], [], [], [])));
    }

    public function testLoadEnabledModulesSkipsAModuleWhoseHardDependencyIsAbsentFromDisk(): void
    {
        // missing_dep_module requires a module id that no directory in the
        // fixtures provides — the registry entry stays enabled, the module
        // simply degrades to "not loaded" and the site keeps working.
        $this->registryRepo->upsert('missing_dep_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $this->assertNotContains('missing_dep_module', $this->manager->getEnabledModuleIds());
        $this->assertNull($this->router->resolve(new \Core\Http\Request('GET', '/missing-dep-module', [], [], [], [])));
        $this->assertTrue($this->registryRepo->findByModuleId('missing_dep_module')['enabled']);
    }

    public function testLoadEnabledModulesLoadsADependentSortedBeforeItsDependency(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->registryRepo->upsert('dependent_module', true, '1.0.0', null);
        // The admin dragged the dependent module above the one it requires:
        // resolution must look at the whole discovered set, not at whatever
        // has already been loaded by this point in the loop.
        $this->manager->reorder(['dependent_module', 'valid_module']);

        $this->manager->loadEnabledModules();

        $this->assertSame(
            ['dependent_module', 'valid_module'],
            array_values(array_intersect($this->manager->getEnabledModuleIds(), ['dependent_module', 'valid_module']))
        );
    }

    public function testLoadEnabledModulesSkipsEveryModuleInADependencyCycle(): void
    {
        $this->registryRepo->upsert('cycle_a_module', true, '1.0.0', null);
        $this->registryRepo->upsert('cycle_b_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $enabled = $this->manager->getEnabledModuleIds();
        $this->assertNotContains('cycle_a_module', $enabled);
        $this->assertNotContains('cycle_b_module', $enabled);
        $this->assertNull($this->router->resolve(new \Core\Http\Request('GET', '/cycle-a', [], [], [], [])));
        $this->assertNull($this->router->resolve(new \Core\Http\Request('GET', '/cycle-b', [], [], [], [])));
    }

    public function testLoadEnabledModulesSkipsAModuleWhoseDependencyIsItselfUnsatisfied(): void
    {
        // A dependency that cannot function itself satisfies nobody:
        // dependent_module requires valid_module, which is disabled here.
        $this->registryRepo->upsert('dependent_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $this->assertNotContains('dependent_module', $this->manager->getEnabledModuleIds());
    }

    public function testLoadEnabledModulesDoesNotAutoActivateAModuleWithUnmetRequirements(): void
    {
        // auto_dependent_module is enabled_by_default but requires
        // valid_module, which has no registry row here.
        $this->manager->loadEnabledModules();

        $this->assertNull($this->registryRepo->findByModuleId('auto_dependent_module'));
        $this->assertNotContains('auto_dependent_module', $this->manager->getEnabledModuleIds());
    }

    public function testLoadEnabledModulesAutoActivatesADefaultModuleOnceItsRequirementIsMet(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);

        $this->manager->loadEnabledModules();

        $entry = $this->registryRepo->findByModuleId('auto_dependent_module');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['enabled']);
        $this->assertContains('auto_dependent_module', $this->manager->getEnabledModuleIds());
    }

    public function testActivateRefusesAnUnmetRequirementBeforeMigratingTheSchema(): void
    {
        // dependent_module ships a schema.sql precisely so this ordering is
        // observable: the refusal must happen before any migration runs.
        $this->migrationRunner->expects($this->never())->method('migrate');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('nécessite le module « Module de test valide »');

        $this->manager->activate('dependent_module', 1);
    }

    public function testActivateRefusalLeavesNoRegistryRow(): void
    {
        try {
            $this->manager->activate('dependent_module', 1);
            $this->fail('activate() should have refused an unmet requirement');
        } catch (ModuleException) {
            // expected
        }

        $this->assertNull($this->registryRepo->findByModuleId('dependent_module'));
    }

    public function testActivateSucceedsOnceTheRequirementIsEnabled(): void
    {
        $this->migrationRunner->method('migrate')->willReturn(
            new \Core\Database\MigrationResult([], [], false)
        );
        $this->manager->activate('valid_module', 1);

        $this->manager->activate('dependent_module', 1);

        $entry = $this->registryRepo->findByModuleId('dependent_module');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['enabled']);
    }

    public function testDeactivateRefusesWhileAnEnabledModuleRequiresIt(): void
    {
        $this->migrationRunner->method('migrate')->willReturn(
            new \Core\Database\MigrationResult([], [], false)
        );
        $this->manager->activate('valid_module', 1);
        $this->manager->activate('dependent_module', 1);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requis par le module « Module dépendant »');

        $this->manager->deactivate('valid_module', 1);
    }

    public function testDeactivateSucceedsOnceTheDependentIsDisabled(): void
    {
        $this->migrationRunner->method('migrate')->willReturn(
            new \Core\Database\MigrationResult([], [], false)
        );
        $this->manager->activate('valid_module', 1);
        $this->manager->activate('dependent_module', 1);

        $this->manager->deactivate('dependent_module', 1);
        $this->manager->deactivate('valid_module', 1);

        $this->assertFalse($this->registryRepo->findByModuleId('valid_module')['enabled']);
    }

    public function testFindEnabledDependentsIgnoresDisabledModules(): void
    {
        $this->registryRepo->upsert('valid_module', true, '1.0.0', null);
        $this->registryRepo->upsert('dependent_module', false, '1.0.0', null);

        $dependents = $this->manager->findEnabledDependents('valid_module', $this->manager->discoverModules());

        $this->assertSame([], array_map(fn($m) => $m->manifest->id, $dependents));
    }

    public function testAreRequirementsSatisfiedIsTrueForAModuleDeclaringNone(): void
    {
        $this->assertTrue(
            $this->manager->areRequirementsSatisfied('valid_module', $this->manager->discoverModules())
        );
    }

    /**
     * @return string[]
     */
    private function configurationMenuLabels(): array
    {
        $menuBuilder = new MenuBuilder(Role::fromString('superadmin'));
        $manager = new ModuleManager(
            $this->fixturesDir,
            $this->settingService,
            $this->cookieConsentService,
            $menuBuilder,
            $this->registryRepo,
            $this->createMock(MigrationRunner::class),
            new JournalService(new JournalRepository($this->pdo)),
            new Router()
        );
        $manager->loadEnabledModules();

        foreach ($menuBuilder->build() as $menu) {
            if ($menu['id'] === 'configuration') {
                return array_map(fn($p) => $p['label'] ?? '', $menu['pages']);
            }
        }

        return [];
    }

    /**
     * @param array<int, string> $flags
     */
    private function managerWithProfile(array $flags): ModuleManager
    {
        return new ModuleManager(
            $this->fixturesDir,
            $this->settingService,
            $this->cookieConsentService,
            $this->menuBuilder,
            $this->registryRepo,
            $this->migrationRunner,
            new JournalService(new JournalRepository($this->pdo)),
            $this->router,
            null,
            $this->offlineWhitelist,
            new InstallationProfile($flags)
        );
    }

    /**
     * @param ModuleInfo[] $modules
     * @return string[]
     */
    private static function moduleIdsOf(array $modules): array
    {
        return array_map(static fn(ModuleInfo $module): string => $module->manifest->id, $modules);
    }

    public function testAGatedModuleIsInvisibleWhenNoneOfItsFlagsHolds(): void
    {
        $ids = self::moduleIdsOf($this->managerWithProfile([])->discoverModules());

        $this->assertNotContains('visible_when_module', $ids);
        $this->assertContains('valid_module', $ids);
    }

    public function testAGatedModuleIsInvisibleWhenOnlyAnUnlistedFlagHolds(): void
    {
        $ids = self::moduleIdsOf($this->managerWithProfile([
            InstallationProfile::FLAG_LOCAL_INSTALLATION,
        ])->discoverModules());

        $this->assertNotContains('visible_when_module', $ids);
    }

    public function testAGatedModuleIsVisibleWhenItsFlagHolds(): void
    {
        $ids = self::moduleIdsOf($this->managerWithProfile([
            InstallationProfile::FLAG_STATISTICS_RECEIVER,
        ])->discoverModules());

        $this->assertContains('visible_when_module', $ids);
    }

    /**
     * OR semantics: one flag out of several listed ones is enough.
     */
    public function testAModuleListingSeveralFlagsIsVisibleWhenOneHolds(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => ['reference_installation', 'local_installation'],
        ]);
        $profile = new InstallationProfile([InstallationProfile::FLAG_LOCAL_INSTALLATION]);

        $this->assertTrue($profile->hasAny($manifest->visibleWhen));
    }

    /**
     * The unset() in discoverModules() is load-bearing: without it a hidden
     * module reappears through the "in registry but missing from disk"
     * branch, which is exactly what the filter exists to prevent.
     */
    public function testAGatedModuleIsAbsentEvenWithARegistryRow(): void
    {
        $this->registryRepo->upsert('visible_when_module', true, '1.0.0', null);

        $ids = self::moduleIdsOf($this->managerWithProfile([])->discoverModules());

        $this->assertNotContains('visible_when_module', $ids);
    }

    public function testAGatedModuleRegistersNoRouteWhenNoFlagHolds(): void
    {
        $this->registryRepo->upsert('visible_when_module', true, '1.0.0', null);

        $router = new Router();
        $manager = new ModuleManager(
            $this->fixturesDir,
            $this->settingService,
            $this->cookieConsentService,
            new MenuBuilder(Role::fromString('admin')),
            $this->registryRepo,
            $this->migrationRunner,
            new JournalService(new JournalRepository($this->pdo)),
            $router,
            null,
            $this->offlineWhitelist,
            new InstallationProfile([])
        );
        $manager->loadEnabledModules();

        $this->assertNull($router->resolve(new Request('GET', '/visible-when', [], [], [], [])));
        $this->assertNotContains('visible_when_module', $manager->getEnabledModuleIds());
    }

    public function testAGatedModuleRegistersItsRouteWhenItsFlagHolds(): void
    {
        $this->registryRepo->upsert('visible_when_module', true, '1.0.0', null);

        $router = new Router();
        $manager = new ModuleManager(
            $this->fixturesDir,
            $this->settingService,
            $this->cookieConsentService,
            new MenuBuilder(Role::fromString('admin')),
            $this->registryRepo,
            $this->migrationRunner,
            new JournalService(new JournalRepository($this->pdo)),
            $router,
            null,
            $this->offlineWhitelist,
            new InstallationProfile([InstallationProfile::FLAG_STATISTICS_RECEIVER])
        );
        $manager->loadEnabledModules();

        $this->assertNotNull($router->resolve(new Request('GET', '/visible-when', [], [], [], [])));
        $this->assertContains('visible_when_module', $manager->getEnabledModuleIds());
    }
}
