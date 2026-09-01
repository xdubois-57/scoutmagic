<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\AssetVersion;
use PHPUnit\Framework\TestCase;

/**
 * The token that busts a stale stylesheet.
 *
 * What is pinned here is the one property the old implementation did not
 * have: **it changes when a file changes, even though the release does
 * not.** `main` carries one `VERSION` across dozens of merges, and
 * `public/sw.js` caches its app shell under exactly that token with
 * `ignoreSearch`, so an unchanged token meant a browser kept serving the
 * stylesheet it precached weeks earlier — new markup against the previous
 * CSS.
 */
class AssetVersionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/scoutmagic-assets-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public/assets/css', 0700, true);
        mkdir($this->root . '/public/assets/js', 0700, true);
        file_put_contents($this->root . '/public/assets/css/app.css', 'body{}');
        file_put_contents($this->root . '/public/assets/js/api.js', '// api');
        file_put_contents($this->root . '/public/sw.js', '// sw');
        AssetVersion::forgetMemo();
    }

    protected function tearDown(): void
    {
        AssetVersion::forgetMemo();
        self::removeTree($this->root);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    public function testTheTokenStartsWithTheReleaseVersionSoItStaysReadable(): void
    {
        $token = AssetVersion::forProject($this->root, '1.0.38');

        $this->assertStringStartsWith('1.0.38-', $token);
    }

    /**
     * The whole point. Same release, changed stylesheet, different token
     * — which is what makes the service worker install a new app shell.
     */
    public function testAChangedStylesheetChangesTheTokenWithoutAReleaseBump(): void
    {
        $before = AssetVersion::forProject($this->root, '1.0.38');

        file_put_contents($this->root . '/public/assets/css/app.css', 'body{margin:0}');
        AssetVersion::forgetMemo();

        $this->assertNotSame($before, AssetVersion::forProject($this->root, '1.0.38'));
    }

    public function testANewScriptChangesItToo(): void
    {
        $before = AssetVersion::forProject($this->root, '1.0.38');

        file_put_contents($this->root . '/public/assets/js/support-package.js', '// new');
        AssetVersion::forgetMemo();

        $this->assertNotSame($before, AssetVersion::forProject($this->root, '1.0.38'));
    }

    public function testAnUnchangedTreeAnswersTheSameTokenTwice(): void
    {
        $first = AssetVersion::forProject($this->root, '1.0.38');
        AssetVersion::forgetMemo();

        $this->assertSame($first, AssetVersion::forProject($this->root, '1.0.38'));
    }

    /**
     * A tree with no assets at all — a unit test's temporary root, an
     * install caught mid-copy — must not produce a token that changes on
     * every call, and must never be empty: an empty `?v=` is a query
     * string that busts nothing and reads as a bug.
     */
    public function testWithoutAnyAssetItFallsBackToTheReleaseVersionAlone(): void
    {
        $bare = sys_get_temp_dir() . '/scoutmagic-assets-bare-' . bin2hex(random_bytes(6));
        mkdir($bare, 0700, true);

        try {
            $this->assertSame('1.0.38', AssetVersion::forProject($bare, '1.0.38'));
        } finally {
            rmdir($bare);
        }
    }
}
