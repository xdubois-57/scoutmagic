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
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    /**
     * Pinned deliberately: ModuleManager only re-applies schema.sql when
     * the manifest version is greater than the installed one, so a schema
     * change without a bump is silently a no-op on every already-enabled
     * install (AGENTS.md). Editing schema.sql should break this test — the
     * fix is to bump module.json, which is the whole point.
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.3.0', $this->manifest->version);
    }

    public function testThePostActionsAreDeclaredAsPostRoutesOnly(): void
    {
        $postPaths = [];
        foreach ($this->manifest->routes as $route) {
            if (str_contains($route['path'], '/posts')) {
                $this->assertSame('POST', $route['method'], $route['path']);
                $postPaths[] = $route['path'];
            }
        }

        $this->assertContains('/groups/{id}/posts', $postPaths);
        $this->assertContains('/groups/{id}/posts/{postId}/edit', $postPaths);
        $this->assertContains('/groups/{id}/posts/{postId}/delete', $postPaths);
        $this->assertContains('/groups/{id}/posts/{postId}/pin', $postPaths);
        $this->assertContains('/groups/{id}/posts/{postId}/unpin', $postPaths);
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

    /**
     * Regression guard: every PostController route once declared its
     * controller as "Modules\\\\Groups\\\\Controller\\\\PostController"
     * in the JSON source — valid JSON, but the doubled escaping decodes
     * to a class name containing literal double backslashes, which never
     * matches Core\Http\FrontController's registered-instance lookup
     * (keyed by the real, single-backslash ::class constant) nor
     * class_exists(). ModuleManifest never validates this itself (a
     * malformed but syntactically valid string parses fine), so the
     * break only ever surfaced as a fatal "Class ... not found" the
     * moment a visitor actually hit one of those routes in production —
     * this asserts every declared controller is the genuine, loadable
     * class instead.
     */
    public function testEveryRoutesControllerClassActuallyExists(): void
    {
        foreach ($this->manifest->routes as $route) {
            $this->assertTrue(
                class_exists($route['controller']),
                "{$route['method']} {$route['path']} declares a controller that does not exist: {$route['controller']}"
            );
            $this->assertTrue(
                is_subclass_of($route['controller'], \Core\Http\Controller\AbstractController::class),
                "{$route['controller']} must extend AbstractController"
            );
        }
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
