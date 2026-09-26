<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest of the social module: optional, superadmin only, every
 * state change a POST — except the two GETs a browser round trip through
 * Meta needs.
 */
final class ModuleManifestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/modules/social/module.json';
        ModuleManifest::fromFile($path);
        $this->manifest = json_decode((string) file_get_contents($path), true);
    }

    public function testTheModuleIsOptionalAndOffByDefault(): void
    {
        $this->assertFalse($this->manifest['enabled_by_default']);
    }

    public function testEveryRouteIsSuperadminUnderConfiguration(): void
    {
        foreach ($this->manifest['routes'] as $route) {
            $this->assertSame('superadmin', $route['role_min'], $route['path']);
            $this->assertSame('configuration', $route['menu'], $route['path']);
            $this->assertStringStartsWith('/config/reseaux-sociaux', $route['path']);
        }
    }

    public function testEveryStateChangeIsAPost(): void
    {
        foreach ($this->manifest['routes'] as $route) {
            $reads = in_array($route['action'], ['index', 'connect', 'callback'], true);
            $this->assertSame($reads ? 'GET' : 'POST', $route['method'], $route['path'] . ' → ' . $route['action']);
        }
    }
}
