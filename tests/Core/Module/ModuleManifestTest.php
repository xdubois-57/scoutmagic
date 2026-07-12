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
                ['path' => '/test', 'controller' => 'C', 'action' => 'a', 'menu' => 'notre_unite', 'role_min' => 'superadmin'],
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
