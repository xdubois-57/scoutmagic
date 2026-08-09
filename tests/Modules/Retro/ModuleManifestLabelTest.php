<?php

declare(strict_types=1);

namespace Tests\Modules\Retro;

use PHPUnit\Framework\TestCase;

/**
 * modules/retro/module.json's menu label for its Espace chefs d'U config
 * page — renamed from "Rétrospectives — Config" to "Rétrospective". The
 * separate, unrelated "Rétrospectives" (plural) entry on Espace chefs is
 * untouched.
 */
class ModuleManifestLabelTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/modules/retro/module.json';
        $this->manifest = json_decode((string) file_get_contents($path), true);
    }

    private function routeFor(string $path, string $method): ?array
    {
        foreach ($this->manifest['routes'] as $route) {
            if ($route['path'] === $path && $route['method'] === $method) {
                return $route;
            }
        }
        return null;
    }

    public function testConfigRouteLabelIsRetrospectiveSingular(): void
    {
        $route = $this->routeFor('/config/retro', 'GET');

        $this->assertNotNull($route);
        $this->assertSame('Rétrospective', $route['label']);
        $this->assertSame('Rétrospective', $route['breadcrumb']['label']);
        $this->assertSame(["Espace chefs d'U"], $route['breadcrumb']['parents']);
    }

    public function testEspaceChefsRouteLabelIsUnchanged(): void
    {
        $route = $this->routeFor('/retro', 'GET');

        $this->assertNotNull($route);
        $this->assertSame('Rétrospectives', $route['label']);
    }
}
