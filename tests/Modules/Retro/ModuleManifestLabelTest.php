<?php

declare(strict_types=1);

namespace Tests\Modules\Retro;

use PHPUnit\Framework\TestCase;

/**
 * The two entries the retro module puts in two different menus, and the
 * wording that keeps them apart: « Rétrospective » (singular) for the
 * Espace chefs d'U configuration page, « Rétrospectives » (plural) for the
 * boards themselves on Espace animateurs.
 *
 * The singular one is no longer a `label` in the manifest. It could not
 * stay one: the page asks for Staff d'U membership on top of its
 * `role_min`, and a manifest label is drawn from `role_min` alone, so the
 * menu offered the link to people the page refused (issue #347). It is
 * contributed per request by Modules\Retro\Menu\RetroMenuHookService
 * instead — which is where this test now reads the wording, since that is
 * where it lives.
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

    public function testTheConfigRouteDeclaresNoMenuLabelOfItsOwn(): void
    {
        $route = $this->routeFor('/config/retro', 'GET');

        $this->assertNotNull($route);
        $this->assertSame(
            '',
            $route['label'],
            'a manifest label puts this entry back in front of every admin role, including the ones the '
            . 'page refuses — issue #347',
        );
        $this->assertSame('Rétrospective', $route['breadcrumb']['label']);
        $this->assertSame(["Espace chefs d'U"], $route['breadcrumb']['parents']);
    }

    public function testTheConfigEntryIsStillWordedInTheSingularWhereItNowLives(): void
    {
        $hook = (string) file_get_contents(
            dirname(__DIR__, 3) . '/modules/retro/src/Menu/RetroMenuHookService.php',
        );

        $this->assertStringContainsString("'Rétrospective',", $hook);
        $this->assertStringNotContainsString("'Rétrospectives',", $hook);
    }

    public function testEspaceChefsRouteLabelIsUnchanged(): void
    {
        $route = $this->routeFor('/retro', 'GET');

        $this->assertNotNull($route);
        $this->assertSame('Rétrospectives', $route['label']);
    }
}
