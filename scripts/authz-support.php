<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * The authorization matrix: every route, replayed as every role, checked
 * against the `role_min` it declares.
 *
 * WHY THIS IS NOT A ZAP PROFILE
 *
 * The original plan put this behind ZAP's "Access Control Testing"
 * add-on, which is not in the `stable` image. Rather than pin a
 * marketplace download into the harness, the check is done here — and it
 * turns out to be the better home anyway, because the question is not a
 * scanning question. There is one right answer per (route, role), the
 * application declares it in `module.json`, and comparing the two is
 * arithmetic rather than heuristics. No payloads, no false positives,
 * and a result that means the same thing on every run.
 *
 * WHAT IT ASSERTS
 *
 * `Core\Security\RbacGuard` runs on every route before the controller
 * (ARCHITECTURE.md §12). So for each (route, role):
 *
 *     allowed(role, route)  ==  role->hasAccess(route.role_min)
 *
 * Two ways to be wrong, and they are not equally bad:
 *
 *   - OVER-PERMISSIVE: a role reaches a route it may not. That is the
 *     security hole, and it fails the run.
 *   - UNDER-PERMISSIVE: a role is refused a route it should reach. Not
 *     a hole, but usually a bug — reported, and not fatal, because a
 *     module may legitimately narrow access further than role_min (the
 *     retro module gates board creation on a setting, for instance).
 *
 * HOW A POST IS TESTED WITHOUT WRITING ANYTHING
 *
 * This is the part worth understanding before changing it. Replaying
 * 500-odd routes as six roles, for real, would rewrite the instance
 * halfway through its own audit — and every later probe would then be
 * measuring a site that the audit itself had changed.
 *
 * So a POST is sent WITHOUT a CSRF token, deliberately. The RBAC guard
 * runs BEFORE the controller, and the CSRF check runs INSIDE it, so the
 * two denials are distinguishable and the authorized case stops at the
 * CSRF wall having changed nothing:
 *
 *     RBAC denial, anonymous      302 → /login
 *     RBAC denial, authenticated  403, text/html (errors/403.html.twig)
 *     CSRF denial, form endpoint  302 → somewhere that is not /login
 *     CSRF denial, JSON endpoint  403, application/json
 *
 * A 403 is therefore read by its Content-Type, never by its status
 * alone, and a 302 by where it points. Nothing is ever written.
 *
 * FIXTURES, AND WHY A MISSING ONE IS AN ERROR
 *
 * Most routes carry a parameter — `/groups/{id}/members`,
 * `/locations/{slug}`. `tests/dast/authz-fixtures.json` supplies a value
 * for each, keyed by route-pattern prefix rather than by placeholder
 * name: `{id}` is a member on `/members/{id}` and a discussion group on
 * `/groups/{id}`, and one global value for `{id}` would paper over the
 * difference.
 *
 * The values need only be WELL-FORMED, not real. The guard runs before
 * the controller, so a route answers the authorization question
 * identically whether the row exists or not — refused is refused, and a
 * 404 for a missing row still means the caller got past the guard.
 *
 * A route with no fixture **fails** rather than being skipped. A skipped
 * route is a route nobody is checking, and a coverage hole that
 * announces itself as a green run is worse than no run at all: the
 * matrix would quietly shrink every time somebody added a route, and
 * would still say "no finding".
 *
 * Subcommands:
 *   routes   the route inventory, as JSON
 *
 * The replay itself — `matrix`, which needs a provisioned instance — is
 * NOT here yet, and `dast.sh --profile=standard` still refuses to run
 * for that reason. What is here is the inventory and the fixtures it
 * will replay, plus the commit-time check that both stay complete;
 * everything above about CSRF and denial-reading is the design that half
 * will follow, written down now because it is the part that is easy to
 * get wrong later.
 */

const AUTHZ_REPO_ROOT = __DIR__ . '/..';

