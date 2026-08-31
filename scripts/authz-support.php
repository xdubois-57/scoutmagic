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
 *   routes                        the route inventory, as JSON
 *   matrix <base-url> <report>    the replay itself
 *
 * `matrix` needs a provisioned instance and the role credentials in the
 * environment, so it is run through `scripts/dast.sh --profile=standard`
 * rather than by hand. The inventory half needs neither, and
 * `Tests\Security\AuthorizationMatrixInventoryTest` runs it on every
 * commit — that is what keeps the replay from quietly shrinking.
 */

const AUTHZ_REPO_ROOT = __DIR__ . '/..';

/**
 * The role ladder, weakest first. Mirrors Core\Security\Role, and is
 * spelled out here rather than derived from it so that a change to the
 * enum's ordering shows up as a failing matrix rather than as a matrix
 * that silently agrees with whatever the code now says.
 *
 * @var list<string>
 */
const AUTHZ_ROLES = ['public', 'identified', 'intendant', 'chief', 'admin', 'superadmin'];

// Defined by tests/Security/AuthorizationMatrixInventoryTest.php before
// it requires this file, exactly as scripts/e2e-support.php is guarded
// by E2E_SUPPORT_TEST: the inventory helpers below are the half worth
// running on every commit, and the command dispatcher must not run when
// they are. Both constants above are declared first because `const` at
// file scope is evaluated in order, and main() reaches them.
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

        case 'matrix':
            $baseUrl = $argv[2] ?? '';
            $reportPath = $argv[3] ?? '';
            if ($baseUrl === '' || $reportPath === '') {
                fwrite(STDERR, "Usage: authz-support.php matrix <base-url> <report-path>\n");
                exit(1);
            }
            exit(authz_matrix($baseUrl, $reportPath));

        default:
            fwrite(STDERR, "authz-support.php: unknown subcommand '{$command}'.\n");
            exit(1);
    }
}

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

/**
 * One HTTP exchange with the instance under test, retried once when the
 * instance says nothing at all.
 *
 * **A silent request carries no verdict, and a retry cannot manufacture
 * one.** This gate replays 3,516 (route, role) pairs through one
 * single-worker `php -S`, and one of them once came back with a header
 * block that held no status line — which authz_http_once() reports as
 * "no answer" and which the caller then counts as UNREACHABLE, never as
 * "reached". Retrying it is therefore not a way of getting past a
 * refusal: a route that reliably kills the connection stays silent on
 * both attempts and still fails the run. What the retry buys is that a
 * single dropped connection out of thousands no longer decides a
 * security gate on a question it never asked.
 *
 * Replaying the POST costs nothing either: every POST here is sent with
 * `{}` and no CSRF token, so it is refused before it can write (see this
 * file's own header).
 *
 * @return array{status: int, content_type: string, location: string, cookie: ?string}|null
 *         null on a transport failure (including a timeout), on both tries
 */
function authz_http(string $url, string $method, ?string $cookie, ?string $jsonBody = null, int $timeout = 15): ?array
{
    $response = authz_http_once($url, $method, $cookie, $jsonBody, $timeout);

    return $response ?? authz_http_once($url, $method, $cookie, $jsonBody, $timeout);
}

/**
 * The exchange itself.
 *
 * Streams rather than cURL, and no proxy, for the same reasons
 * scripts/dast-support.php gives: everything here is on loopback, and
 * some environments export HTTPS_PROXY, which would simply hang.
 *
 * @return array{status: int, content_type: string, location: string, cookie: ?string}|null
 *         null on a transport failure (including a timeout)
 */
function authz_http_once(string $url, string $method, ?string $cookie, ?string $jsonBody = null, int $timeout = 15): ?array
{
    $headers = ["Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"];
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $jsonBody ?? '',
            'timeout' => $timeout,
            // The status IS the answer here — a 403 must come back as a
            // response to read, not as a warning and `false`.
            'ignore_errors' => true,
            // Never followed: where a redirect POINTS is half the
            // verdict. A 302 to /login is the guard turning an anonymous
            // caller away; a 302 anywhere else is a controller that was
            // reached and did something else.
            'follow_location' => 0,
        ],
        'ssl' => [
            // Same reasoning as dast-support.php: the instance serves a
            // certificate generated for this run and trusted by nothing,
            // over loopback.
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false && !isset($http_response_header)) {
        return null;
    }

    $status = 0;
    $contentType = '';
    $location = '';
    $setCookie = null;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            // A redirect chain would leave several status lines; the last
            // one is this response's, and follow_location is off anyway.
            $status = (int) $m[1];
            continue;
        }
        if (preg_match('/^Content-Type:\s*(.+)$/i', $header, $m)) {
            $contentType = strtolower(trim($m[1]));
            continue;
        }
        if (preg_match('/^Location:\s*(.+)$/i', $header, $m)) {
            $location = trim($m[1]);
            continue;
        }
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) {
            $setCookie = trim($m[1]);
        }
    }

    // No status line means no answer, exactly like a refused connection.
    // It used to fall through as status 0, which authz_was_denied() reads
    // as "not denied" and the caller then prints as REACHED BY A ROLE
    // THAT MAY NOT — the loudest line this tool has, for a route that had
    // in fact said nothing at all. A verdict is a status code or it is
    // not a verdict.
    if ($status === 0) {
        return null;
    }

    return ['status' => $status, 'content_type' => $contentType, 'location' => $location, 'cookie' => $setCookie];
}

