<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * Every script `base.html.twig` loads on every page must also be in the
 * service worker's offline app shell.
 *
 * These two lists are written a directory apart and were never compared.
 * By the time this test was added, four site-wide toolboxes had drifted
 * out of the shell: `api.js`, `toast.js`, `confirm.js` and `theme.js`.
 * Offline — which for this site means a scout leader in a field with no
 * signal, the exact scenario the PWA exists for — the page still rendered,
 * so nothing looked broken; but `window.ScoutMagicApi` was undefined, so
 * every fetch helper was gone, and `window.ScoutMagicConfirm` was
 * undefined, so the delegated `data-confirm` handler threw before
 * re-submitting and « Supprimer » did nothing at all.
 *
 * The failure mode is always silent, which is why it needs a test rather
 * than a convention.
 *
 * The other direction — a shell entry whose file no longer exists, which
 * makes `cache.addAll()` reject wholesale and leaves the visitor with no
 * offline shell at all — is already covered by
 * Tests\Core\Offline\AppShellManifestTest.
 */
final class AppShellCoverageTest extends TestCase
{
    /**
     * Scripts that legitimately belong to one page rather than the shell.
     * `base.html.twig` loads them from inside a `{% if %}`, so they are
     * not on every page and caching them would grow the shell for nothing.
     *
     * @var list<string>
     */
    private const NOT_SHELL_SCRIPTS = [];

    public function testEverySiteWideScriptIsInTheOfflineAppShell(): void
    {
        $root = dirname(__DIR__, 3);
        $base = (string) file_get_contents($root . '/core/View/templates/base.html.twig');
        $sw = (string) file_get_contents($root . '/public/sw.js');

        // Scripts are referenced through the asset() cache-busting helper;
        // sw.js's precache list keeps bare paths and matches with
        // ignoreSearch, so the comparison strips the wrapper.
        preg_match_all("~<script[^>]+src=\"\{\{ asset\('(/assets/js/[^']+)'\) \}\}\"~", $base, $m);
        $loaded = array_values(array_unique($m[1]));
        self::assertNotEmpty($loaded, 'base.html.twig should load some scripts — did the markup change?');

        preg_match_all("~'(/assets/js/[^']+)'~", $sw, $shellMatches);
        $shell = $shellMatches[1];

        $missing = array_values(array_diff($loaded, $shell, self::NOT_SHELL_SCRIPTS));
        sort($missing);

        self::assertSame(
            [],
            $missing,
            "base.html.twig loads these on every page but public/sw.js never caches them —\n"
            . "offline they are simply absent, and whatever they provide fails silently:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * The offline page's own assets, held to the same rule — and it is a
     * harder rule there: this page is ONLY ever seen with no network, so
     * an asset missing from the shell is not degraded, it is absent.
     *
     * The script that matters most is theme.js. The page cannot carry the
     * inline nonce'd snippet base.html.twig uses to set `data-bs-theme`
     * before the first paint (a nonce baked into a service-worker-cached
     * response goes stale on the very next request), so it loads theme.js
     * from its head instead — and was served in light mode to every
     * dark-mode reader until it did.
     */
    public function testEveryAssetTheOfflinePageLoadsIsInTheOfflineAppShell(): void
    {
        $root = dirname(__DIR__, 3);
        $offline = (string) file_get_contents($root . '/core/View/templates/pwa/offline.html.twig');
        $sw = (string) file_get_contents($root . '/public/sw.js');

        preg_match_all("~asset\('(/assets/[^']+)'\)~", $offline, $m);
        $loaded = array_values(array_unique($m[1]));
        self::assertContains(
            '/assets/js/theme.js',
            $loaded,
            'pwa/offline.html.twig must load theme.js or it renders light for a dark-mode reader.'
        );

        preg_match_all("~'(/assets/[^']+)'~", $sw, $shellMatches);
        $missing = array_values(array_diff($loaded, $shellMatches[1]));
        sort($missing);

        self::assertSame(
            [],
            $missing,
            "pwa/offline.html.twig links these but public/sw.js never caches them — and this page
"
            . "is only ever shown with no network, so they would simply not be there:
  "
            . implode("\n  ", $missing)
        );
    }
}
