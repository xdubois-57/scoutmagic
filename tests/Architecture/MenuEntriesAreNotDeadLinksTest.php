<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Support\RouteInventory;

/**
 * A menu entry is a promise: click this, and the page opens. Issue #347
 * is what happens when the promise is made by one rule and kept by
 * another.
 *
 * « Rétrospective » was declared as a `label` in `modules/retro/
 * module.json`, so the menu drew it from the route's `role_min: admin`
 * alone. `RetroConfigController` then asked a narrower question —
 * `MemberService::isUnitChief()`, membership of the Staff d'U section for
 * the authorization year — which an « Administrateur du site » who is not
 * himself a chef d'U does not satisfy. The site therefore offered him a
 * link and answered it with the word "Forbidden". `/config/banner` had
 * the same hole, unreported only because nobody had clicked it.
 *
 * Neither controller was wrong to ask its narrower question, and neither
 * `role_min` was wrong either. What was wrong is that the menu could only
 * ever see one of the two. So the rule this file pins is:
 *
 *     a static menu entry — one drawn from `role_min` and nothing else —
 *     must point at an action that refuses nobody `role_min` admits.
 *
 * There are two ways to keep it, and a failure here should say which one
 * the change wants. Either the controller's extra check is the wrong one
 * and goes, or the entry stops being static: `Core\Module\MenuEntryProvider`
 * contributes it per request, asking exactly the question the controller
 * will ask (Modules\Retro\Menu\RetroMenuHookService is the worked
 * example). What is not an option is leaving the menu to guess.
 *
 * **This is not a security test and a green run proves no page safe.**
 * Menu visibility is a convenience and never a boundary (SECURITY.md §3,
 * ARCHITECTURE.md §12); every route keeps its own `role_min` and every
 * controller keeps its own check. What is tested here is whether the site
 * tells the truth about where it will let somebody in.
 *
 * **What it cannot see**, and the reason `scripts/authz-support.php` asks
 * the same question at runtime over every (route, role) pair: a refusal
 * that a service rather than the controller decides, and the predicate
 * inside a MenuEntryProvider — nothing static can prove that a hook's
 * condition and a controller's condition are the same condition.
 */
final class MenuEntriesAreNotDeadLinksTest extends TestCase
{
    /**
     * The floor. Everything below reads a parsed inventory, and an
     * inventory that came back empty would agree with anything.
     */
    public function testTheInventoryItReadsIsNotEmpty(): void
    {
        $routes = RouteInventory::getRoutes();
        $menuRoutes = array_filter($routes, static fn(array $route) => $route['menuLabel'] !== '');

        $this->assertGreaterThan(100, count($routes), 'The GET route inventory is implausibly short.');
        $this->assertGreaterThan(
            10,
            count($menuRoutes),
            'No module declares a menu label any more — either the manifests changed shape, or this test '
            . 'is now asking its question about nothing.',
        );
        $this->assertGreaterThan(
            10,
            count(RouteInventory::getCoreMenuEntries()),
            'No core menu entry was found in public/index.php.',
        );
    }

    /**
     * THE RULE ITSELF, for the entries a module declares in its manifest.
     */
    public function testNoModuleMenuEntryPointsAtAnActionThatRefuses(): void
    {
        $offenders = [];

        foreach (RouteInventory::getRoutes() as $route) {
            if ($route['menuLabel'] === '') {
                continue;
            }

            $refusal = self::refusalIn($route['class'], $route['action']);
            if ($refusal !== null) {
                $offenders[] = sprintf(
                    '%s: « %s » → %s (role_min: %s) — %s',
                    $route['origin'],
                    $route['menuLabel'],
                    $route['path'],
                    $route['roleMin'],
                    $refusal,
                );
            }
        }

        $this->assertSame([], $offenders, self::explanation());
    }