/**
 * Sign in over real HTTP and return the session cookie, or null.
 *
 * Two exchanges, because the CSRF token is session-bound: GET /login to
 * be given a session and the token the page carries in its
 * `<meta name="csrf-token">` (base.html.twig), then POST the credentials
 * as JSON exactly as public/assets/js/auth.js does — `rgpd_consent`
 * included, since AuthController::hasRgpdConsent() refuses the login
 * without it and would otherwise fail here for a reason that looks like
 * a wrong password.
 */
function authz_login(string $baseUrl, string $email, string $password): ?string
{
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $page = @file_get_contents($baseUrl . '/login', false, $context);
    if ($page === false) {
        return null;
    }

    $cookie = null;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) {
            $cookie = trim($m[1]);
        }
    }
    if ($cookie === null || preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page, $m) !== 1) {
        return null;
    }

    $response = authz_http($baseUrl . '/login/password', 'POST', $cookie, json_encode([
        'email' => $email,
        'password' => $password,
        'rgpd_consent' => true,
        '_csrf_token' => $m[1],
    ]) ?: '');

    if ($response === null) {
        return null;
    }

    // Signing in regenerates the session id, so the cookie to keep is the
    // one this response set — carrying the pre-login one forward would
    // leave every later request anonymous, and the matrix would report
    // every authenticated role as denied everywhere.
    return $response['cookie'] ?? $cookie;
}

/**
 * Did the RBAC guard turn this response away?
 *
 * Read from the shape of the response rather than from its status alone,
 * because a 403 has two possible authors and they mean opposite things
 * here — see this file's header. The guard's own two answers
 * (Core\Security\RbacGuard::enforce) are a 302 to /login for an
 * anonymous caller and a 403 rendered as the site's HTML error page for
 * an authenticated one. A CSRF refusal is a 403 carrying JSON, or a 302
 * pointing somewhere that is not /login.
 *
 * @param array{status: int, content_type: string, location: string, cookie: ?string} $response
 */
function authz_was_denied(array $response): bool
{
    if ($response['status'] === 302) {
        return preg_match('#(^|//[^/]*)/login(\?|$)#', $response['location']) === 1;
    }

    return $response['status'] === 403 && !str_contains($response['content_type'], 'json');
}

/**
 * The credentials for each role, as scripts/dast.sh exports them.
 *
 * `public` is absent on purpose: it is the anonymous caller, and it has
 * no credentials by definition.
 *
 * @return array<string, array{email: string, password: string}>
 */
function authz_credentials(): array
{
    $prefixes = [
        'identified' => 'E2E_MEMBER',
        'intendant' => 'E2E_INTENDANT',
        'chief' => 'E2E_CHIEF',
        'admin' => 'E2E_UNIT_ADMIN',
        'superadmin' => 'E2E_ADMIN',
    ];

    $credentials = [];
    foreach ($prefixes as $role => $prefix) {
        $email = (string) getenv($prefix . '_EMAIL');
        $password = (string) getenv($prefix . '_PASSWORD');
        if ($email === '' || $password === '') {
            fwrite(STDERR, "authz: {$prefix}_EMAIL/{$prefix}_PASSWORD are not set — run this through scripts/dast.sh.\n");
            exit(1);
        }
        $credentials[$role] = ['email' => $email, 'password' => $password];
    }

    return $credentials;
}

/**
 * Sign in as each role and prove the session really carries it.
 *
 * The proof matters more than the login. An account whose role failed to
 * resolve still signs in perfectly well — it is simply `identified` —
 * and the matrix would then find every admin route correctly refusing
 * it, report a clean run, and have checked nothing at all. So each
 * session is asked for a page only its own role may reach, and the run
 * stops if the answer is no.
 *
 * @return array<string, ?string> role => session cookie (null for public)
 */
function authz_sessions(string $baseUrl): array
{
    // One route per role that the role below it may NOT reach. Chosen
    // from the inventory rather than invented: each is a real page whose
    // role_min is exactly this role.
    $proof = [
        'identified' => '/account',
        'intendant' => '/chefs/staffs',
        'chief' => '/chefs/calendar',
        'admin' => '/admin/journal',
        'superadmin' => '/config/settings',
    ];

    $sessions = ['public' => null];

    foreach (authz_credentials() as $role => $credentials) {
        $cookie = authz_login($baseUrl, $credentials['email'], $credentials['password']);
        if ($cookie === null) {
            fwrite(STDERR, "authz: could not sign in as '{$role}' ({$credentials['email']}).\n");
            exit(1);
        }

        $response = authz_http($baseUrl . $proof[$role], 'GET', $cookie);
        if ($response === null || authz_was_denied($response)) {
            fwrite(STDERR, sprintf(
                "authz: signed in as '%s' but %s was refused — the session does not carry that role.\n"
                . "       A matrix run from here would check nothing and still come back green.\n",
                $role,
                $proof[$role]
            ));
            exit(1);
        }

        $sessions[$role] = $cookie;
    }

    return $sessions;
}

