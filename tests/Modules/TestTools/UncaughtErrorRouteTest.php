<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Http\Request;
use Core\Module\ModuleManifest;
use Modules\TestTools\Controller\TestToolsController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `/test-tools/uncaught-error` — the route that crashes on purpose.
 *
 * It is the only way to exercise Core\Http\ErrorHandler's whole chain
 * against a running installation (controller → ErrorHandler::guard() →
 * error_log → the journal entry at level `error` → the generic 500 page),
 * which tests/e2e/specs/journal-uncaught-error.spec.js does.
 *
 * A route whose entire purpose is to fault has exactly one thing that must
 * never slip: it may not exist on a deploying unit's installation. That is
 * a property of the MODULE — `visible_when` keeps ModuleManager from ever
 * discovering it (ARCHITECTURE.md §8.49, and ModuleVisibilityTest pins the
 * flags themselves) — so what is pinned here is that the route lives in
 * that module and nowhere else, and stays superadmin-only.
 */
class UncaughtErrorRouteTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->manifest = ModuleManifest::fromFile(
            dirname(__DIR__, 3) . '/modules/test_tools/module.json'
        );
    }

    public function testTheManifestDeclaresTheRouteAsSuperadminOnly(): void
    {
        $route = null;
        foreach ($this->manifest->routes as $candidate) {
            if ($candidate['path'] === '/test-tools/uncaught-error') {
                $route = $candidate;
            }
        }

        $this->assertNotNull($route, 'the provoked-error route is gone from the manifest');
        $this->assertSame('GET', $route['method']);
        $this->assertSame('superadmin', $route['role_min']);
        $this->assertSame(TestToolsController::class, $route['controller']);
        $this->assertSame('provokeUncaughtError', $route['action']);
    }

    /**
     * The gated module is the whole containment: no core route, and no
     * other module's route, may reach this action.
     */
    public function testNoOtherManifestDeclaresTheRoute(): void
    {
        $root = dirname(__DIR__, 3);

        $declarations = [];
        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = ModuleManifest::fromFile($manifestPath);
            foreach ($manifest->routes as $route) {
                if ($route['path'] === '/test-tools/uncaught-error') {
                    $declarations[] = $manifest->id;
                }
            }
        }

        $this->assertSame(['test_tools'], $declarations);
        // Core's routes are declared by hand in the composition root.
        $this->assertStringNotContainsString(
            'uncaught-error',
            (string) file_get_contents($root . '/public/index.php')
        );
    }

    public function testTheActionThrows(): void
    {
        $controller = new TestToolsController(new Environment(new ArrayLoader([])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Erreur provoquée volontairement depuis les outils de test.');

        $controller->provokeUncaughtError(new Request('GET', '/test-tools/uncaught-error', [], [], [], []), []);
    }
}