    /**
     * The same rule for the entries core registers itself, resolved
     * through the GET route each one points at.
     */
    public function testNoCoreMenuEntryPointsAtAnActionThatRefuses(): void
    {
        $byPath = [];
        foreach (RouteInventory::getRoutes() as $route) {
            $byPath[$route['path']] = $route;
        }

        $offenders = [];
        foreach (RouteInventory::getCoreMenuEntries() as $entry) {
            // '#' and friends: an entry that is its own placeholder (the
            // "no linked member" line of Espace membres) leads nowhere and
            // can refuse nobody.
            if (!isset($byPath[$entry['url']])) {
                continue;
            }

            $route = $byPath[$entry['url']];
            $refusal = self::refusalIn($route['class'], $route['action']);
            if ($refusal !== null) {
                $offenders[] = sprintf(
                    'core menu entry « %s » → %s (role_min: %s) — %s',
                    $entry['label'],
                    $entry['url'],
                    $route['roleMin'],
                    $refusal,
                );
            }
        }

        $this->assertSame([], $offenders, self::explanation());
    }

    /**
     * The two entries the fix moved out of their manifests, named here so
     * that putting a `label` back — the one-line change that reintroduces
     * issue #347 — fails with the issue number rather than only with the
     * general rule above.
     */
    public function testTheTwoEntriesIssue347WasAboutAreStillContributedPerRequest(): void
    {
        foreach (['retro' => 'Rétrospective', 'banner' => 'Bannière'] as $module => $label) {
            $manifest = json_decode(
                (string) file_get_contents(RouteInventory::root() . "/modules/{$module}/module.json"),
                true,
            );
            self::assertIsArray($manifest);

            foreach ($manifest['routes'] as $route) {
                if ($route['path'] === "/config/{$module}" && strtoupper($route['method']) === 'GET') {
                    $this->assertSame(
                        '',
                        $route['label'],
                        "modules/{$module}/module.json declares a static menu label for /config/{$module} "
                        . 'again. That page is not open to everybody its role_min admits — it asks for Staff '
                        . "d'U membership — so a label drawn from role_min alone puts the link back in front "
                        . 'of people it will refuse (issue #347).',
                    );
                }
            }

            $hook = RouteInventory::root() . '/modules/' . $module . '/src/Menu/'
                . ucfirst($module) . 'MenuHookService.php';
            $this->assertFileExists(
                $hook,
                "The « {$label} » entry has to come from somewhere: without its MenuEntryProvider, the page "
                . 'is reachable only by typing its address.',
            );
            $this->assertStringContainsString(
                'extends UnitChiefMenuHook',
                (string) file_get_contents($hook),
                'The hook no longer inherits the condition, so nothing narrows the entry it contributes '
                . 'and it is offered to every admin role again.',
            );
        }

        $this->assertStringContainsString(
            'isUnitChief',
            (string) file_get_contents(RouteInventory::root() . '/core/Module/UnitChiefMenuHook.php'),
            'Core\\Module\\UnitChiefMenuHook is where both entries above get their condition — one copy '
            . 'of it, since the two modules that needed it first held one each. Without that call it '
            . 'contributes them unconditionally, which is issue #347 with more files.',
        );
    }

    /**
     * What an action can refuse with, or null when it refuses nothing.
     *
     * Read from the source an action can actually run — its own body plus
     * the helpers it calls, which is where every one of these refusals is
     * written. Comments are already gone, so a docblock describing a 403
     * cannot be mistaken for one.
     */
    private static function refusalIn(string $class, string $action): ?string
    {
        $file = RouteInventory::controllerFile($class);
        if ($file === null) {
            throw new \RuntimeException(
                "No file on disk for controller '{$class}' — a menu entry whose controller cannot be read "
                . 'is a menu entry nobody is checking.',
            );
        }

        $source = RouteInventory::reachableSource($file, $action);
        if ($source === '') {
            throw new \RuntimeException("{$class} declares no method '{$action}'.");
        }

        if (preg_match('/^.*\b403\b.*$/m', $source, $m) === 1) {
            return 'it can answer 403: ' . trim($m[0]);
        }

        if (preg_match('/^.*\$this->forbidden\(.*$/m', $source, $m) === 1) {
            return 'it can refuse: ' . trim($m[0]);
        }

        return null;
    }

    private static function explanation(): string
    {
        return 'A menu entry drawn from role_min alone points at an action that refuses on something else, '
            . 'so the site offers a link it will answer with a refusal — this is issue #347 happening again. '
            . 'Either drop the controller\'s extra check, or drop the `label` from the manifest and '
            . 'contribute the entry through a Core\\Module\\MenuEntryProvider that asks the controller\'s own '
            . 'question (Modules\\Retro\\Menu\\RetroMenuHookService is the worked example).';
    }
}
