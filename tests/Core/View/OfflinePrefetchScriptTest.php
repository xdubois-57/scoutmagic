<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/js/offline-prefetch.js (Part 3.2/3.4) and the
 * `standalone` flag public/assets/js/offline-cache.js computes for it —
 * no JS test runner in this repo (AGENTS.md), so this reads the raw files
 * to pin down the structural things that matter: fetching the new
 * manifest route, skipping already-cached images, deleting stale ones,
 * and revalidating pages via If-None-Match with a Date-refresh on 304.
 */
class OfflinePrefetchScriptTest extends TestCase
{
    private string $prefetchJs;
    private string $cacheJs;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->prefetchJs = (string) file_get_contents($root . '/public/assets/js/offline-prefetch.js');
        $this->cacheJs = (string) file_get_contents($root . '/public/assets/js/offline-cache.js');
    }

    public function testFetchesTheNewManifestRouteNotTheOldPhotoManifest(): void
    {
        $this->assertStringContainsString('/api/offline/manifest', $this->prefetchJs);
        $this->assertStringNotContainsString('/api/offline/photo-manifest', $this->prefetchJs);
    }

    public function testNeverRunsAnonymouslyOrWithoutConsent(): void
    {
        $this->assertStringContainsString("config.accountScope === 'guest'", $this->prefetchJs);
        $this->assertStringContainsString('!config.consent', $this->prefetchJs);
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

    public function testImagesSkipAlreadyCachedUrlsWithoutFetching(): void
    {
        preg_match('/function prefetchImages\(cache, imageUrls\) \{(.*?)\n    \}\n\}/s', $this->prefetchJs, $m);
        $this->assertNotEmpty($m, 'Could not locate prefetchImages()');

        $this->assertStringContainsString('cache.match(url)', $m[1]);
        $this->assertMatchesRegularExpression('/if \(cached\) \{\s*return undefined;/', $m[1]);
    }

    public function testImagesDeleteStaleEntriesNoLongerInTheManifest(): void
    {
        preg_match('/function prefetchImages\(cache, imageUrls\) \{(.*?)\n    \}\n\}/s', $this->prefetchJs, $m);
        $this->assertNotEmpty($m, 'Could not locate prefetchImages()');

        $this->assertStringContainsString('cache.keys()', $m[1]);
        $this->assertStringContainsString('cache.delete(', $m[1]);
        $this->assertStringContainsString('IMAGE_URL_PATTERN', $m[1]);
    }

    public function testPagesSendIfNoneMatchFromTheCachedEtag(): void
    {
        preg_match('/function prefetchPages\(cache, pageUrls\) \{(.*?)\n    \}\n\}/s', $this->prefetchJs, $m);
        $this->assertNotEmpty($m, 'Could not locate prefetchPages()');

        $this->assertStringContainsString("cached.headers.get('ETag')", $m[1]);
        $this->assertStringContainsString("headers['If-None-Match']", $m[1]);
    }

    public function testA304RefreshesTheCachedDateInsteadOfRedownloading(): void
    {
        preg_match('/function prefetchPages\(cache, pageUrls\) \{(.*?)\n    \}\n\}/s', $this->prefetchJs, $m);
        $this->assertNotEmpty($m, 'Could not locate prefetchPages()');

        $this->assertStringContainsString('response.status === 304', $m[1]);
        $this->assertStringContainsString('refreshCachedDate(', $m[1]);
    }

    public function testRefreshCachedDateSetsANewDateHeaderOnTheSameBody(): void
    {
        preg_match('/function refreshCachedDate\(cache, url, cached\) \{(.*?)\n\}/s', $this->prefetchJs, $m);
        $this->assertNotEmpty($m, 'Could not locate refreshCachedDate()');

        $this->assertStringContainsString("headers.set('Date'", $m[1]);
        $this->assertStringContainsString('cache.put(url, refreshed)', $m[1]);
    }

    public function testEveryImageAndPageFetchIsBestEffort(): void
    {
        // At least the per-image and per-page fetch chains each carry
        // their own .catch() — one failure must never abort the rest.
        $this->assertGreaterThanOrEqual(2, substr_count($this->prefetchJs, '.catch(function ()'));
    }

    // --- offline-cache.js: the standalone flag itself ---

    public function testOfflineCacheComputesAndSendsTheStandaloneFlag(): void
    {
        $this->assertStringContainsString('isStandalone()', $this->cacheJs);
        $this->assertStringContainsString('display-mode: standalone', $this->cacheJs);
        $this->assertStringContainsString('standalone: isStandalone()', $this->cacheJs);
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
