<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\InstallationProfile;
use Core\Module\ModuleException;
use Core\Module\ModuleManifest;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

class ModuleManifestTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__, 2) . '/fixtures/modules';
    }

    public function testFromFileParsesValidManifest(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertSame('valid_module', $manifest->id);
        $this->assertSame('Module de test valide', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertCount(2, $manifest->routes);
        $this->assertCount(1, $manifest->settings);
        $this->assertCount(1, $manifest->cookies);
        $this->assertCount(1, $manifest->scheduledTasks);
        $this->assertCount(1, $manifest->storage);
    }

    public function testFromFileValidatesRouteStructure(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $route = $manifest->routes[0];
        $this->assertSame('/test-module', $route['path']);
        $this->assertSame('GET', $route['method']);
        $this->assertSame('Modules\\ValidModule\\Controller\\TestController', $route['controller']);
        $this->assertSame('index', $route['action']);
        $this->assertSame('espace_animes', $route['menu']);
        $this->assertSame('identified', $route['role_min']);
        $this->assertSame('Test Module', $route['label']);
    }

    public function testValidationRejectsMissingId(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'id'");

        ModuleManifest::fromArray(['name' => 'Test', 'version' => '1.0.0']);
    }

    public function testValidationRejectsMissingName(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'name'");

        ModuleManifest::fromArray(['id' => 'test', 'version' => '1.0.0']);
    }

    public function testValidationRejectsInvalidVersion(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('version must be semver');

        ModuleManifest::fromArray(['id' => 'test', 'name' => 'Test', 'version' => 'abc']);
    }

    public function testValidationRejectsRouteWithoutRoleMin(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'role_min'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes'],
            ],
        ]);
    }

    public function testValidationRejectsInvalidMenuValue(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid menu value");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'invalid_menu', 'role_min' => 'public'],
            ],
        ]);
    }

    public function testValidationRejectsRoleMinMorePermissiveThanMenuMinimum(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("more permissive than menu");

        // Configuration menu requires admin, but route has role_min: public
        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'configuration', 'role_min' => 'public'],
            ],
        ]);
    }

    public function testValidationAcceptsAbsentOptionalSections(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'minimal',
            'name' => 'Minimal Module',
            'version' => '1.0.0',
        ]);

        $this->assertSame('minimal', $manifest->id);
        $this->assertEmpty($manifest->routes);
        $this->assertEmpty($manifest->settings);
        $this->assertEmpty($manifest->cookies);
        $this->assertEmpty($manifest->scheduledTasks);
        $this->assertEmpty($manifest->storage);
    }

    public function testValidationRejectsInvalidCookieCategory(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid category");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'cookies' => [
                ['name' => 'c', 'category' => 'marketing', 'purpose' => 'p', 'duration' => 'd'],
            ],
        ]);
    }

    public function testValidationRejectsInvalidRoleMinValue(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid role_min");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'notre_unite', 'role_min' => 'wizard'],
            ],
        ]);
    }

    public function testValidationRejectsScheduledTaskWithoutHandler(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'handler'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'scheduled_tasks' => [
                ['key' => 'my_task'],
            ],
        ]);
    }

    public function testValidationRejectsSettingWithoutDescription(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'description'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'settings' => [
                ['key' => 'k', 'type' => 'text', 'label' => 'L'],
            ],
        ]);
    }

    public function testRouteMenuOrderDefaultsTo100(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertSame(100, $manifest->routes[0]['menu_order']);
    }

    public function testRouteMenuOrderCanBeSetExplicitly(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_order' => 5],
            ],
        ]);

        $this->assertSame(5, $manifest->routes[0]['menu_order']);
    }

    public function testRouteMenuOrderRejectsNonInteger(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("'menu_order' must be an integer");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_order' => 'first'],
            ],
        ]);
    }

    // --- menu_icon ------------------------------------------------------

    public function testRouteMenuIconIsNullWhenNotDeclared(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertNull($manifest->routes[0]['menu_icon']);
    }

    public function testRouteMenuIconIsKeptWhenDeclared(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_icon' => 'bi-calendar3'],
            ],
        ]);

        $this->assertSame('bi-calendar3', $manifest->routes[0]['menu_icon']);
    }

    /**
     * The value is interpolated into a class attribute, so anything
     * outside the Bootstrap Icons vocabulary's own shape is refused at
     * load time rather than escaped at render time.
     */
    public function testRouteMenuIconRejectsAnythingThatIsNotABootstrapIconsClass(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("'menu_icon' must be a Bootstrap Icons class");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_icon' => '" onload="alert(1)'],
            ],
        ]);
    }

    public function testRouteMenuIconRejectsNonString(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("'menu_icon' must be a string");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_icon' => 42],
            ],
        ]);
    }

    // --- menu_group -----------------------------------------------------

    public function testRouteMenuGroupIsNullWhenNotDeclared(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertNull($manifest->routes[0]['menu_group']);
    }

    public function testRouteMenuGroupIsKeptWhenDeclaredForThatMenu(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_group' => 'pages'],
            ],
        ]);

        $this->assertSame('pages', $manifest->routes[0]['menu_group']);
    }

    /**
     * The vocabulary is closed per menu (Core\View\MenuBuilder::MENU_GROUPS)
     * exactly as `menu` itself is: a group that exists, but not in *this*
     * menu, is still refused — otherwise two modules writing "gestion" and
     * "Gestion" would draw two columns meaning the same thing.
     */
    public function testRouteMenuGroupRejectsAGroupNotDeclaredForThatMenu(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid menu_group value 'gestion' for menu 'espace_animes'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_group' => 'gestion'],
            ],
        ]);
    }

    /**
     * "Notre unité" declares no group at all, so no value can be valid
     * there — a module naming one is stating something the menu cannot
     * honour, and finds out at load time rather than never.
     */
    public function testRouteMenuGroupRejectsAnyValueOnAnUngroupedMenu(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid menu_group value 'pages' for menu 'notre_unite'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'notre_unite', 'role_min' => 'public', 'menu_group' => 'pages'],
            ],
        ]);
    }

    public function testRouteMenuGroupRejectsNonString(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("'menu_group' must be a string");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_animes', 'role_min' => 'identified', 'menu_group' => 42],
            ],
        ]);
    }

    /**
     * Every shipped module.json is loaded for real: a `menu_group` typo in
     * any of them is a fatal boot, so it has to be caught here rather than
     * on the first request after a deploy.
     */
    public function testEveryShippedModuleManifestDeclaresValidMenuGroups(): void
    {
        $manifests = glob(dirname(__DIR__, 3) . '/modules/*/module.json');
        $this->assertNotEmpty($manifests);

        foreach ($manifests as $path) {
            $manifest = ModuleManifest::fromFile($path);

            foreach ($manifest->routes as $route) {
                if ($route['menu_group'] === null) {
                    continue;
                }

                $this->assertContains(
                    $route['menu_group'],
                    MenuBuilder::groupIdsFor($route['menu']),
                    "{$manifest->id}: '{$route['menu_group']}' is not a group of '{$route['menu']}'"
                );
            }
        }
    }

    public function testEnabledByDefaultDefaultsToFalse(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertFalse($manifest->enabledByDefault);
    }

    public function testEnabledByDefaultCanBeSetTrue(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'enabled_by_default' => true,
        ]);

        $this->assertTrue($manifest->enabledByDefault);
    }

    public function testDescriptionDefaultsToEmptyString(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertSame('', $manifest->description);
    }

    public function testDescriptionCanBeSet(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'description' => 'Un module de test.',
        ]);

        $this->assertSame('Un module de test.', $manifest->description);
    }

    public function testRouteBreadcrumbDefaultsToNullWhenAbsent(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/valid_module/module.json');

        $this->assertNull($manifest->routes[0]['breadcrumb']);
    }

    public function testRouteBreadcrumbIsParsedWithLabelAndParents(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/test',
                    'controller' => 'C',
                    'action' => 'a',
                    'menu' => 'espace_chefs',
                    'role_min' => 'intendant',
                    'breadcrumb' => ['label' => 'Staffs', 'parents' => ['Espace animateurs']],
                ],
            ],
        ]);

        $this->assertSame(
            ['label' => 'Staffs', 'parents' => ['Espace animateurs']],
            $manifest->routes[0]['breadcrumb']
        );
    }

    public function testRouteBreadcrumbParentsDefaultsToEmptyArray(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/test',
                    'controller' => 'C',
                    'action' => 'a',
                    'menu' => 'espace_chefs',
                    'role_min' => 'intendant',
                    'breadcrumb' => ['label' => 'Staffs'],
                ],
            ],
        ]);

        $this->assertSame(['label' => 'Staffs', 'parents' => []], $manifest->routes[0]['breadcrumb']);
    }

    public function testRouteBreadcrumbRejectsMissingLabel(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("breadcrumb missing or invalid 'label'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/test',
                    'controller' => 'C',
                    'action' => 'a',
                    'menu' => 'espace_chefs',
                    'role_min' => 'intendant',
                    'breadcrumb' => ['parents' => ['Espace animateurs']],
                ],
            ],
        ]);
    }

    public function testRouteBreadcrumbRejectsNonArrayParents(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("breadcrumb 'parents' must be an array");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/test',
                    'controller' => 'C',
                    'action' => 'a',
                    'menu' => 'espace_chefs',
                    'role_min' => 'intendant',
                    'breadcrumb' => ['label' => 'Staffs', 'parents' => 'Espace animateurs'],
                ],
            ],
        ]);
    }

    public function testRouteBreadcrumbRejectsNonStringBreadcrumbValue(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("'breadcrumb' must be an object");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/test',
                    'controller' => 'C',
                    'action' => 'a',
                    'menu' => 'espace_chefs',
                    'role_min' => 'intendant',
                    'breadcrumb' => 'Staffs',
                ],
            ],
        ]);
    }

    public function testValidationRejectsAnEarlierWildcardRouteShadowingALaterLiteralRoute(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("shadows");

        // Real incident: '/x/{id}/{token}' declared before '/x/demande/{id}'
        // swallows every request meant for the second route — id="demande"
        // casts to 0, the visitor gets a plain 404 with no clue why.
        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/x/{id}/{token}', 'method' => 'GET', 'controller' => 'C', 'action' => 'a', 'menu' => 'notre_unite', 'role_min' => 'public'],
                ['path' => '/x/demande/{id}', 'method' => 'GET', 'controller' => 'C', 'action' => 'b', 'menu' => 'espace_animes', 'role_min' => 'identified'],
            ],
        ]);
    }

    public function testValidationAllowsALiteralRouteDeclaredBeforeAWildcardRoute(): void
    {
        // The safe, common direction: a literal action segment placed
        // before a generic '{id}' catch-all so the literal isn't itself
        // swallowed as an id — must never be rejected.
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/news/manage', 'method' => 'GET', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_admin', 'role_min' => 'admin'],
                ['path' => '/news/{id}', 'method' => 'GET', 'controller' => 'C', 'action' => 'b', 'menu' => 'notre_unite', 'role_min' => 'public'],
            ],
        ]);

        $this->assertCount(2, $manifest->routes);
    }

    public function testValidationAllowsDifferentLiteralsAtTheSamePosition(): void
    {
        // '/a/edit/{id}' and '/a/view/{id}' can never both match the same
        // request path — not a shadowing risk regardless of order.
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/a/edit/{id}', 'method' => 'GET', 'controller' => 'C', 'action' => 'a', 'menu' => 'espace_admin', 'role_min' => 'admin'],
                ['path' => '/a/view/{id}', 'method' => 'GET', 'controller' => 'C', 'action' => 'b', 'menu' => 'espace_admin', 'role_min' => 'admin'],
            ],
        ]);

        $this->assertCount(2, $manifest->routes);
    }

    public function testValidationAllowsSameShapeRoutesOnDifferentHttpMethods(): void
    {
        // GET '/x/{id}/{token}' and POST '/x/demande/{id}' never actually
        // compete for the same request — Core\Http\Router::resolve()
        // filters by method first.
        $manifest = ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                ['path' => '/x/{id}/{token}', 'method' => 'GET', 'controller' => 'C', 'action' => 'a', 'menu' => 'notre_unite', 'role_min' => 'public'],
                ['path' => '/x/demande/{id}', 'method' => 'POST', 'controller' => 'C', 'action' => 'b', 'menu' => 'espace_animes', 'role_min' => 'identified'],
            ],
        ]);

        $this->assertCount(2, $manifest->routes);
    }

    /**
     * Every real module.json in the repo, parsed for real — not just the
     * fixtures above. Guards against the exact incident that motivated
     * validateNoShadowedRoutes(): registration's own '/inscriptions/suivi/
     * {id}/{token}' once shadowed '/inscriptions/suivi/demande/{id}',
     * silently 404ing every "Espace membres" link to a family's own
     * registration request. ModuleManifest::fromFile() already runs this
     * check on every real request (Core\Module\ModuleManager::load()), so
     * this test doesn't add new enforcement — it just fails fast, with a
     * clear message, instead of only surfacing at boot or in production.
     */
    public function testEveryRealModuleManifestParsesWithoutShadowedRoutes(): void
    {
        $manifestPaths = glob(dirname(__DIR__, 3) . '/modules/*/module.json');
        $this->assertNotEmpty($manifestPaths, 'Expected to find at least one real module.json');

        foreach ($manifestPaths as $path) {
            ModuleManifest::fromFile($path);
        }

        $this->addToAssertionCount(1);
    }

    public function testFromFileThrowsForMissingFile(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('not found');

        ModuleManifest::fromFile('/nonexistent/module.json');
    }

    public function testFromFileRejectsInvalidModuleJson(): void
    {
        $this->expectException(ModuleException::class);

        // The invalid_module fixture is missing the 'id' field
        ModuleManifest::fromFile($this->fixturesDir . '/invalid_module/module.json');
    }

    // --- offline (Core\Offline\OfflineWhitelist aggregation) ---

    private function baseManifestData(array $offline): array
    {
        return [
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'offline' => $offline,
        ];
    }

    public function testOfflineEntryParsesWithDefaultMatchExact(): void
    {
        $manifest = ModuleManifest::fromArray($this->baseManifestData([
            ['path' => '/my-module', 'label' => 'Mon module', 'role_min' => 'public'],
        ]));

        $this->assertCount(1, $manifest->offline);
        $this->assertSame('/my-module', $manifest->offline[0]['path']);
        $this->assertSame('Mon module', $manifest->offline[0]['label']);
        $this->assertSame('exact', $manifest->offline[0]['match']);
        $this->assertSame('public', $manifest->offline[0]['role_min']);
    }

    public function testOfflineEntryAcceptsExplicitChildMatch(): void
    {
        $manifest = ModuleManifest::fromArray($this->baseManifestData([
            ['path' => '/my-module/items/', 'label' => 'Items', 'match' => 'child', 'role_min' => 'identified'],
        ]));

        $this->assertSame('child', $manifest->offline[0]['match']);
    }

    public function testOfflineEntryRejectsMissingPath(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'path'");

        ModuleManifest::fromArray($this->baseManifestData([
            ['label' => 'Mon module', 'role_min' => 'public'],
        ]));
    }

    public function testOfflineEntryRejectsPathNotStartingWithSlash(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("path must start with '/'");

        ModuleManifest::fromArray($this->baseManifestData([
            ['path' => 'my-module', 'label' => 'Mon module', 'role_min' => 'public'],
        ]));
    }

    public function testOfflineEntryRejectsMissingLabel(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'label'");

        ModuleManifest::fromArray($this->baseManifestData([
            ['path' => '/my-module', 'role_min' => 'public'],
        ]));
    }

    public function testOfflineEntryRejectsMissingRoleMin(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("missing or invalid 'role_min'");

        ModuleManifest::fromArray($this->baseManifestData([
            ['path' => '/my-module', 'label' => 'Mon module'],
        ]));
    }

    public function testOfflineEntryRejectsInvalidRoleMin(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid role_min");

        ModuleManifest::fromArray($this->baseManifestData([
            ['path' => '/my-module', 'label' => 'Mon module', 'role_min' => 'not-a-role'],
        ]));
    }

    public function testOfflineEntryRejectsInvalidMatchValue(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("invalid match");

        ModuleManifest::fromArray($this->baseManifestData([
            ['path' => '/my-module', 'label' => 'Mon module', 'match' => 'prefix', 'role_min' => 'public'],
        ]));
    }

    public function testManifestWithNoOfflineSectionDefaultsToEmptyArray(): void
    {
        $manifest = ModuleManifest::fromArray(['id' => 'test', 'name' => 'Test', 'version' => '1.0.0']);

        $this->assertSame([], $manifest->offline);
    }

    public function testFromFileParsesHardDependencies(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixturesDir . '/dependent_module/module.json');

        $this->assertSame(['valid_module'], $manifest->requires);
    }

    public function testManifestWithNoRequiresSectionDefaultsToEmptyArray(): void
    {
        $manifest = ModuleManifest::fromArray(['id' => 'test', 'name' => 'Test', 'version' => '1.0.0']);

        $this->assertSame([], $manifest->requires);
    }

    public function testRequiresRejectsNonArray(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires must be an array');

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'requires' => 'other_module',
        ]);
    }

    public function testRequiresRejectsEmptyStringElement(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires[1] must be a non-empty string');

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'requires' => ['other_module', ''],
        ]);
    }

    public function testRequiresRejectsNonStringElement(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('requires[0] must be a non-empty string');

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'requires' => [42],
        ]);
    }

    public function testRequiresRejectsDuplicate(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("duplicates module 'other_module'");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'requires' => ['other_module', 'other_module'],
        ]);
    }

    public function testRequiresRejectsSelfReference(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("Module 'test' requires itself");

        ModuleManifest::fromArray([
            'id' => 'test',
            'name' => 'Test',
            'version' => '1.0.0',
            'requires' => ['test'],
        ]);
    }

    public function testPositionalConstructionWithoutRequiresStillWorks(): void
    {
        // Core\Module\ModuleManager::discoverModules() builds placeholder
        // manifests exactly like this, positionally, in two places — a new
        // constructor parameter inserted anywhere but last would silently
        // land in the wrong slot there.
        $manifest = new ModuleManifest('ghost', 'ghost', '0.0.0', [], [], [], [], []);

        $this->assertSame('ghost', $manifest->id);
        $this->assertSame('0.0.0', $manifest->version);
        $this->assertSame([], $manifest->storage);
        $this->assertFalse($manifest->enabledByDefault);
        $this->assertSame([], $manifest->requires);
    }

    public function testASettingIsEditableByDefault(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'plain',
            'name' => 'Plain',
            'version' => '1.0.0',
            'settings' => [[
                'key' => 'k',
                'type' => 'text',
                'label' => 'Label',
                'description' => 'Description',
            ]],
        ]);

        $this->assertTrue($manifest->settings[0]['editable']);
    }

    public function testASettingCanDeclareItselfNonEditable(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'plain',
            'name' => 'Plain',
            'version' => '1.0.0',
            'settings' => [[
                'key' => 'k',
                'type' => 'boolean',
                'editable' => false,
                'label' => 'Label',
                'description' => 'Description',
            ]],
        ]);

        $this->assertFalse($manifest->settings[0]['editable']);
    }

    public function testANonBooleanSettingEditableIsRejected(): void
    {
        $this->expectException(ModuleException::class);

        ModuleManifest::fromArray([
            'id' => 'plain',
            'name' => 'Plain',
            'version' => '1.0.0',
            'settings' => [[
                'key' => 'k',
                'type' => 'boolean',
                'editable' => 'false',
                'label' => 'Label',
                'description' => 'Description',
            ]],
        ]);
    }

    public function testVisibleWhenDefaultsToEmpty(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'plain',
            'name' => 'Plain',
            'version' => '1.0.0',
        ]);

        $this->assertSame([], $manifest->visibleWhen);
    }

    public function testVisibleWhenAcceptsASingleFlag(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'receiver',
            'name' => 'Receiver',
            'version' => '1.0.0',
            'visible_when' => ['statistics_receiver'],
        ]);

        $this->assertSame(['statistics_receiver'], $manifest->visibleWhen);
    }

    public function testVisibleWhenAcceptsSeveralFlags(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => ['reference_installation', 'local_installation'],
        ]);

        $this->assertSame(['reference_installation', 'local_installation'], $manifest->visibleWhen);
    }

    public function testAnEmptyVisibleWhenMeansAlwaysVisible(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'plain',
            'name' => 'Plain',
            'version' => '1.0.0',
            'visible_when' => [],
        ]);

        $this->assertSame([], $manifest->visibleWhen);
    }

    /**
     * The whole reason visible_when replaced a plain boolean: a typo used
     * to produce a module that silently existed nowhere.
     */
    public function testAnUnknownVisibleWhenFlagIsRejected(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('reference_instalation');

        ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => ['reference_instalation'],
        ]);
    }

    public function testAnUnknownVisibleWhenFlagNamesTheKnownSet(): void
    {
        try {
            ModuleManifest::fromArray([
                'id' => 'toolbox',
                'name' => 'Toolbox',
                'version' => '1.0.0',
                'visible_when' => ['nope'],
            ]);
            $this->fail('Expected ModuleException');
        } catch (ModuleException $e) {
            foreach (InstallationProfile::KNOWN_FLAGS as $flag) {
                $this->assertStringContainsString($flag, $e->getMessage());
            }
        }
    }

    public function testANonListVisibleWhenIsRejected(): void
    {
        $this->expectException(ModuleException::class);

        ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => ['flag' => 'statistics_receiver'],
        ]);
    }

    public function testAScalarVisibleWhenIsRejected(): void
    {
        $this->expectException(ModuleException::class);

        ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => 'statistics_receiver',
        ]);
    }

    public function testANonStringVisibleWhenElementIsRejected(): void
    {
        $this->expectException(ModuleException::class);

        ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => [true],
        ]);
    }

    public function testADuplicatedVisibleWhenFlagIsRejected(): void
    {
        $this->expectException(ModuleException::class);

        ModuleManifest::fromArray([
            'id' => 'toolbox',
            'name' => 'Toolbox',
            'version' => '1.0.0',
            'visible_when' => ['local_installation', 'local_installation'],
        ]);
    }
}
