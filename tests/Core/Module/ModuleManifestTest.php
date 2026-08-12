<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\ModuleException;
use Core\Module\ModuleManifest;
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
                    'breadcrumb' => ['label' => 'Staffs', 'parents' => ['Espace chefs']],
                ],
            ],
        ]);

        $this->assertSame(
            ['label' => 'Staffs', 'parents' => ['Espace chefs']],
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
                    'breadcrumb' => ['parents' => ['Espace chefs']],
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
                    'breadcrumb' => ['label' => 'Staffs', 'parents' => 'Espace chefs'],
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
     * silently 404ing every "Espace animés" link to a family's own
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
}
