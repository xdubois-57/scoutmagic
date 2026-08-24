<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The help chantier's closing invariant: no page reachable at a given
 * role is without help FOR THAT ROLE — written as a test, not as a review
 * pass, so a page added later without a topic fails CI instead of
 * shipping undocumented (AGENTS.md checklists ask for the topic in the
 * same change).
 *
 * **A page is a route that declares a breadcrumb.** That is the
 * application's own definition, not one invented here: Core\Http\
 * FrontController sets `route_breadcrumb` and `route_help` side by side
 * on exactly those routes, so anything carrying one is somewhere a person
 * lands and can press the help button. Endpoints declare none — the JSON
 * polls, the `.ics` feeds, the file downloads, the exports — and are
 * correctly out of scope.
 *
 * It was narrower than that, and the gap was most of the application:
 * only pages a MENU links were checked, so everything reached by clicking
 * through went unexamined. Twenty-one pages had no topic at all, among
 * them the two an outsider is sent to — the tracking page a family
 * follows an enrolment on, and the unsubscribe page at the foot of every
 * mailing — which are the pages least able to explain themselves and the
 * most likely to be read by someone who has never seen the site.
 *
 * Dynamic per-member entries are not pages of their own (their target,
 * /members/{id}, is covered by its own topics). Modules gated by
 * `visible_when` are skipped: they never exist on a deploying unit's
 * installation (ARCHITECTURE.md §8.49), which is the audience the help is
 * written for.
 *
 * Coverage means: at least one shipped topic whose `paths` match the
 * page's URL and whose role_min does not exceed the page's own — so the
 * lowest role that can open the page also sees its help.
 */
