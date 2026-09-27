<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A page that uses a class of `components.css` loads `components.css`
 * (issue #602).
 *
 * `base.html.twig` loads Bootstrap, `app.css` and `editable.css` on every
 * page, but not `components.css`: a page asks for it in its own
 * `{% block stylesheets %}`. Forgetting to is silent — the class is simply
 * inert — and it had been found by hand four times (the rental calendar,
 * the member card, the support ticket, then #570's carpool map, whose
 * point map had no height at all) before #602 found fifteen more pages
 * with an inert `.min-w-0`.
 *
 * **What it reads.** The classes `components.css` styles with a SIMPLE
 * selector — `.foo`, optionally with a pseudo-class — and that no
 * always-loaded stylesheet styles too. A class that only appears inside a
 * compound selector (`.nav .active`, `.rating .bi-star`) is styled only in
 * that context, and using it elsewhere is not a missing stylesheet. Then
 * every page reaching `base.html.twig` through `extends`, together with
 * everything it extends, includes, embeds or imports, recursively: a class
 * used in a partial needs the stylesheet on each page that pulls the
 * partial in. A template named through a variable cannot be followed; none
 * of the pages below depends on one for these classes.
 */
final class ComponentsStylesheetIsLoadedWhereItsClassesAreUsedTest extends TestCase
{
    private const ALWAYS_LOADED = [
        'public/assets/vendor/bootstrap/css/bootstrap.min.css',
        'public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
        'public/assets/css/app.css',
        'public/assets/css/editable.css',
    ];

    public function testEveryPageUsingAComponentsClassLoadsTheStylesheet(): void
    {
        $classes = self::componentsOnlyClasses();
        $templates = self::templates();
        $offenders = [];
        $pages = 0;

        foreach ($templates as $name => $source) {
            if (!self::isPage($name, $templates)) {
                continue;
            }
            ++$pages;

            $reachable = self::reachable($name, $templates);
            $loads = false;
            $used = [];
            foreach ($reachable as $member) {
                $memberSource = $templates[$member];
                if (str_contains($memberSource, "/assets/css/components.css')")) {
                    $loads = true;
                }
                foreach (self::classesUsedIn($memberSource) as $class) {
                    if (isset($classes[$class])) {
                        $used[$class] = true;
                    }
                }
            }

            if (!$loads && $used !== []) {
                ksort($used);
                $offenders[] = $name . ' uses ' . implode(', ', array_map(
                    static fn (string $class): string => '.' . $class,
                    array_keys($used)
                ));
            }
        }

        $this->assertGreaterThan(100, $pages, 'the scan found almost no pages to read');
        $this->assertGreaterThan(20, count($classes), 'the scan found almost no components.css classes');
        $this->assertSame(
            [],
            $offenders,
            "these pages use a class that only public/assets/css/components.css styles, and base.html.twig\n"
            . "does not load that file. Link it in the page's {% block stylesheets %} — or, for a generic\n"
            . "utility, move the rule to app.css:\n  " . implode("\n  ", $offenders) . "\n"
        );
    }

    /** The scan has to know the classes that started this, or it approves everything. */
    public function testTheReaderKnowsWhatItIsLookingFor(): void
    {
        $classes = self::componentsOnlyClasses();

        $this->assertArrayHasKey('carpool-point-map', $classes, 'issue #570\'s class');
        $this->assertArrayHasKey('support-payload-preview', $classes, 'issue #602\'s class');
        $this->assertArrayNotHasKey('min-w-0', $classes, '.min-w-0 lives in app.css since #602');

        $this->assertSame(['a', 'b-c'], self::classesUsedIn('<div class="a {{ x ? \'d\' : \'\' }} b-c">'));
        $this->assertSame(['a', 'b'], self::simpleClassesIn('.a, .b:hover { x: 1 } .c .d { } .e > .f { }'));
    }

    /**
     * @return array<string, true>
     */
    private static function componentsOnlyClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $classes = array_fill_keys(
            self::simpleClassesIn((string) file_get_contents($root . '/public/assets/css/components.css')),
            true
        );

        foreach (self::ALWAYS_LOADED as $path) {
            $css = self::withoutComments((string) file_get_contents($root . '/' . $path));
            foreach (array_keys($classes) as $class) {
                if (preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $css) === 1) {
                    unset($classes[$class]);
                }
            }
        }

        return $classes;
    }

    /**
     * The classes a stylesheet styles with a simple selector.
     *
     * @return list<string>
     */
    private static function simpleClassesIn(string $css): array
    {
        $css = self::withoutComments($css);
        // At-rule preludes (`@media …`) end in `{` too; they are not selectors.
        preg_match_all('/([^{}@;]+)\{/', $css, $matches);

        $classes = [];
        foreach ($matches[1] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                if (preg_match('/^\.([A-Za-z][\w-]*)(?::{1,2}[\w-]+(?:\([^)]*\))?)*$/', trim($selector), $m) === 1) {
                    $classes[$m[1]] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * The literal class names in a template's `class="…"` attributes.
     *
     * @return list<string>
     */
    private static function classesUsedIn(string $source): array
    {
        preg_match_all('/\bclass="([^"]*)"/', $source, $matches);

        $classes = [];
        foreach ($matches[1] as $value) {
            // Twig expressions inside the attribute are not class names.
            $value = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', ' ', $value);
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
                if (preg_match('/^[A-Za-z][\w-]*$/', $token) === 1) {
                    $classes[$token] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * @param array<string, string> $templates
     */
    private static function isPage(string $name, array $templates): bool
    {
        $seen = [];
        while (isset($templates[$name]) && !isset($seen[$name])) {
            $seen[$name] = true;
            if (preg_match('/\{%-?\s*extends\s+[\'"]([^\'"]+)[\'"]/', $templates[$name], $m) !== 1) {
                return false;
            }
            if ($m[1] === 'base.html.twig') {
                return true;
            }
            $name = $m[1];
        }

        return false;
    }

    /**
     * The template and everything it extends, includes, embeds or imports.
     *
     * @param array<string, string> $templates
     * @return list<string>
     */
    private static function reachable(string $name, array $templates): array
    {
        $seen = [];
        $queue = [$name];
        while ($queue !== []) {
            $current = array_pop($queue);
            if (isset($seen[$current]) || !isset($templates[$current])) {
                continue;
            }
            $seen[$current] = true;
            preg_match_all(
                '/(?:\{%-?\s*(?:extends|include|embed|import|from)\s+|\binclude\(\s*)[\'"]([^\'"]+)[\'"]/',
                $templates[$current],
                $matches
            );
            foreach ($matches[1] as $next) {
                $queue[] = $next;
            }
        }

        return array_keys($seen);
    }

    /**
     * Every template, keyed by the name Twig knows it by: `x.html.twig`
     * for core, `@module/x.html.twig` for a module's views.
     *
     * @return array<string, string>
     */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $templates = [];

        $core = $root . '/core/View/templates';
        foreach (self::twigFilesUnder($core) as $path) {
            $templates[substr($path, strlen($core) + 1)] = (string) file_get_contents($path);
        }
        foreach (glob($root . '/modules/*/views', GLOB_ONLYDIR) ?: [] as $views) {
            $module = basename(dirname($views));
            foreach (self::twigFilesUnder($views) as $path) {
                $templates['@' . $module . '/' . substr($path, strlen($views) + 1)] = (string) file_get_contents($path);
            }
        }

        return $templates;
    }

    /**
     * @return list<string>
     */
    private static function twigFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function withoutComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
