<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/sw.js's icon precaching and cache-invalidation logic (§8.23) —
 * no JS test runner in this repo (AGENTS.md), so this reads the raw file
 * to pin down the structural things that matter: every icon URL
 * base.html.twig can link (favicons, footer logo, the original PWA sizes)
 * is precached WITH its current `?v=` cache-buster, the icon match never
 * uses ignoreSearch (that was the actual bug — a stale/fresh `?v=` must
 * genuinely hit or miss the cache, never be silently ignored), and the
 * cache name folds in pwa_icon_version alongside app_version so a
 * standalone logo upload (which never bumps app_version) still purges
 * and re-precaches.
 */
class ServiceWorkerPrecacheTest extends TestCase
{
    private string $swJs;

    protected function setUp(): void
    {
        $this->swJs = (string) file_get_contents(dirname(__DIR__, 3) . '/public/sw.js');
    }

    private function iconUrlsBlock(): string
    {
        preg_match('/const ICON_URLS = \[(.*?)\];/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate ICON_URLS in public/sw.js');
        return $m[1];
    }

    private function appShellBaseUrlsBlock(): string
    {
        preg_match('/const APP_SHELL_BASE_URLS = \[(.*?)\];/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate APP_SHELL_BASE_URLS in public/sw.js');
        return $m[1];
    }

    public function testIconUrlsIncludeEveryOriginalPwaIconSize(): void
    {
        $block = $this->iconUrlsBlock();

        foreach (['/pwa/icon-192.png', '/pwa/icon-512.png', '/pwa/icon-512-maskable.png', '/pwa/icon-180.png'] as $url) {
            $this->assertStringContainsString("'{$url}'", $block);
        }
    }

    public function testIconUrlsIncludeTheFaviconAndFooterLogoSizes(): void
    {
        $block = $this->iconUrlsBlock();

        foreach (['/pwa/icon-16.png', '/pwa/icon-32.png', '/pwa/icon-48.png', '/pwa/icon-64.png'] as $url) {
            $this->assertStringContainsString("'{$url}'", $block);
        }
    }

    /**
     * /favicon.ico can never carry a `?v=` — browsers request it
     * implicitly at the document root with no query string at all — so it
     * must live in the plain (ignoreSearch-matched) app-shell list, never
     * in the versioned ICON_URLS list.
     */
    public function testFaviconIcoIsNotInTheVersionedIconList(): void
    {
        $block = $this->iconUrlsBlock();

        $this->assertStringNotContainsString('favicon.ico', $block);
    }

    public function testFaviconIcoAndOfflinePageAreInTheBaseAppShellList(): void
    {
        $block = $this->appShellBaseUrlsBlock();

        $this->assertStringContainsString("'/favicon.ico'", $block);
        $this->assertStringContainsString("'/offline'", $block);
    }

    public function testIconUrlsArePrecachedWithTheirVersionQueryString(): void
    {
        $this->assertStringContainsString('VERSIONED_ICON_URLS', $this->swJs);
        $this->assertMatchesRegularExpression(
            "/ICON_URLS\\.map\\(function \\(path\\) \\{ return path \\+ '\\?v=' \\+ ICON_VERSION; \\}\\)/",
            $this->swJs
        );
        $this->assertStringContainsString('const APP_SHELL_URLS = APP_SHELL_BASE_URLS.concat(VERSIONED_ICON_URLS);', $this->swJs);
    }

    public function testCacheNameFoldsInBothAppVersionAndIconVersion(): void
    {
        $this->assertMatchesRegularExpression(
            "/const CACHE_NAME = 'app-shell-' \\+ VERSION \\+ '-icons-' \\+ ICON_VERSION;/",
            $this->swJs
        );
    }

    public function testIconVersionIsReadFromTheIvQueryParamNeverHardcoded(): void
    {
        $this->assertStringContainsString("params.get('iv')", $this->swJs);
    }

    /**
     * The actual bug this whole lot fixes: icon URLs must be matched
     * WITHOUT ignoreSearch, or a "?v=7" request would resolve to whatever
     * was precached under "?v=6" (or vice versa) instead of correctly
     * missing the cache.
     */
    public function testIconFetchHandlerDoesNotUseIgnoreSearch(): void
    {
        preg_match('/if \(ICON_URLS\.includes\(url\.pathname\)\) \{(.*?)\n    \}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the ICON_URLS fetch-handler branch in public/sw.js');
        $this->assertStringNotContainsString('ignoreSearch', $m[1]);
    }

