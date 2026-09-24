<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Security\Role;
use PHPUnit\Framework\TestCase;

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 2) . '/scripts/authz-support.php';

/**
 * The half of the authorization matrix that needs no server, and
 * therefore runs on every commit rather than only when somebody
 * remembers to run `scripts/dast.sh --profile=standard`.
 *
 * The matrix itself (`scripts/authz-support.php matrix`) replays every
 * route as every role against a provisioned instance. That is the part
 * that finds an over-permissive route. This is the part that makes sure
 * the matrix is still looking at everything — which is the failure mode
 * that would otherwise never announce itself: a route the inventory
 * cannot see, or a route with no fixture, does not make the matrix red.
 * It makes it *shorter*, and a shorter green run reads exactly like a
 * complete one.
 *
 * So: every route accounted for, every route addressable, and the role
 * ladder the matrix reasons with matching the one the application
 * enforces.
 */
class AuthorizationMatrixInventoryTest extends TestCase
{
    /**
     * `authzCoreRoutes()` parses public/index.php rather than booting
     * it, and exits non-zero if the count it matched is not the count
     * present. Calling it here is the assertion: a route written in a
     * shape the regex does not understand fails this test at commit
     * time, not silently at scan time.
     */
    public function testEveryCoreRouteIsAccountedFor(): void
    {
        $present = substr_count(
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php'),
            '->addRoute('
        );

        $this->assertGreaterThan(100, $present, 'did public/index.php stop registering routes?');
        $this->assertCount(
            $present,
            \authzCoreRoutes(),
            'a route public/index.php registers is invisible to the authorization matrix'
        );
    }

    /**
     * The menu half of the inventory, added with the fatal
     * « menu dead link » finding (issue #347): the matrix now treats a
     * refusal differently depending on whether a menu advertised the
     * route, so a `menu` flag that quietly became false everywhere would
     * turn that gate off without turning anything red.
     *
     * Two things are asserted, and the second is the one that matters: a
     * plausible count, and that every URL core draws in a menu is a GET
     * route the matrix will actually hold to the promise.
     */
    public function testTheMatrixKnowsWhichRoutesAMenuAdvertises(): void
    {
        $routes = \authzRoutes();

        foreach ($routes as $route) {
            $this->assertArrayHasKey(
                'menu',
                $route,
                'a route reached the matrix without saying whether a menu advertises it'
            );
        }

        $advertised = array_filter($routes, static fn(array $route) => $route['menu']);
        $this->assertGreaterThan(
            20,
            count($advertised),
            'almost nothing is advertised in a menu any more — either the manifests and public/index.php '
            . 'changed shape, or the menu dead-link gate is now asking its question about nothing'
        );

        $getPaths = [];
        foreach ($routes as $route) {
            if ($route['method'] === 'GET') {
                $getPaths[$route['path']] = $route['menu'];
            }
        }

        foreach (array_keys(\authzCoreMenuUrls(
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php')
        )) as $url) {
            // '#' is the "no linked member" placeholder entry: it leads
            // nowhere, so there is no route to hold to anything.
            if ($url === '#') {
                continue;
            }

            $this->assertArrayHasKey($url, $getPaths, "core draws a menu entry for {$url}, which is no GET route");
            $this->assertTrue($getPaths[$url], "the matrix does not know that {$url} is advertised in a menu");
        }
    }

    public function testEveryModuleContributesItsRoutes(): void
    {
        $modules = glob(dirname(__DIR__, 2) . '/modules/*/module.json') ?: [];
        $sources = array_unique(array_column(\authzModuleRoutes(), 'source'));

        $withRoutes = 0;
        foreach ($modules as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (($manifest['routes'] ?? []) !== []) {
                $withRoutes++;
            }
        }

        $this->assertCount($withRoutes, $sources);
    }

    /**
     * The requirement that keeps the matrix honest as the codebase
     * grows: a new parameterised route arrives with a fixture, or this
     * fails. Nothing is skipped for want of a value to put in the URL.
     */
    public function testEveryRouteCanBeAddressed(): void
    {
        $groups = \authzFixtures();
        $unaddressable = [];

        foreach (\authzRoutes() as $route) {
            if (\authzConcretePath($route['path'], $groups) === null) {
                $unaddressable[] = $route['method'] . ' ' . $route['path'] . '  (' . $route['source'] . ')';
            }
        }

        sort($unaddressable);

        $this->assertSame(
            [],
            $unaddressable,
            "These routes have no fixture in tests/dast/authz-fixtures.json, so the authorization\n"
            . "matrix cannot build a URL for them and would leave them unchecked. Add a group keyed\n"
            . "by the route's prefix up to its first placeholder — the value only has to be\n"
            . "well-formed, not real:\n" . implode("\n", $unaddressable)
        );
    }

    /**
     * A fixture group nothing matches is dead weight that reads as
     * coverage. Checked in this direction too so the file only ever
     * describes routes that exist.
     */
    public function testNoFixtureGroupIsUnused(): void
    {
        $paths = array_column(\authzRoutes(), 'path');
        $unused = [];

        foreach (array_keys(\authzFixtures()) as $prefix) {
            $used = false;
            foreach ($paths as $path) {
                if (str_starts_with($path, $prefix)) {
                    $used = true;
                    break;
                }
            }
            if (!$used) {
                $unused[] = $prefix;
            }
        }

        $this->assertSame([], $unused, 'these fixture groups match no route any more — remove them');
    }