// Defined by tests/Security/AuthorizationMatrixInventoryTest.php before
// it requires this file, exactly as scripts/e2e-support.php is guarded
// by E2E_SUPPORT_TEST: the inventory helpers below are the half worth
// running on every commit, and the command dispatcher must not run when
// they are. The constant above is declared first because `const` at file
// scope is evaluated in order, and main() reads it.
if (!defined('AUTHZ_SUPPORT_TEST')) {
    authz_main($argv);
}

/**
 * Every side effect this file has: reads argv, writes to the streams,
 * exits.
 *
 * @param string[] $argv
 */
function authz_main(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "authz-support.php is a CLI script.\n");
        exit(1);
    }

    $command = $argv[1] ?? '';

    switch ($command) {
        case 'routes':
            $groups = authz_fixtures();
            $out = [];
            foreach (authz_routes() as $route) {
                $out[] = $route + [
                    'placeholders' => authz_placeholders($route['path']),
                    'concrete_path' => authz_concrete_path($route['path'], $groups),
                ];
            }
            echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
            exit(0);

        default:
            fwrite(STDERR, "authz-support.php: unknown subcommand '{$command}'.\n");
            exit(1);
    }
}

/**
 * The role ladder, weakest first. Mirrors Core\Security\Role, and is
 * spelled out here rather than derived from it so that a change to the
 * enum's ordering shows up as a failing matrix rather than as a matrix
 * that silently agrees with whatever the code now says.
 *
 * @var list<string>
 */
const AUTHZ_ROLES = ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'];

/**
 * Whether $role satisfies $roleMin, by position on the ladder above.
 */
function authz_has_access(string $role, string $roleMin): bool
{
    $have = array_search($role, AUTHZ_ROLES, true);
    $need = array_search($roleMin, AUTHZ_ROLES, true);

    if ($have === false || $need === false) {
        fwrite(STDERR, "authz: unknown role '{$role}' or role_min '{$roleMin}'.\n");
        exit(1);
    }

    return $have >= $need;
}

/**
 * Every route the application registers, from both places it registers
 * them.
 *
 * @return list<array{method: string, path: string, role_min: string, source: string}>
 */
function authz_routes(): array
{
    return [...authz_core_routes(), ...authz_module_routes()];
}

/**
 * The core routes, parsed out of public/index.php's `addRoute()` calls.
 *
 * Parsed rather than read from a populated Router because booting the
 * front controller means serving a request. The parse is made safe by
 * counting: if the number of calls matched is not the number of calls
 * present, this exits non-zero rather than returning a short list.
 * `Tests\Security\AuthorizationMatrixInventoryTest` runs that check on
 * every commit, so a route written in a shape this does not understand
 * is caught long before a scan.
 *
 * @return list<array{method: string, path: string, role_min: string, source: string}>
 */
function authz_core_routes(): array
{
    $file = AUTHZ_REPO_ROOT . '/public/index.php';
    $source = (string) file_get_contents($file);

    $present = substr_count($source, '->addRoute(');
    $matched = preg_match_all(
        '/->addRoute\(\s*'
        . "'([A-Z]+)'\s*,\s*"          // method
        . "'([^']+)'\s*,\s*"           // path
        . '[^,]+,\s*'                  // controller class
        . "'[^']+'\s*,\s*"             // action
        . "'([a-z]+)'"                 // role_min
        . '/',
        $source,
        $matches,
        PREG_SET_ORDER
    );

    if ($matched !== $present) {
        fwrite(STDERR, sprintf(
            "authz: public/index.php has %d addRoute() calls but only %d parsed.\n"
            . "       A route written in an unfamiliar shape would be invisible to the matrix,\n"
            . "       so this refuses to run rather than audit an incomplete list.\n",
            $present,
            $matched
        ));
        exit(1);
    }

    $routes = [];
    foreach ($matches as $m) {
        $routes[] = [
            'method' => $m[1],
            'path' => $m[2],
            'role_min' => $m[3],
            'source' => 'core',
        ];
    }

    return $routes;
}

