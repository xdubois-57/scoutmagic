<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Every ancestor a route declares must name a page that really exists,
 * with a role floor no stricter than the page declaring it.
 *
 * Both halves matter, and neither shows up in a rendering test:
 *
 * - A **mistyped or renamed** ancestor path is silent by design —
 *   Core\Http\Router::ancestorTrailFor() drops a step it cannot resolve,
 *   which is the right behaviour for a module the visitor disabled and
 *   exactly the wrong one for a typo. The breadcrumb simply stops
 *   offering the way back up, on a site whose only back affordance is
 *   that breadcrumb (design.md §7.3). This test is what tells the
 *   difference.
 * - An ancestor **stricter than the page under it** is a step nobody who
 *   can read the page can ever see. It is not a leak — the filter drops
 *   it, and the ancestor route keeps enforcing its own role_min — but it
 *   is always a declaration mistake, since a visitor who reached the
 *   detail page came from somewhere.
 *
 * Reads the real manifests, so a new module joins this test by existing.
 */
class BreadcrumbAncestorRoutesTest extends TestCase
{
    private const ROLE_LEVELS = [
        'public' => 0,
        'identified' => 1,
        'intendant' => 2,
        'chief' => 3,
        'admin' => 4,
        'superadmin' => 5,
    ];

    /** @var array<string, string> declared GET path => role_min */
    private array $getRoutes = [];

    /** @var array<int, array{module: string, path: string, label: string, ancestorPath: string, roleMin: string}> */
    private array $declaredAncestors = [];

    protected function setUp(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = ModuleManifest::fromFile($manifestPath);
            foreach ($manifest->routes as $route) {
                if (strtoupper($route['method']) !== 'GET') {
                    continue;
                }
                $this->getRoutes[$route['path']] ??= $route['role_min'];

                foreach ($route['breadcrumb']['ancestors'] ?? [] as $ancestor) {
                    $this->declaredAncestors[] = [
                        'module' => $manifest->id,
                        'path' => $route['path'],
                        'label' => $ancestor['label'],
                        'ancestorPath' => $ancestor['path'],
                        'roleMin' => $route['role_min'],
                    ];
                }
            }
        }
    }

    public function testTheSuiteActuallyFoundDeclaredAncestors(): void
    {
        // A refactor that silently stopped parsing them would otherwise
        // turn every assertion below into a vacuous pass.
        $this->assertGreaterThan(30, count($this->declaredAncestors));
    }

    public function testEveryDeclaredAncestorNamesARealGetRoute(): void
    {
        $unresolved = [];
        foreach ($this->declaredAncestors as $entry) {
            if (!isset($this->getRoutes[$entry['ancestorPath']])) {
                $unresolved[] = "{$entry['module']}: {$entry['path']} → {$entry['ancestorPath']}";
            }
        }

        $this->assertSame([], $unresolved, 'Ancestor paths naming no declared GET route (a typo renders no step at all)');
    }

    public function testNoDeclaredAncestorIsStricterThanThePageItSitsAbove(): void
    {
        $tooStrict = [];
        foreach ($this->declaredAncestors as $entry) {
            $ancestorRole = $this->getRoutes[$entry['ancestorPath']] ?? null;
            if ($ancestorRole === null) {
                continue;
            }
            if (self::ROLE_LEVELS[$ancestorRole] > self::ROLE_LEVELS[$entry['roleMin']]) {
                $tooStrict[] = "{$entry['module']}: {$entry['path']} ({$entry['roleMin']}) → {$entry['ancestorPath']} ({$ancestorRole})";
            }
        }

        $this->assertSame([], $tooStrict, 'Ancestors nobody able to read the page could ever see');
    }

    public function testNoDeclaredAncestorIsAPattern(): void
    {
        $patterns = [];
        foreach ($this->declaredAncestors as $entry) {
            if (str_contains($entry['ancestorPath'], '{')) {
                $patterns[] = "{$entry['module']}: {$entry['ancestorPath']}";
            }
        }

        // ModuleManifest refuses these at load time; this asserts the
        // real manifests are clean rather than the validator's behaviour.
        $this->assertSame([], $patterns);
    }

    /**
     * A route declaring an ancestor also declares a label for itself —
     * otherwise the trail renders the ancestor and then nothing, since
     * the partial only emits the current step inside `route_breadcrumb`.
     */
    public function testEveryRouteDeclaringAnAncestorAlsoDeclaresItsOwnLabel(): void
    {
        $labelless = [];
        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = ModuleManifest::fromFile($manifestPath);
            foreach ($manifest->routes as $route) {
                $breadcrumb = $route['breadcrumb'] ?? null;
                if ($breadcrumb === null || ($breadcrumb['ancestors'] ?? []) === []) {
                    continue;
                }
                if (trim($breadcrumb['label']) === '') {
                    $labelless[] = "{$manifest->id}: {$route['path']}";
                }
            }
        }

        $this->assertSame([], $labelless);
    }
}