    /**
     * The counterpart to the icon rule above: none of the base app-shell
     * URLs legitimately carries a query string, so a lookup for one that
     * does must still resolve onto the precached bare-path entry. The
     * branch itself now delegates to appShellResponse(), which is where
     * the ignoreSearch lookup lives.
     */
    public function testAppShellBaseFetchHandlerStillUsesIgnoreSearch(): void
    {
        preg_match('/if \(APP_SHELL_BASE_URLS\.includes\(url\.pathname\)\) \{(.*?)\n    \}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the APP_SHELL_BASE_URLS fetch-handler branch in public/sw.js');
        $this->assertStringContainsString('appShellResponse(request, url)', $m[1]);

        preg_match('/function cachedShell\(request\) \{(.*?)\n\}/s', $this->swJs, $lookup);
        $this->assertNotEmpty($lookup, 'Could not locate cachedShell() in public/sw.js');
        $this->assertStringContainsString('ignoreSearch', $lookup[1]);
    }

    /**
     * §2.6, and a real production bug: cache.addAll() fetches the BARE
     * app-shell URLs — no `?v=` on any of them — and a bare
     * /assets/js/api.js is a URL no page ever requests, so the only thing
     * that ever writes it into the browser's own HTTP cache is a previous
     * precache. Served with a far-future lifetime (or heuristic freshness
     * off Last-Modified), that entry is then reused: the new cache
     * generation is filled with the PREVIOUS release's bytes, and
     * ignoreSearch serves them whatever `?v=` the page asks for. The
     * symptom was Configuration > Maintenance loading new markup against
     * the old api.js and dying on « window.ScoutMagicApi.pollSlot is not
     * a function » — an uncaught error, so every later block of
     * maintenance.js (update channel, branch selector, install form)
     * never ran at all.
     */
    public function testPrecacheBypassesTheBrowserHttpCache(): void
    {
        preg_match('/function precacheRequests\(\) \{(.*?)\n\}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate precacheRequests() in public/sw.js');
        $this->assertStringContainsString('APP_SHELL_URLS.map', $m[1]);
        $this->assertStringContainsString("new Request(url, { cache: 'reload' })", $m[1]);

        preg_match("/self\.addEventListener\('install', function \(event\) \{(.*?)\n\}\);/s", $this->swJs, $install);
        $this->assertNotEmpty($install, 'Could not locate the install() handler in public/sw.js');
        $this->assertStringContainsString('cache.addAll(precacheRequests())', $install[1]);
    }

    /**
     * The other half of the same bug: on the first page load after an
     * install the controlling worker is still the previous one, so
     * cache-first hands the previous build's script to the new build's
     * markup. A `?v=` this worker never precached must go to the network
     * — with the cache kept as the offline fallback, so nothing regresses
     * when there is no network to go to.
     */
    public function testAShellVersionThisWorkerNeverPrecachedBypassesTheCache(): void
    {
        preg_match('/function isSupersededShellRequest\(url\) \{(.*?)\n\}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate isSupersededShellRequest() in public/sw.js');
        $this->assertStringContainsString("url.searchParams.get('v')", $m[1]);
        $this->assertStringContainsString('requested !== VERSION', $m[1]);

        preg_match('/function appShellResponse\(request, url\) \{(.*?)\n\}/s', $this->swJs, $branch);
        $this->assertNotEmpty($branch, 'Could not locate appShellResponse() in public/sw.js');
        $this->assertStringContainsString('isSupersededShellRequest(url)', $branch[1]);
        $this->assertStringContainsString('fetch(request)', $branch[1]);
        $this->assertStringContainsString('cachedShell(request)', $branch[1]);
    }