/**
 * The module routes, read from each module.json.
 *
 * Every module is read, enabled or not: a route's authorization must be
 * right before somebody turns the module on, not after.
 *
 * @return list<array{method: string, path: string, role_min: string, source: string}>
 */
function authz_module_routes(): array
{
    $routes = [];

    foreach (glob(AUTHZ_REPO_ROOT . '/modules/*/module.json') ?: [] as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            fwrite(STDERR, "authz: {$manifestPath} is not readable JSON.\n");
            exit(1);
        }

        $module = basename(dirname($manifestPath));

        foreach ($manifest['routes'] ?? [] as $index => $route) {
            foreach (['method', 'path', 'role_min'] as $key) {
                if (!isset($route[$key]) || !is_string($route[$key])) {
                    fwrite(STDERR, "authz: {$module} route #{$index} has no {$key}.\n");
                    exit(1);
                }
            }

            $routes[] = [
                'method' => strtoupper($route['method']),
                'path' => $route['path'],
                'role_min' => $route['role_min'],
                'source' => $module,
            ];
        }
    }

    return $routes;
}

/**
 * The placeholders a path carries, in order.
 *
 * @return list<string>
 */
function authz_placeholders(string $path): array
{
    preg_match_all('/\{([a-zA-Z_]+)\}/', $path, $matches);

    return $matches[1];
}

/**
 * The fixture groups, keyed by route-pattern prefix.
 *
 * @return array<string, array<string, string>>
 */
function authz_fixtures(): array
{
    $path = AUTHZ_REPO_ROOT . '/tests/dast/authz-fixtures.json';
    $decoded = json_decode((string) @file_get_contents($path), true);

    if (!is_array($decoded) || !is_array($decoded['groups'] ?? null)) {
        fwrite(STDERR, "authz: {$path} is missing, unreadable, or has no 'groups'.\n");
        exit(1);
    }

    /** @var array<string, array<string, string>> $groups */
    $groups = $decoded['groups'];

    return $groups;
}

/**
 * The fixture group covering $routePath, or null.
 *
 * Matched on the route PATTERN, not on a concrete path: every group key
 * ends at its own first placeholder, so `/gallery/media/{media_id}/…`
 * matches `/gallery/media/{media_id}` and cannot also match
 * `/gallery/{id}` — the two differ on a literal segment before either
 * placeholder. Two keys can therefore never both prefix one route, and
 * "longest match" is a formality rather than a tie-break.
 *
 * @param array<string, array<string, string>> $groups
 * @return array<string, string>|null
 */
function authz_fixture_group(string $routePath, array $groups): ?array
{
    $best = null;
    $bestLength = -1;

    foreach ($groups as $prefix => $values) {
        if (str_starts_with($routePath, $prefix) && strlen($prefix) > $bestLength) {
            $best = $values;
            $bestLength = strlen($prefix);
        }
    }

    return $best;
}

/**
 * The concrete URL path to request, with every placeholder replaced.
 *
 * Returns null when the route has no fixture group, or when its group
 * does not cover one of its placeholders. Every caller treats that as an
 * error rather than a reason to skip — see this file's header.
 *
 * @param array<string, array<string, string>> $groups
 */
function authz_concrete_path(string $routePath, array $groups): ?string
{
    $placeholders = authz_placeholders($routePath);
    if ($placeholders === []) {
        return $routePath;
    }

    $values = authz_fixture_group($routePath, $groups);
    if ($values === null) {
        return null;
    }

    $concrete = $routePath;
    foreach ($placeholders as $name) {
        if (!isset($values[$name])) {
            return null;
        }
        $concrete = str_replace('{' . $name . '}', rawurlencode($values[$name]), $concrete);
    }

    return $concrete;
}