/**
 * Replay every route as every role.
 *
 * @return int the process exit code
 */
function authz_matrix(string $baseUrl, string $reportPath): int
{
    $baseUrl = rtrim($baseUrl, '/');
    $groups = authz_fixtures();
    $routes = authz_routes();

    echo "authz: signing in as each role.\n";
    $sessions = authz_sessions($baseUrl);

    echo 'authz: replaying ' . count($routes) . ' routes as ' . count($sessions) . " roles.\n";

    $overPermissive = [];
    $underPermissive = [];
    $unreachable = [];
    $checked = 0;

    foreach ($routes as $route) {
        $path = authz_concrete_path($route['path'], $groups);
        if ($path === null) {
            // Cannot happen with a green AuthorizationMatrixInventoryTest,
            // and is fatal rather than skipped if it ever does: a route
            // silently left out is the one failure this whole design is
            // built to prevent.
            fwrite(STDERR, "authz: no fixture for {$route['method']} {$route['path']}.\n");
            return 1;
        }

        foreach ($sessions as $role => $cookie) {
            $response = authz_http(
                $baseUrl . $path,
                $route['method'],
                $cookie,
                $route['method'] === 'GET' ? null : '{}'
            );

            if ($response === null) {
                $unreachable[] = "{$route['method']} {$route['path']} as {$role}";
                continue;
            }

            $checked++;
            $reached = !authz_was_denied($response);
            $shouldReach = authz_has_access($role, $route['role_min']);

            if ($reached && !$shouldReach) {
                $overPermissive[] = sprintf(
                    '%s %s — reached by %s (role_min: %s, HTTP %d)',
                    $route['method'],
                    $route['path'],
                    $role,
                    $route['role_min'],
                    $response['status']
                );
            } elseif (!$reached && $shouldReach) {
                $underPermissive[] = sprintf(
                    '%s %s — refused to %s (role_min: %s, HTTP %d)',
                    $route['method'],
                    $route['path'],
                    $role,
                    $route['role_min'],
                    $response['status']
                );
            }
        }
    }

    $report = [
        'checked' => $checked,
        'routes' => count($routes),
        'roles' => array_keys($sessions),
        'over_permissive' => $overPermissive,
        'under_permissive' => $underPermissive,
        'unreachable' => $unreachable,
    ];
    @file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    echo "\nauthz: {$checked} (route, role) pairs checked.\n";

    // A transport failure is not a verdict either way, so it is neither
    // counted nor quietly dropped: an unanswered route is a route nobody
    // checked, and it says so.
    if ($unreachable !== []) {
        echo "\nauthz: " . count($unreachable) . " pair(s) got no answer at all — NOT CHECKED:\n";
        foreach (array_slice($unreachable, 0, 20) as $line) {
            echo "  - {$line}\n";
        }
        if (count($unreachable) > 20) {
            echo '  … and ' . (count($unreachable) - 20) . " more (see the report).\n";
        }
    }

    // Reported, never fatal. A module may narrow access further than its
    // route declares — the retro module gates board creation on a setting
    // of its own — so this is a list to read, not a wall.
    if ($underPermissive !== []) {
        echo "\nauthz: " . count($underPermissive) . " route(s) refused a role that role_min admits:\n";
        foreach (array_slice($underPermissive, 0, 20) as $line) {
            echo "  - {$line}\n";
        }
        if (count($underPermissive) > 20) {
            echo '  … and ' . (count($underPermissive) - 20) . " more (see the report).\n";
        }
    }

    if ($overPermissive !== []) {
        echo "\nauthz: " . count($overPermissive) . " ROUTE(S) REACHED BY A ROLE THAT MAY NOT:\n";
        foreach ($overPermissive as $line) {
            echo "  - {$line}\n";
        }
        echo "\nauthz: FAILED. Report: {$reportPath}\n";

        return 1;
    }

    if ($unreachable !== []) {
        echo "\nauthz: no route is over-permissive, but some got no answer — treat this as a failed run.\n";

        return 1;
    }

    if ($underPermissive === []) {
        echo "\nauthz: every route answers exactly the roles its role_min admits.\n";
    } else {
        echo "\nauthz: no route is reachable by a role that may not reach it.\n";
        echo '       (' . count($underPermissive) . " refusals above are stricter than role_min, which is\n";
        echo "       defence in depth rather than a fault — a member may only edit their OWN record,\n";
        echo "       a file goes through FileAccessGuard. Read them; do not assume them.)\n";
    }

    return 0;
}