final class HelpMenuCoverageTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function shippedRegistry(): HelpRegistry
    {
        $registry = new HelpRegistry(self::root() . '/docs/help');
        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $moduleDir = dirname($manifestPath);
            $data = json_decode((string) file_get_contents($manifestPath), true);
            $helpDirName = is_array($data) && isset($data['help']['dir']) && is_string($data['help']['dir'])
                ? $data['help']['dir']
                : 'help';
            if (is_dir($moduleDir . '/' . $helpDirName)) {
                $registry->registerModuleTopics(basename($moduleDir), $moduleDir . '/' . $helpDirName);
            }
        }

        return $registry;
    }

    /**
     * Every (url, role_min) pair the menus offer.
     *
     * @return array<int, array{url: string, role: Role, origin: string}>
     */
    private static function pageRoutes(): array
    {
        $pages = self::menuPages();

        // Core routes that render a page: Router::addRoute()'s sixth
        // argument is the breadcrumb, and a route that has one is a page
        // by construction (Core\Http\FrontController sets route_breadcrumb
        // and route_help side by side on exactly these). Endpoints — the
        // JSON polls, the file downloads, the .ics feeds — pass five
        // arguments and are correctly absent.
        $indexSource = (string) file_get_contents(self::root() . '/public/index.php');
        $count = preg_match_all(
            "/->addRoute\\(\\s*'GET'\\s*,\\s*'([^']+)'\\s*,[^,]+,\\s*'[^']*'\\s*,\\s*'([^']+)'\\s*,\\s*\\[/",
            $indexSource,
            $coreMatches,
            PREG_SET_ORDER
        );
        self::assertGreaterThan(0, $count, 'No core page route found in public/index.php — the extraction regex broke.');
        foreach ($coreMatches as $match) {
            $pages[] = [
                'url' => $match[1],
                'role' => Role::from($match[2]),
                'origin' => "core page route '{$match[1]}' (public/index.php)",
            ];
        }

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data) || !empty($data['visible_when'])) {
                continue;
            }
            foreach ($data['routes'] ?? [] as $route) {
                if (!is_array($route) || empty($route['breadcrumb'])) {
                    continue;
                }
                if (strtoupper((string) ($route['method'] ?? 'GET')) !== 'GET') {
                    continue;
                }
                $pages[] = [
                    'url' => (string) $route['path'],
                    'role' => Role::from((string) $route['role_min']),
                    'origin' => "module '" . basename(dirname($manifestPath)) . "' page '{$route['path']}'",
                ];
            }
        }

        // Same url at the same role twice (a menu entry is also a route
        // with a breadcrumb) is one page to a reader.
        $unique = [];
        foreach ($pages as $page) {
            $unique[$page['url'] . '@' . $page['role']->value] = $page;
        }

        return array_values($unique);
    }

    /**
     * @return array<int, array{url: string, role: Role, origin: string}>
     */
    private static function menuPages(): array
    {
        $pages = [];

        $indexSource = (string) file_get_contents(self::root() . '/public/index.php');
        $count = preg_match_all(
            "/\\\$menuBuilder->addPage\\(\\s*MenuBuilder::MENU_[A-Z_]+,\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*'([^']*)',\\s*'([^']*)'/",
            $indexSource,
            $m,
            PREG_SET_ORDER
        );
        self::assertGreaterThan(0, $count, 'No core menu page found in public/index.php — the extraction regex broke.');
        foreach ($m as $match) {
            $pages[] = [
                'url' => $match[2],
                'role' => Role::from($match[3]),
                'origin' => "core menu page '{$match[1]}' (public/index.php)",
            ];
        }

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data)) {
                continue;
            }
            // A visible_when module never exists on a deploying unit's
            // installation — its pages are out of the help's audience.
            if (!empty($data['visible_when'])) {
                continue;
            }
            foreach ($data['routes'] ?? [] as $route) {
                if (!is_array($route) || ($route['label'] ?? '') === '') {
                    continue;
                }
                $method = strtoupper((string) ($route['method'] ?? 'GET'));
                if ($method !== 'GET') {
                    continue;
                }
                $pages[] = [
                    'url' => (string) $route['path'],
                    'role' => Role::from((string) $route['role_min']),
                    'origin' => "module '" . basename(dirname($manifestPath)) . "' menu page '{$route['label']}'",
                ];
            }
        }

        return $pages;
    }

    /**
     * A route pattern as a URL the matcher can be asked about: `{id}` and
     * friends stand for one concrete segment, which is exactly what a
     * `*` in a topic's `paths` matches.
     */
    private static function concreteUrl(string $routePattern): string
    {
        return (string) preg_replace('/\{[a-zA-Z_]+\}/', 'x', $routePattern);
    }

    /**
     * The matcher is Core\Help\HelpService itself, not a restatement of
     * it. This test used to carry its own copy of the exact/child rules —
     * which silently stopped being the truth when `paths` grew its third
     * form, so every page named by a segment pattern read as uncovered.
     * A coverage test that disagrees with the code it covers is worse
     * than no coverage test.
     */
    public function testEveryPageHasHelpVisibleAtItsOwnRoleFloor(): void
    {
        $service = new HelpService(self::shippedRegistry());
        $missing = [];

        foreach (self::pageRoutes() as $page) {
            if ($service->findForPath(self::concreteUrl($page['url']), $page['role']) === []) {
                $missing[] = "{$page['url']} ({$page['origin']}, role_min {$page['role']->value})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Pages without a help topic visible at their own role floor:\n  "
            . implode("\n  ", $missing)
            . "\nEvery end-user page needs a topic, existing or new (AGENTS.md checklists, design.md §7.11)."
        );
    }

    /**
     * The widening itself, pinned: this test used to look only at pages a
     * menu links, so the deep ones — a booking's calendar, a stay's
     * documents, the tracking page a renter is sent — could ship with no
     * topic and nothing said. Those are most of the application.
     */
    public function testCoverageLooksBeyondThePagesAMenuLinks(): void
    {
        $this->assertGreaterThan(
            count(self::menuPages()),
            count(self::pageRoutes()),
            'Page coverage must include routes reached by clicking through, not only menu entries.'
        );
    }
}
