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
     * The documentation quotes the matrix's size in four places. Nothing
     * checked those figures, and all four had gone stale — README said
     * 528 routes twice and 534 once, SECURITY.md said 528 × 6 = 3 168,
     * while the inventory above held 746.
     *
     * A stale figure here is not cosmetic, and it is the same failure
     * this class already exists to catch, one level up. The reader these
     * sentences are written for is auditing coverage: they compare the
     * quoted count to the route table and decide whether the matrix is
     * looking at everything. A count that understates it reads exactly
     * like a hole in the matrix — and a count that overstates it hides
     * one.
     *
     * The figures are therefore derived here and nowhere else: from
     * `authzRoutes()`, from `AUTHZ_ROLES`, and — for the identifier rule
     * SECURITY.md quotes beside them — from the same inventory walked
     * with `Tests\Core\Http\RouterIdentifierParametersTest`'s own
     * predicate, since that is the rule the sentence is about.
     *
     * **Adding a route makes this test red.** That is the intent: the
     * number in the prose is part of the change that added the route,
     * and the failure message says which sentence to edit.
     */
    public function testTheFiguresTheDocumentationQuotesAreTheInventorysOwn(): void
    {
        $routes = count(\authzRoutes());
        $pairs = $routes * count(AUTHZ_ROLES);
        $identifierRoutes = $this->routesCarryingAnIdentifierPlaceholder();

        $claims = [
            ['README.md', "La matrice d'autorisation : les ", ' routes rejouées', $routes],
            ['README.md', 'rejoue les ', ' routes, et compare', $routes],
            ['README.md', '--profile=standard` — les ', ' routes rejouées', $routes],
            ['README.md', ' rôles — ', ' couples (route, rôle)', $pairs],
            ['SECURITY.md', 'the route declares. ', ' routes × 6 roles', $routes],
            ['SECURITY.md', ' × 6 roles = ', ' pairs', $pairs],
            ['SECURITY.md', 'can still reach — ', ' routes, and it also checks', $identifierRoutes],
        ];

        $wrong = [];

        foreach ($claims as [$file, $before, $after, $expected]) {
            $matched = preg_match(
                '/' . preg_quote($before, '/') . '([0-9][0-9 ]*[0-9]|[0-9])' . preg_quote($after, '/') . '/u',
                $this->read($file),
                $found
            );

            $this->assertSame(
                1,
                $matched,
                "{$file} no longer carries the sentence « {$before}… {$after} ». "
                . 'Either restore it or update this claim.'
            );

            $quoted = (int) str_replace(' ', '', $found[1]);
            if ($quoted !== $expected) {
                $wrong[] = "{$file}: « {$before}{$found[1]}{$after} » — the inventory holds {$expected}";
            }
        }

        $this->assertSame(
            [],
            $wrong,
            "Figures the documentation quotes that the inventory contradicts:\n  "
            . implode("\n  ", $wrong)
        );
    }

    /**
     * How many routes carry a placeholder named like a row identifier —
     * the figure SECURITY.md quotes for the router rule. The predicate is
     * Tests\Core\Http\RouterIdentifierParametersTest's, restated rather
     * than imported because that class keeps it private; it is three
     * comparisons, and a drift between the two would make this test red
     * rather than silently agree with nothing.
     */
    private function routesCarryingAnIdentifierPlaceholder(): int
    {
        $paths = [];

        foreach (\authzRoutes() as $route) {
            foreach (\authzPlaceholders($route['path']) as $name) {
                $isIdentifier = $name === 'id'
                    || str_ends_with($name, '_id')
                    || (str_ends_with($name, 'Id') && $name !== 'Id');

                if ($isIdentifier) {
                    $paths[$route['path']] = true;
                }
            }
        }

        return count($paths);
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