    /**
     * §2.1: activate() must only ever purge app-shell-* cache generations
     * — never offline-config or content-* (Lot 3), which used to be wiped
     * on every release or standalone logo upload because the old filter
     * deleted every cache whose name simply differed from CACHE_NAME.
     */
    public function testActivateOnlyPurgesAppShellPrefixedCaches(): void
    {
        preg_match("/self\\.addEventListener\\('activate', function \\(event\\) \\{(.*?)\\n\\}\\);/s", $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the activate() handler in public/sw.js');

        $this->assertStringContainsString('APP_SHELL_CACHE_PREFIX', $m[1]);
        $this->assertStringNotContainsString("name !== CACHE_NAME }", $m[1]);
    }

    /**
     * §2.2: bootstrap-icons.min.css was precached without its font files
     * — every icon site-wide (including the whole menu) rendered as an
     * empty box offline. components.css and the other listed scripts fix
     * the same class of bug for Staffs/trombinoscope/calendar/member
     * page/départs and the offline-page/offline-nav/offline-prefetch
     * scripts themselves.
     */
    public function testAppShellIncludesBootstrapIconsFontFiles(): void
    {
        $block = $this->appShellBaseUrlsBlock();

        $this->assertStringContainsString("'/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2'", $block);
        $this->assertStringContainsString("'/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff'", $block);
    }

    public function testAppShellIncludesComponentsCssAndTheOfflineSupportScripts(): void
    {
        $block = $this->appShellBaseUrlsBlock();

        $this->assertStringContainsString("'/assets/css/components.css'", $block);
        foreach ([
            '/assets/js/breadcrumb.js',
            '/assets/js/select-bar.js',
            '/assets/js/nav-rail.js',
            '/assets/js/notification-badge.js',
            '/assets/js/offline-cache.js',
            '/assets/js/offline-nav.js',
            '/assets/js/offline-prefetch.js',
            '/assets/js/offline-page.js',
        ] as $url) {
            $this->assertStringContainsString("'{$url}'", $block);
        }
    }

    public function testAppShellIncludesContactPageAndBranchCardImages(): void
    {
        $block = $this->appShellBaseUrlsBlock();

        $this->assertStringContainsString("'/assets/img/lesscouts.png'", $block);
        $this->assertStringContainsString("'/assets/img/branches/", $block);
    }

    /**
     * §2.5: /files/{id}/{variant} must be answered network-first with a
     * cache-match fallback, and this branch must never write to the
     * cache itself — writing stays the pre-download script's job.
     */
    public function testFileVariantBranchIsNetworkFirstAndNeverWrites(): void
    {
        preg_match('/if \(\/\^\\\\\/files\\\\\/\\\\d\+\\\\\/\(thumb\|md\)\$\/\.test\(url\.pathname\)\) \{(.*?)\n    \}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the /files/{id}/{variant} fetch-handler branch in public/sw.js');

        $this->assertStringContainsString('fetch(request)', $m[1]);
        $this->assertStringContainsString('caches.match(request)', $m[1]);
        $this->assertStringNotContainsString('cache.put', $m[1]);
        $this->assertStringNotContainsString('.put(', $m[1]);
    }

    /**
     * The old '/api/offline/photo/' exception route no longer exists
     * anywhere in the service worker.
     */
    public function testNoLongerReferencesTheRetiredOfflinePhotoRoute(): void
    {
        $this->assertStringNotContainsString('/api/offline/photo/', $this->swJs);
    }

    /**
     * Part 3.4: a normal browser tab's own navigation must never WRITE to
     * the content cache now that it can hold personal data (Mon compte,
     * the member's own page) — only the installed app does. Reads (the
     * cache.match() fallback on a fetch failure) stay unconditional.
     */
    public function testNetworkFirstWithCacheFallbackGatesTheWriteOnStandalone(): void
    {
        preg_match('/function networkFirstWithCacheFallback\(request, url, config, network\) \{(.*?)\n\}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate networkFirstWithCacheFallback() in public/sw.js');

        $this->assertMatchesRegularExpression(
            '/if \(response\?\.ok && !response\.redirected && config\.standalone\) \{/',
            $m[1]
        );
    }

    /**
     * The read path (cache.match() inside the .catch() fallback) must NOT
     * be gated on config.standalone — a plain tab must still be able to
     * read whatever the installed app already cached.
     */
    public function testNetworkFirstWithCacheFallbackReadsAreUnconditional(): void
    {
        preg_match('/function networkFirstWithCacheFallback\(request, url, config, network\) \{(.*?)\n\}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate networkFirstWithCacheFallback() in public/sw.js');

        preg_match('/\.catch\(function \(\) \{(.*)/s', $m[1], $catchBlock);
        $this->assertNotEmpty($catchBlock, 'Could not locate the .catch() fallback block');
        $this->assertStringNotContainsString('config.standalone', $catchBlock[1]);
    }
}
