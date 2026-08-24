<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use PHPUnit\Framework\TestCase;

/**
 * Every class name a module manifest declares must actually exist.
 *
 * The failure this prevents is silent and total. A route whose
 * `controller` does not resolve simply never serves — the page 404s or
 * 500s, and nothing at build time says why. It is also easy to produce
 * without noticing: a manifest is JSON, PHP namespaces are
 * backslash-separated, and one escaping level too many turns
 * "Modules\Camps\Controller\X" into "Modules\\Camps\\Controller\\X",
 * which looks right in a diff and matches nothing at runtime. That
 * happened here, to fourteen routes at once, and no existing test saw it:
 * the module's own RBAC tests build their Router from the class
 * constant, never from the manifest.
 *
 * Scheduled task handlers have the same shape and the same failure mode,
 * except quieter — a task that never resolves is a feature that simply
 * never happens.
 */
final class ManifestClassesExistTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function manifests(): array
    {
        $root = dirname(__DIR__, 3);
        $cases = [];
        foreach (glob($root . '/modules/*/module.json') ?: [] as $path) {
            $cases[basename(dirname($path))] = [$path];
        }

        return $cases;
    }

    /**
     * @dataProvider manifests
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('manifests')]
    public function testEveryDeclaredControllerAndHandlerResolves(string $manifestPath): void
    {
        $data = json_decode((string) file_get_contents($manifestPath), true);
        self::assertIsArray($data, "{$manifestPath} is not valid JSON");

        $missing = [];
        foreach ($data['routes'] ?? [] as $route) {
            $controller = (string) ($route['controller'] ?? '');
            if ($controller !== '' && !class_exists($controller)) {
                $missing[] = "route {$route['method']} {$route['path']} → {$controller}";
            }
        }
        foreach ($data['scheduled_tasks'] ?? [] as $task) {
            $handler = (string) ($task['handler'] ?? '');
            if ($handler !== '' && !class_exists($handler)) {
                $missing[] = "task {$task['key']} → {$handler}";
            }
        }

        self::assertSame(
            [],
            $missing,
            basename(dirname($manifestPath)) . " declares classes that do not exist:\n  "
            . implode("\n  ", $missing)
            . "\n(a doubled backslash in the JSON is the usual cause — it reads correctly and matches nothing)"
        );
    }

    /**
     * @dataProvider manifests
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('manifests')]
    public function testEveryDeclaredActionExistsOnItsController(string $manifestPath): void
    {
        $data = json_decode((string) file_get_contents($manifestPath), true);
        self::assertIsArray($data);

        $missing = [];
        foreach ($data['routes'] ?? [] as $route) {
            $controller = (string) ($route['controller'] ?? '');
            $action = (string) ($route['action'] ?? '');
            if ($controller === '' || $action === '' || !class_exists($controller)) {
                continue;
            }
            if (!method_exists($controller, $action)) {
                $missing[] = "{$route['method']} {$route['path']} → {$controller}::{$action}()";
            }
        }

        self::assertSame(
            [],
            $missing,
            basename(dirname($manifestPath)) . " declares actions its controller does not have:\n  "
            . implode("\n  ", $missing)
        );
    }
}
