<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
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

/**
 * The SHIPPED modules, loaded by the real loader from the real
 * `modules/` directory, remember which module each of their routes
 * belongs to.
 *
 * `Tests\Core\Module\ModuleManagerTest` pins the same property against a
 * fixture, which is the right place for the mechanism. This one exists
 * because the defect it guards against was invisible to every unit test
 * in the tree: `Router::getModuleForPath()` answered null for every route
 * on every request for as long as ownership was recorded by a method the
 * loader never called, and the two Router suites that covered that method
 * all passed. The first consumer to ask the question — Modules\UsageStats
 * — reported that a unit's trombinoscope and gallery pages belonged to
 * `core`, and that is how anybody found out.
 *
 * So: the real manifests, the real loader, and a route resolved the way a
 * request resolves one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ModuleRouteOwnershipTest extends TestCase
{
    private Router $router;

    /** @var list<array{id: string, path: string}> */
    private array $expected = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->router = new Router();

        $registry = new ModuleRegistryRepository($pdo);

        // Enabled at exactly the version their manifest declares, so
        // loading them registers routes without triggering a schema
        // migration this test has no business running.
        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest) || !is_string($manifest['id'] ?? null)) {
                continue;
            }

            $registry->upsert($manifest['id'], true, (string) $manifest['version'], null);

            foreach ($manifest['routes'] ?? [] as $route) {
                if (($route['method'] ?? 'GET') === 'GET') {
                    $this->expected[] = ['id' => $manifest['id'], 'path' => (string) $route['path']];
                }
            }
        }

        $journal = new JournalService(new JournalRepository($pdo));

        (new ModuleManager(
            $root . '/modules',
            new SettingService(new SettingRepository($pdo)),
            new CookieConsentService([]),
            new MenuBuilder(Role::SUPERADMIN),
            $registry,
            $journal,
            $this->router,
            null,
            new \Core\Offline\OfflineWhitelist(),
            // Every installation flag, so `visible_when` modules
            // (support_dashboard, test_tools) load here too.
            new \Core\Module\InstallationProfile(\Core\Module\InstallationProfile::KNOWN_FLAGS)
        ))->loadEnabledModules();
    }

    public function testEveryShippedModuleRouteIsAttributedToItsOwnModule(): void
    {
        $this->assertNotSame([], $this->expected, 'No module route was collected — the scan is broken.');

        $wrong = [];
        foreach ($this->expected as $route) {
            $owner = $this->router->getModuleForPath($route['path']);
            if ($owner !== $route['id']) {
                $wrong[] = $route['path'] . ' → ' . var_export($owner, true) . ' (expected ' . $route['id'] . ')';
            }
        }

        $this->assertSame([], $wrong, "Module routes attributed to the wrong owner:\n" . implode("\n", $wrong));
    }

    /**
     * The two pages the bug report named, resolved the way a request
     * resolves them rather than looked up by string.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reportedPages')]
    public function testAPageOpenedByAVisitorResolvesToItsModule(string $url, string $moduleId): void
    {
        $resolved = $this->router->resolve(new Request('GET', $url, [], [], [], []));

        $this->assertNotNull($resolved, $url . ' resolves to no route at all.');
        $this->assertSame($moduleId, $this->router->getModuleForPath($resolved->path));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function reportedPages(): array
    {
        return [
            'le trombinoscope' => ['/trombinoscope', 'trombinoscope'],
            'la galerie' => ['/gallery', 'gallery'],
            'un album' => ['/gallery/1', 'gallery'],
        ];
    }

    /**
     * A core page stays core. The recorder turns null into `core`, so a
     * route wrongly CLAIMED by a module is exactly as wrong as one
     * wrongly disowned — and only one of the two is obvious on screen.
     */
    public function testACoreRouteIsNotClaimedByAnyModule(): void
    {
        $this->assertNull($this->router->getModuleForPath('/'));
        $this->assertNull($this->router->getModuleForPath('/members/{id}'));
    }
}
