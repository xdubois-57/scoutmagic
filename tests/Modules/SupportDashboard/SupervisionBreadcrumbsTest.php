<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use PHPUnit\Framework\TestCase;

/**
 * Every page inside Supervision names the whole way down to itself
 * (issue #356).
 *
 * **`parents` and `ancestors` are not interchangeable**, and this is the
 * test that says so. A `parents` entry names a MENU and opens it, so it
 * only ever renders for one of the five menu labels — « Supervision »
 * declared there is inert grey text, which is how this started. An
 * `ancestors` entry names a real ancestor PAGE by its own route path and
 * renders as a link, which is what a reader on a ticket needs to get back
 * to the other two screens. `/support-dashboard` is static, so the
 * manifest can declare it and no controller-side `breadcrumb_trail` is
 * needed.
 *
 * Two of the three were wrong before this test existed: Tickets and a
 * ticket's detail declared `Configuration` alone and skipped the level they
 * live in.
 */
class SupervisionBreadcrumbsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function supervisionPages(): array
    {
        return [
            'le tableau de bord' => ['/support-dashboard', []],
            'les tickets' => ['/support-dashboard/tickets', ['Supervision']],
            'un ticket' => ['/support-dashboard/tickets/{id}', ['Supervision']],
            'les correspondances' => ['/support-dashboard/correspondances', ['Supervision']],
        ];
    }

    /**
     * @param array<int, string> $expectedAncestors
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('supervisionPages')]
    public function testEveryPageNamesItsAncestorPages(string $path, array $expectedAncestors): void
    {
        $route = self::route($path);

        $this->assertArrayHasKey('breadcrumb', $route, "{$path} declares no breadcrumb at all.");
        $this->assertSame(
            ['Configuration'],
            $route['breadcrumb']['parents'] ?? [],
            "{$path} must hang under the Configuration menu."
        );
        $this->assertSame(
            $expectedAncestors,
            array_column($route['breadcrumb']['ancestors'] ?? [], 'label'),
            "{$path} does not name the ancestor PAGE it lives under, so the level renders as "
            . 'inert text instead of a link back.'
        );
    }

    /**
     * An ancestor is named by its own route path, and the path has to be a
     * page this module actually declares: `Router::ancestorTrailFor()`
     * looks up its `role_min` to decide whether this reader may see the
     * step at all, and a path nothing declares is a link into a 404.
     */
    public function testTheAncestorPathIsARouteThisModuleDeclares(): void
    {
        foreach (self::supervisionPages() as [$path, $expectedAncestors]) {
            foreach (self::route($path)['breadcrumb']['ancestors'] ?? [] as $ancestor) {
                $this->assertNotSame(
                    [],
                    self::route($ancestor['path']),
                    "{$path} names {$ancestor['path']} as an ancestor, which is no route of this module."
                );
            }
        }
    }

    /**
     * The rail and the manifest have to agree: a pill pointing at a page
     * nothing declares is a link into a 404, and a page the rail does not
     * carry is one nobody finds from its siblings.
     */
    public function testTheRailAndTheManifestNameTheSamePages(): void
    {
        $rail = (string) file_get_contents(
            dirname(__DIR__, 3) . '/modules/support_dashboard/views/_nav.html.twig'
        );

        foreach (['/support-dashboard', '/support-dashboard/tickets', '/support-dashboard/correspondances'] as $path) {
            $this->assertStringContainsString("'url': '{$path}'", $rail, "the rail has no pill for {$path}.");
            $this->assertNotSame([], self::route($path), "{$path} is on the rail but in no manifest route.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function route(string $path): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/support_dashboard/module.json'),
            true
        );
        self::assertIsArray($manifest);

        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === $path && $route['method'] === 'GET') {
                return $route;
            }
        }

        return [];
    }
}
