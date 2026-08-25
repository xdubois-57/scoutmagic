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
     * `authz_core_routes()` parses public/index.php rather than booting
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
            \authz_core_routes(),
            'a route public/index.php registers is invisible to the authorization matrix'
        );
    }

    public function testEveryModuleContributesItsRoutes(): void
    {
        $modules = glob(dirname(__DIR__, 2) . '/modules/*/module.json') ?: [];
        $sources = array_unique(array_column(\authz_module_routes(), 'source'));

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
        $groups = \authz_fixtures();
        $unaddressable = [];

        foreach (\authz_routes() as $route) {
            if (\authz_concrete_path($route['path'], $groups) === null) {
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
        $paths = array_column(\authz_routes(), 'path');
        $unused = [];

        foreach (array_keys(\authz_fixtures()) as $prefix) {
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
                    \authz_has_access($have, $need),
                    "disagreement on whether '{$have}' satisfies role_min '{$need}'"
                );
            }
        }
    }

    /**
     * Every route declares a role_min the ladder knows. A typo would
     * otherwise reach `authz_has_access()` at scan time and take the
     * whole run down.
     */
    public function testEveryRouteDeclaresAKnownRoleMin(): void
    {
        $unknown = [];

        foreach (\authz_routes() as $route) {
            if (!in_array($route['role_min'], AUTHZ_ROLES, true)) {
                $unknown[] = $route['path'] . " declares role_min '{$route['role_min']}'";
            }
        }

        $this->assertSame([], $unknown);
    }
}
