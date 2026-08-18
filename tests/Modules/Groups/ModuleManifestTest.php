<?php

declare(strict_types=1);

namespace Tests\Modules\Groups;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is validated at load time by Core\Module\ModuleManager, so
 * a mistake here takes the module out of every menu with only a badge on
 * the Modules page to show for it. Same precedent as
 * Tests\Modules\Retro\ModuleManifestLabelTest.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/groups/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('groups', $this->manifest->id);
        $this->assertSame('1.0.0', $this->manifest->version);
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    public function testItHardDependsOnGallery(): void
    {
        // Group media live in a gallery album — without it the module has
        // nowhere to put them, so this is a hard dependency (§7.1's
        // `requires`), not the optional §7.5 kind.
        $this->assertSame(['gallery'], $this->manifest->requires);
    }

    public function testEveryRouteIsIdentifiedOrStricterAndInEspaceAnimes(): void
    {
        foreach ($this->manifest->routes as $route) {
            $this->assertSame('espace_animes', $route['menu'], $route['path']);
            $this->assertContains($route['role_min'], ['identified', 'chief'], $route['path']);
        }
    }

    public function testOnlyTheListPageAppearsInTheMenu(): void
    {
        $labelled = array_values(array_filter($this->manifest->routes, fn(array $r) => $r['label'] !== ''));

        $this->assertCount(1, $labelled);
        $this->assertSame('/groups', $labelled[0]['path']);
        $this->assertSame('Groupes', $labelled[0]['label']);
    }

    public function testTheLiteralArchivesRouteIsDeclaredBeforeTheIdWildcard(): void
    {
        // ModuleManifest::validateNoShadowedRoutes() would reject the
        // reverse order outright; this pins the intent so a reorder can't
        // quietly turn /groups/archives into id="archives".
        $paths = array_map(fn(array $r) => $r['method'] . ' ' . $r['path'], $this->manifest->routes);

        $this->assertLessThan(
            array_search('GET /groups/{id}', $paths, true),
            array_search('GET /groups/archives', $paths, true)
        );
    }

    public function testItDeclaresNoOfflinePageNoCookieAndNoStorage(): void
    {
        // Group content is private: it never goes into the offline cache
        // (SECURITY.md §10), and the module stores no file of its own.
        $this->assertSame([], $this->manifest->offline);
        $this->assertSame([], $this->manifest->cookies);
        $this->assertSame([], $this->manifest->storage);
        $this->assertSame([], $this->manifest->settings);
    }
}
