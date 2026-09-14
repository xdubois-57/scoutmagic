<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * Where the application's routes, its menu entries and the controller code
 * behind them can be read as data — the shared half of the two tests in
 * this directory that ask questions about refusals
 * (Tests\Architecture\MenuEntriesAreNotDeadLinksTest and
 * Tests\Architecture\APageRefusalIsReadableTest).
 *
 * **Parsed rather than booted**, the same trade `scripts/authz-support.php`
 * makes and for the same reason: building the real Router means running
 * the composition root, which means serving a request. And parsed
 * **defensively**: every extractor below counts what it found against what
 * is there, so a route written in a shape these patterns do not understand
 * fails the test rather than silently shrinking what it examines. A test
 * that quietly stops looking at half the application is worse than no test
 * at all — it reports green for a question it no longer asks.
 *
 * Not a test class itself (no `Test` suffix), so PHPUnit collects it as a
 * plain autoloaded class rather than as a suite with no assertions.
 */
final class RouteInventory
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function indexSource(): string
    {
        return (string) file_get_contents(self::root() . '/public/index.php');
    }

    /**
     * Every GET route the application registers, from both places it
     * registers them, with what each one is: a page (it declares a
     * breadcrumb — `Core\Http\FrontController` sets `route_breadcrumb`
     * and `route_help` on exactly those, so it is the application's own
     * definition of "somewhere a person lands"), and/or a static menu
     * entry (it declares a non-empty `label`, drawn from `role_min`
     * alone).
     *
     * @return list<array{
     *     path: string,
     *     roleMin: string,
     *     class: string,
     *     action: string,
     *     isPage: bool,
     *     menuLabel: string,
     *     origin: string
     * }>
     */
    public static function getRoutes(): array
    {
        return [...self::coreRoutes(), ...self::moduleRoutes()];
    }

    /**
     * The core GET routes, parsed out of public/index.php's addRoute()
     * calls. A sixth argument means a breadcrumb, which means a page.
     *
     * @return list<array{
     *     path: string, roleMin: string, class: string, action: string,
     *     isPage: bool, menuLabel: string, origin: string
     * }>
     */
    private static function coreRoutes(): array
    {
        $source = self::indexSource();

        $present = preg_match_all("/->addRoute\(\s*'GET'\s*,/", $source);
        $matched = preg_match_all(
            "/->addRoute\(\s*'GET'\s*,\s*'([^']+)'\s*,\s*([^,]+),\s*'([^']*)'\s*,\s*'([a-z]+)'\s*(,\s*\[)?/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        if ($matched !== $present) {
            throw new \RuntimeException(sprintf(
                'public/index.php has %d GET addRoute() calls but only %d parsed. A route written in an '
                . 'unfamiliar shape would be invisible to the refusal tests, so this refuses to answer '
                . 'rather than answer about part of the application.',
                $present,
                $matched
            ));
        }

        $routes = [];
        foreach ($matches as $m) {
            $routes[] = [
                'path' => $m[1],
                'roleMin' => $m[4],
                'class' => trim($m[2]),
                'action' => $m[3],
                'isPage' => isset($m[5]),
                'menuLabel' => '',
                'origin' => 'core',
            ];
        }

        return $routes;
    }

    /**
     * The module GET routes, read from each module.json. Every module is
     * read, enabled or not — a page's refusal must be readable before
     * somebody switches the module on, not after.
     *
     * @return list<array{
     *     path: string, roleMin: string, class: string, action: string,
     *     isPage: bool, menuLabel: string, origin: string
     * }>
     */
    private static function moduleRoutes(): array
    {
        $routes = [];

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                throw new \RuntimeException($manifestPath . ' is not readable JSON.');
            }

            $module = basename(dirname($manifestPath));
            foreach ($manifest['routes'] ?? [] as $route) {
                if (strtoupper((string) ($route['method'] ?? 'GET')) !== 'GET') {
                    continue;
                }
                $routes[] = [
                    'path' => (string) $route['path'],
                    'roleMin' => (string) $route['role_min'],
                    'class' => (string) $route['controller'],
                    'action' => (string) $route['action'],
                    'isPage' => !empty($route['breadcrumb']),
                    'menuLabel' => (string) ($route['label'] ?? ''),
                    'origin' => "module '{$module}'",
                ];
            }
        }

        return $routes;
    }

    /**
     * The core menu entries, read out of public/index.php's addPage()
     * calls — the other half of what a menu offers, alongside the module
     * routes carrying a `label`.
     *
     * Every call is read; the ones whose destination is an expression
     * rather than a literal are then dropped, and they are exactly the
     * per-member dynamic entries (`'/members/' . $member->memberYearId`)
     * whose target is a route of its own, covered as such. Reading every
     * call before dropping any is what keeps a call written in a new
     * shape from disappearing unnoticed.
     *
     * @return list<array{label: string, url: string, roleMin: string}>
     */
    public static function getCoreMenuEntries(): array
    {
        $source = self::indexSource();
        $entries = [];

        foreach (self::callArguments($source, '$menuBuilder->addPage(') as $arguments) {
            if (count($arguments) < 4) {
                throw new \RuntimeException(
                    'An addPage() call in public/index.php has fewer than four arguments — the menu, '
                    . 'the label, the URL and the role are all mandatory, so this is a shape these tests '
                    . 'do not understand rather than an entry to skip.'
                );
            }

            $url = self::stringLiteral($arguments[2]);
            $role = self::stringLiteral($arguments[3]);
            if ($url === null || $role === null) {
                // A computed destination: a per-member entry, whose real
                // target is /members/{id} and is examined as that route.
                continue;
            }

            $entries[] = [
                'label' => self::stringLiteral($arguments[1]) ?? $arguments[1],
                'url' => $url,
                'roleMin' => $role,
            ];
        }

        if ($entries === []) {
            throw new \RuntimeException('No core menu entry found in public/index.php — the extraction broke.');
        }

        return $entries;
    }

    /**
     * The argument lists of every call to $needle in $source, split on
     * top-level commas with brackets and quotes respected.
     *
     * @return list<list<string>> one list of raw argument expressions per call
     */
    private static function callArguments(string $source, string $needle): array
    {
        $calls = [];
        $offset = 0;

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $i = $start + strlen($needle);
            $offset = $i;

            $depth = 1;
            $current = '';
            $arguments = [];
            $quote = null;
            $length = strlen($source);

            for (; $i < $length; $i++) {
                $char = $source[$i];

                if ($quote !== null) {
                    $current .= $char;
                    if ($char === '\\') {
                        $current .= $source[++$i] ?? '';
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    $current .= $char;
                    continue;
                }

                if ($char === '(' || $char === '[') {
                    $depth++;
                } elseif ($char === ')' || $char === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $arguments[] = trim($current);
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    $arguments[] = trim($current);
                    $current = '';
                    continue;
                }

                $current .= $char;
            }

            $calls[] = array_values(array_filter($arguments, static fn(string $a) => $a !== ''));
        }

        return $calls;
    }

    /**
     * The value of a single-quoted or double-quoted PHP string literal,
     * or null when the expression is anything else (a concatenation, a
     * method call, a constant).
     */
    private static function stringLiteral(string $expression): ?string
    {
        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $expression, $m) === 1) {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
        }
        if (preg_match('/^"((?:[^"\\\\$]|\\\\.)*)"$/', $expression, $m) === 1) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], $m[1]);
        }

        return null;
    }

    /**
     * The file a controller class lives in, resolving the short names
     * public/index.php imports (`PageController::class`) through its own
     * `use` statements. Null when the name resolves to nothing on disk.
     */
    public static function controllerFile(string $class): ?string
    {
        $class = ltrim(str_replace('::class', '', trim($class)), '\\');

        if (!str_contains($class, '\\')) {
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', self::indexSource(), $uses);
            foreach ($uses[1] as $imported) {
                if (substr($imported, strrpos($imported, '\\') + 1) === $class) {
                    $class = $imported;
                    break;
                }
            }
        }

        $parts = explode('\\', $class);
        if ($parts[0] === 'Core') {
            $file = self::root() . '/core/' . implode('/', array_slice($parts, 1)) . '.php';
        } elseif ($parts[0] === 'Modules' && count($parts) > 2) {
            $module = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $parts[1]));
            $file = self::root() . '/modules/' . $module . '/src/' . implode('/', array_slice($parts, 2)) . '.php';
        } else {
            return null;
        }

        return is_file($file) ? $file : null;
    }

    /**
     * The source an action can actually run: its own body plus the body
     * of every method of the same class it calls, transitively.
     *
     * That second half is the point. Both refusals this directory tests
     * for are written in a private helper — `requireUnitChief()`,
     * `requireOwnMemberId()`, `isVisible()` — and an action body read on
     * its own says nothing about them.
     *
     * It follows `$this->…()` calls only, so a refusal a *service*
     * returns is outside what this can see. That is a real limit and it
     * is why the authorization matrix (`scripts/authz-support.php`) asks
     * the same question at runtime, where the answer needs no parsing.
     */
    public static function reachableSource(string $file, string $action): string
    {
        $source = self::withoutComments((string) file_get_contents($file));

        $collected = '';
        $seen = [];
        $queue = [$action];

        while ($queue !== []) {
            $method = array_shift($queue);
            if (isset($seen[$method])) {
                continue;
            }
            $seen[$method] = true;

            $body = self::methodBody($source, $method);
            if ($body === null) {
                continue;
            }

            $collected .= $body;
            preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\(/', $body, $calls);
            foreach ($calls[1] as $called) {
                $queue[] = $called;
            }
        }

        return $collected;
    }

    /**
     * The same source with every comment removed.
     *
     * Both tests in this directory look for the word "403" and for
     * `Response` constructions, and this file's own docblocks talk about
     * both at length — a class explaining why it answers 403 must not
     * read as a class that does. Tokenised rather than regexed: a "//"
     * inside a string literal is not a comment.
     */
    private static function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    // Kept as blank lines so that what is left still reads
                    // as the code it is when a failure prints it.
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * One method's body, braces balanced. Null when the class does not
     * declare it — an inherited helper, or a name that only ever appears
     * as a call.
     */
    private static function methodBody(string $source, string $method): ?string
    {
        if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = strpos($source, '{', (int) $m[0][1]);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }
}
