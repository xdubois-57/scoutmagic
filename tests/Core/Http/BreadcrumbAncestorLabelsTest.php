<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use PHPUnit\Framework\TestCase;

/**
 * **A breadcrumb crumb must say what the page it links to is called.**
 *
 * `breadcrumb.ancestors[]` carries a `path` and a `label`, and
 * `Router::ancestorTrailFor()` renders that label verbatim — it never
 * looks up what the target route calls itself. The two are therefore two
 * copies of one name, and a rename that touches only the route leaves
 * the crumb pointing at a page under a title that no longer exists.
 *
 * That is not hypothetical: renaming eleven pages left eleven crumbs
 * behind, saying « Galerie », « Groupes », « Courrier », « Actualités »
 * and « Inscriptions » for pages by then called « Photos »,
 * « Discussions », « Courrier reçu », « Rédiger les actualités » and
 * « Formulaire d'inscription ». Nothing failed.
 *
 * `Tests\Core\Http\BreadcrumbAncestorRoutesTest` is the sibling of this
 * test and deliberately checks something else — that the ancestor path
 * resolves, and that its role floor is not stricter than the child's. It
 * never compares the two texts, which is the gap this closes.
 *
 * **Only GET routes name a page.** A module declares several routes on
 * one path — a form and the POST that submits it — and only the readable
 * one carries the page's name; keying on the path alone made a write
 * endpoint's label win and produced findings that were not real.
 */
final class BreadcrumbAncestorLabelsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function manifests(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $path) {
            $cases[basename(dirname($path))] = [$path];
        }

        self::assertNotSame([], $cases, 'No module manifest found — the glob broke.');

        return $cases;
    }

    /**
     * **A third copy of the same name: the offline list.**
     *
     * `offline[]` declares which pages a phone keeps for reading without
     * a signal, and each entry carries its own `label` — shown to the
     * reader in that list. It is neither the route's label nor a crumb,
     * so neither check above reaches it, and the rename of « Groupes »
     * left it behind: the offline list offered « Groupes » for a page
     * the whole rest of the site had started calling « Discussions ».
     *
     * Three declarations of one name is two too many, but they are what
     * the manifest format has; holding them equal is what this file is
     * for.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('manifests')]
    public function testEveryOfflineEntryNamesItsPageTheSameWay(string $manifestPath): void
    {
        /** @var array{routes?: list<array<string, mixed>>, offline?: list<array<string, mixed>>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $pageNames = [];
        foreach ($manifest['routes'] ?? [] as $route) {
            if (strtoupper((string) ($route['method'] ?? 'GET')) !== 'GET') {
                continue;
            }
            $name = $route['label'] ?? null;
            if (is_string($name) && $name !== '' && !isset($pageNames[$route['path']])) {
                $pageNames[(string) $route['path']] = $name;
            }
        }

        $stale = [];
        foreach ($manifest['offline'] ?? [] as $entry) {
            if (!isset($entry['path'], $entry['label'])) {
                continue;
            }

            $target = $pageNames[(string) $entry['path']] ?? null;
            if ($target !== null && $target !== $entry['label']) {
                $stale[] = sprintf(
                    'offline %s is offered as « %s », the page calls itself « %s »',
                    (string) $entry['path'],
                    (string) $entry['label'],
                    $target
                );
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * **The same crumb, built in PHP instead of declared.**
     *
     * A manifest is not the only place a trail is written: a controller
     * that needs a crumb the declaration cannot express — a booking's
     * own stay, a group's own page — builds the array itself, and those
     * entries carry a hardcoded `label` pointing at a `url`.
     *
     * The manifest check above cannot see them, which is exactly how the
     * rename of « Groupes » to « Discussions » left four of them behind
     * in three controllers of that one module, saying the old name on
     * every group page, every member list and every report. Two tests
     * pinned the old text and one of them went green because it passed
     * its own hardcoded declaration rather than the module's.
     *
     * So the sources are read for `['label' => '…', 'url' => '/…']` and
     * held to the same rule: the crumb says what the page it links to
     * calls itself. A URL no manifest declares as a GET page is left
     * alone — a controller may legitimately name something the menus do
     * not.
     */
    public function testATrailBuiltInAControllerNamesItsTargetTheSameWay(): void
    {
        $pageNames = [];
        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $path) {
            /** @var array{routes?: list<array<string, mixed>>} $manifest */
            $manifest = json_decode((string) file_get_contents($path), true);
            foreach ($manifest['routes'] ?? [] as $route) {
                if (strtoupper((string) ($route['method'] ?? 'GET')) !== 'GET') {
                    continue;
                }

                $name = $route['label'] ?? null;
                if (is_string($name) && $name !== '' && !isset($pageNames[$route['path']])) {
                    $pageNames[(string) $route['path']] = $name;
                }
            }
        }

        $this->assertNotSame([], $pageNames, 'No labelled module page was read — the manifests are unreadable.');

        $stale = [];
        $sources = glob(dirname(__DIR__, 3) . '/modules/*/src/*/*.php') ?: [];
        $sources = [...$sources, ...(glob(dirname(__DIR__, 3) . '/modules/*/src/*/*/*.php') ?: [])];
        $this->assertNotSame([], $sources, 'No module source was read — the glob broke.');

        foreach ($sources as $file) {
            $code = (string) file_get_contents($file);

            preg_match_all(
                "/\['label' => '((?:[^'\\\\]|\\\\.)*)',\s*'url' => '([^']*)'/",
                $code,
                $found,
                PREG_SET_ORDER
            );

            foreach ($found as $crumb) {
                $target = $pageNames[$crumb[2]] ?? null;
                if ($target !== null && $target !== $crumb[1]) {
                    $stale[] = sprintf(
                        '%s: crumb to %s says « %s », the page calls itself « %s »',
                        basename($file),
                        $crumb[2],
                        $crumb[1],
                        $target
                    );
                }
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('manifests')]
    public function testEveryAncestorCrumbNamesItsTargetAsThatPageNamesItself(string $manifestPath): void
    {
        /** @var array{routes?: list<array<string, mixed>>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $routes = $manifest['routes'] ?? [];

        $pageNames = [];
        foreach ($routes as $route) {
            if (strtoupper((string) ($route['method'] ?? 'GET')) !== 'GET') {
                continue;
            }

            $name = $route['label'] ?? null;
            if (($name === null || $name === '') && isset($route['breadcrumb']['label'])) {
                $name = $route['breadcrumb']['label'];
            }

            if (is_string($name) && $name !== '' && !isset($pageNames[$route['path']])) {
                $pageNames[(string) $route['path']] = $name;
            }
        }

        $stale = [];
        foreach ($routes as $route) {
            foreach ((array) ($route['breadcrumb']['ancestors'] ?? []) as $ancestor) {
                if (!is_array($ancestor) || !isset($ancestor['path'], $ancestor['label'])) {
                    continue;
                }

                $target = $pageNames[(string) $ancestor['path']] ?? null;
                if ($target !== null && $target !== $ancestor['label']) {
                    $stale[] = sprintf(
                        '%s → %s: crumb says « %s », the page calls itself « %s »',
                        (string) $route['path'],
                        (string) $ancestor['path'],
                        (string) $ancestor['label'],
                        $target
                    );
                }
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }
}