    /**
     * The matrix spells the role ladder out rather than importing the
     * enum, so that reordering `Core\Security\Role` shows up as a
     * disagreement instead of being silently adopted by the thing meant
     * to be checking it. This is where the disagreement surfaces.
     */
    public function testTheLadderTheMatrixUsesIsTheOneTheApplicationEnforces(): void
    {
        $this->assertSame(
            array_map(static fn (Role $role): string => $role->value, Role::cases()),
            AUTHZ_ROLES,
            'Core\Security\Role and the matrix no longer agree on the order of the roles'
        );
    }

    /**
     * And that the ladder means the same thing on both sides — the
     * ordering being equal is not by itself proof that "has access"
     * agrees.
     */
    public function testAccessAgreesWithTheGuardForEveryPairOfRoles(): void
    {
        foreach (AUTHZ_ROLES as $have) {
            foreach (AUTHZ_ROLES as $need) {
                $this->assertSame(
                    Role::fromString($have)->hasAccess(Role::fromString($need)),
                    \authzHasAccess($have, $need),
                    "disagreement on whether '{$have}' satisfies role_min '{$need}'"
                );
            }
        }
    }

    /**
     * Every route declares a role_min the ladder knows. A typo would
     * otherwise reach `authzHasAccess()` at scan time and take the
     * whole run down.
     */
    public function testEveryRouteDeclaresAKnownRoleMin(): void
    {
        $unknown = [];

        foreach (\authzRoutes() as $route) {
            if (!in_array($route['role_min'], AUTHZ_ROLES, true)) {
                $unknown[] = $route['path'] . " declares role_min '{$route['role_min']}'";
            }
        }

        $this->assertSame([], $unknown);
    }

    /**
     * The documentation must claim the WHOLE route table, never a
     * snapshot of it.
     *
     * This started as the opposite test. README and SECURITY.md quoted
     * « 528 routes × 6 roles = 3 168 pairs » while the inventory held
     * 747, so the first version of this test pinned the quoted figures to
     * `authzRoutes()` and made a stale one red. The reasoning was that
     * the number in the prose is part of the change that adds a route.
     *
     * **Measurement disproved it.** Over thirty days, 50 of the 94
     * commits on `main` touched a route declaration — more than half. A
     * count pinned in prose is therefore red on most open pull requests
     * through no fault of their own, and this very pull request watched
     * the figure move twice while it was open (746, then 747, then 750).
     * A tripwire that fires on more than half of all merges is not a
     * guard, it is a tax.
     *
     * So the prose no longer quotes a size at all. What it states is the
     * invariant — every route, every role — which is what a reader
     * auditing coverage actually needs, is true whatever the table's
     * size, and is exactly what the tests above already hold. This test
     * keeps that claim present, and keeps a well-meaning « 750 routes »
     * from being helpfully written back in.
     */
    public function testTheDocumentationClaimsEveryRouteRatherThanACountOfThem(): void
    {
        // Each entry says WHAT it guards, because the five sentences do
        // not all belong to the same section: four are the authorization
        // matrix's, the fifth is the router rule's one layer out
        // (SECURITY.md « The same rule, one layer out: the router »). Both
        // had a count removed, so both are guarded — but a failure must
        // name the right paragraph, not blame the matrix for a sentence
        // the matrix does not own.
        $claims = [
            [
                'README.md',
                "La matrice d'autorisation : **toutes** les routes rejouées sous les six rôles",
                'the authorization matrix, in the DAST profile table',
            ],
            [
                'README.md',
                "rejoue **toutes** les routes que l'application déclare",
                'the authorization matrix, where the profile is explained',
            ],
            [
                'README.md',
                '**toutes** les routes rejouées sous les six rôles, soit un couple (route, rôle) par combinaison',
                "the authorization matrix, in the CI job list",
            ],
            [
                'SECURITY.md',
                'replays **every route as every role**',
                'the authorization matrix',
            ],
            [
                'SECURITY.md',
                'walks **every** route the application registers',
                'the router identifier rule — a different section, and the other place a count was removed',
            ],
        ];

        foreach ($claims as [$file, $claim, $guards]) {
            $this->assertStringContainsString(
                $claim,
                $this->read($file),
                "{$file} no longer claims the whole route table for {$guards}."
            );
        }

        // And no size may creep back: a figure here cannot be kept true
        // (see the docblock), so one that reads as authoritative is worse
        // than none.
        $quoted = [];

        foreach (['README.md', 'SECURITY.md'] as $file) {
            preg_match_all(
                '/[0-9][0-9 ]*\s+(?:routes|couples|pairs|paires)\b/u',
                $this->read($file),
                $found
            );

            foreach ($found[0] as $figure) {
                $quoted[] = "{$file}: « " . trim($figure) . ' »';
            }
        }

        $this->assertSame(
            [],
            $quoted,
            "A route or pair count is quoted again. It cannot be kept true — more than half\n"
            . "of the commits on `main` touch a route declaration — so state the invariant\n"
            . "instead:\n  " . implode("\n  ", $quoted)
        );
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
