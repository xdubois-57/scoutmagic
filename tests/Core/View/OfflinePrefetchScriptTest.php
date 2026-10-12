<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/js/offline-prefetch.js (Part 3.2/3.4), the pass it asks
 * public/sw.js to run (#883), and the `standalone` flag
 * public/assets/js/offline-cache.js computes for both. Reads the raw files
 * to pin down the structural things that matter: the manifest route,
 * skipping already-cached images, deleting what left the list, and
 * revalidating pages via If-None-Match with a Date-refresh on 304. The
 * behaviour itself is exercised by tests/js/sw-offline-pass.test.js and
 * tests/js/offline-prefetch.test.js.
 */
class OfflinePrefetchScriptTest extends TestCase
{
    private string $prefetchJs;
    private string $cacheJs;
    private string $swJs;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->prefetchJs = (string) file_get_contents($root . '/public/assets/js/offline-prefetch.js');
        $this->cacheJs = (string) file_get_contents($root . '/public/assets/js/offline-cache.js');
        $this->swJs = (string) file_get_contents($root . '/public/sw.js');
    }

    /** The body of a top-level function of public/sw.js. */
    private function swFunction(string $signature): string
    {
        preg_match('/function ' . preg_quote($signature, '/') . ' \{(.*?)\n\}/s', $this->swJs, $m);
        $this->assertNotEmpty($m, "Could not locate {$signature} in public/sw.js");

        return $m[1];
    }

    public function testFetchesTheNewManifestRouteNotTheOldPhotoManifest(): void
    {
        $this->assertStringContainsString("const MANIFEST_URL = '/api/offline/manifest';", $this->swJs);
        $this->assertStringNotContainsString('/api/offline/photo-manifest', $this->swJs);
        $this->assertStringNotContainsString('/api/offline/photo-manifest', $this->prefetchJs);
    }

    public function testNeverRunsWithoutConsent(): void
    {
        $this->assertStringContainsString('!config.consent', $this->prefetchJs);
    }

    /**
     * A visitor not signed in gets the list only (#883): the public pages
     * they visit are kept, nothing is downloaded ahead of them.
     */
    public function testAVisitorNotSignedInGetsTheListOnly(): void
    {
        $this->assertStringContainsString("config.account_scope === 'guest'", $this->swJs);
        $this->assertStringContainsString('return keepListOnly(config);', $this->swJs);
    }

    public function testOnlyRunsInStandaloneDisplayMode(): void
    {
        $this->assertStringContainsString("display-mode: standalone", $this->prefetchJs);
    }

    public function testNoLongerGatesOnAOneTimeLocalStorageMarker(): void
    {
        // The old photo-only version never re-ran after its first
        // success; re-validating pages via ETag every launch is the
        // whole point now, so this marker must be gone.
        $this->assertStringNotContainsString('localStorage', $this->prefetchJs);
        $this->assertStringNotContainsString('predownloaded', $this->prefetchJs);
    }

    /**
     * #883: the page asks, the worker works — and the launch is remembered
     * only once the worker says the pass finished.
     */
    public function testThePageAsksTheWorkerAndRemembersOnlyAFinishedPass(): void
    {
        $this->assertStringContainsString("{ type: 'offline-refresh' }", $this->prefetchJs);
        // Under Data Saver the list is still kept, only nothing is downloaded ahead.
        $this->assertStringContainsString("{ type: 'offline-refresh', listOnly: true }", $this->prefetchJs);
        $this->assertStringContainsString('postMessage(request)', $this->prefetchJs);
        $this->assertStringContainsString("event.data?.type === 'offline-refresh-done'", $this->prefetchJs);
        $this->assertStringNotContainsString('caches.open', $this->prefetchJs);
        $this->assertStringContainsString("data.type === 'offline-refresh'", $this->swJs);
    }

    public function testImagesSkipAlreadyCachedUrlsWithoutFetching(): void
    {
        $body = $this->swFunction('fetchMissingImages(cache, images, scope)');

        $this->assertStringContainsString('cache.match(url)', $body);
        $this->assertStringContainsString('cached ? undefined : fetch(url', $body);
    }

    public function testWhatLeftTheListIsDeleted(): void
    {
        $body = $this->swFunction('pruneCopies(cache, kept)');

        $this->assertStringContainsString('cache.keys()', $body);
        $this->assertStringContainsString('cache.delete(', $body);
    }

    public function testPagesSendIfNoneMatchFromTheCachedEtag(): void
    {
        $body = $this->swFunction('refreshPage(cache, path, startedAt, scope)');

        $this->assertStringContainsString("cached.headers.get('ETag')", $body);
        $this->assertStringContainsString("headers['If-None-Match']", $body);
    }

    public function testA304RefreshesTheCachedDateInsteadOfRedownloading(): void
    {
        $body = $this->swFunction('stampedAnswer(response, cached, scope)');

        $this->assertStringContainsString('response.status === 304', $body);
        $this->assertStringContainsString('stamped(cached, true)', $body);
        $this->assertStringContainsString("headers.set('Date'", $this->swFunction('stamped(response, refreshDate)'));
    }

    public function testEveryImageAndPageFetchIsBestEffort(): void
    {
        // One failed fetch must never abort the rest of the pass — and is
        // counted, so that a pass with failures is not marked done.
        $body = $this->swFunction('inLanes(items, work)');
        $this->assertStringContainsString('.catch(function () {', $body);
        $this->assertStringContainsString('failures += 1;', $body);
        $this->assertStringContainsString('if (failed > 0)', $this->swFunction('settlePass(record, failed)'));
    }

    // --- offline-cache.js: the standalone flag itself ---

    /**
     * Issue #842: the detection is display-mode.js's, exposed as
     * window.ScoutMagicDisplayMode, and offline-cache.js asks it rather
     * than keeping its own copy of the media query.
     */
    public function testOfflineCacheComputesAndSendsTheStandaloneFlag(): void
    {
        $displayModeJs = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/display-mode.js');

        $this->assertStringContainsString('standalone: isStandalone()', $this->cacheJs);
        $this->assertStringContainsString('window.ScoutMagicDisplayMode', $this->cacheJs);
        $this->assertStringNotContainsString('display-mode: standalone', $this->cacheJs);
        $this->assertStringContainsString('display-mode: standalone', $displayModeJs);
        $this->assertStringContainsString('window.ScoutMagicDisplayMode = { isStandalone: isStandalone }', $displayModeJs);
    }

    /**
     * DoD: "Logout and withdrawing functional consent both purge every
     * content-* cache." Pre-existing behaviour (Part 1), reverified here
     * since this iteration touches the same config payload.
     */
    public function testLogoutFormSubmissionTriggersAPurge(): void
    {
        $this->assertStringContainsString('form[action="/logout"]', $this->cacheJs);
        $this->assertStringContainsString("purge-content-caches", $this->cacheJs);
    }

    public function testWithdrawingFunctionalConsentSendsConfigWithConsentFalse(): void
    {
        preg_match('/cookiesForm\.addEventListener\(\'submit\', function \(\) \{(.*?)\n\s*\}\);/s', $this->cacheJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the /cookies/save form handler');

        $this->assertStringContainsString('sendConfig(false)', $m[1]);
    }
}
