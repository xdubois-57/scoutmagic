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

}
