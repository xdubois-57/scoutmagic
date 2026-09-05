<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * A vendored front-end file must not point the browser at a file that is
 * not there.
 *
 * Issue #167: every page of the site logged three 404s in the browser
 * console — `bootstrap.min.css.map`, `bootstrap.bundle.min.js.map` — for
 * visitors who had never opened a debugger. The minified distributions
 * end with a `sourceMappingURL` comment, the `.map` files they name were
 * never copied into `public/assets/vendor/`, and nothing between writing
 * the code and serving it looks at that. The same omission was in
 * `chart.umd.min.js` and `leaflet.js`.
 *
 * Nothing else can catch it. It is invisible to PHP tests (no code path
 * reads these files), to the end-to-end suite (a missing source map fails
 * no assertion), and to review (the comment is the last 40 characters of
 * a one-line 200 KB file). It comes back every time a library is
 * re-vendored, which is a release-time habit — see AGENTS.md § CSS /
 * frontend — so the guard has to live where the habit is checked.
 *
 * Two ways to satisfy this test, and both are correct: ship the `.map`
 * next to the file, or drop the comment. This repository drops the
 * comment — the maps are debugging aids for the library's own authors,
 * they are several times the size of the code they describe, and nothing
 * in this project ever steps through Bootstrap's Sass.
 */
class VendoredAssetSourceMapsTest extends TestCase
{
    /** Where every vendored front-end library lives. */
    private const VENDOR_DIR = 'public/assets/vendor';

    /**
     * The floor under the assertion below: a test that scanned nothing
     * would pass over a vendor directory that had been moved or emptied,
     * and report green over the very files it exists to watch.
     */
    public function testTheVendorDirectoryIsWhereThisTestThinksItIs(): void
    {
        $files = $this->vendoredAssets();

        self::assertGreaterThan(
            4,
            count($files),
            self::VENDOR_DIR . ' holds ' . count($files) . ' .css/.js file(s). The vendored libraries '
            . '(Bootstrap, Bootstrap Icons, Chart.js, Leaflet, html5-qrcode) are more than that, so '
            . 'either they moved or this test is now scanning the wrong place and asserting nothing.',
        );
    }

    /**
     * Every `sourceMappingURL` in a vendored file names a file that is
     * actually served, or there is no `sourceMappingURL` at all.
     */
    public function testNoVendoredAssetPointsAtAMissingSourceMap(): void
    {
        $dangling = [];

        foreach ($this->vendoredAssets() as $path) {
            foreach ($this->sourceMapReferences($path) as $reference) {
                // An inline map carries its own content; there is no
                // second request and therefore no 404.
                if (str_starts_with($reference, 'data:')) {
                    continue;
                }

                $target = dirname($path) . '/' . $reference;
                if (!is_file($target)) {
                    $dangling[] = $this->relative($path) . ' → ' . $reference;
                }
            }
        }

        self::assertSame(
            [],
            $dangling,
            "A vendored asset points the browser at a source map that is not served, so every visitor "
            . "gets a 404 in their console on every page (issue #167):\n  - "
            . implode("\n  - ", $dangling)
            . "\n\nWhen re-vendoring a minified library, strip its trailing `sourceMappingURL` comment "
            . '(or copy the `.map` alongside it). See AGENTS.md § CSS / frontend.',
        );
    }

    /**
     * The `sourceMappingURL` values a file declares, CSS and JS syntax
     * alike (`/*# … *' . '/` and `//# …`).
     *
     * @return array<int, string>
     */
    private function sourceMapReferences(string $path): array
    {
        $content = (string) file_get_contents($path);

        if (preg_match_all('/[#@]\s*sourceMappingURL=([^\s*]+)/', $content, $matches) === false) {
            self::fail('Could not scan ' . $this->relative($path) . ' for source map references.');
        }

        return array_map(
            static fn (string $reference): string => rtrim($reference, "*/ \t\r\n"),
            $matches[1],
        );
    }

    /**
     * Every vendored stylesheet and script, recursively.
     *
     * @return array<int, string>
     */
    private function vendoredAssets(): array
    {
        $root = dirname(__DIR__, 3) . '/' . self::VENDOR_DIR;

        self::assertDirectoryExists($root, self::VENDOR_DIR . ' is missing.');

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['css', 'js'], true)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 3) . '/', '', $path);
    }
}
