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
