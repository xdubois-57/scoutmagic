<?php

declare(strict_types=1);

namespace Tests\Core\Offline;

use PHPUnit\Framework\TestCase;

/**
 * Part 2.4's "precache list must stay honest" test — public/sw.js's
 * APP_SHELL_BASE_URLS is a hand-written array, and it has drifted from
 * reality before (bootstrap-icons.min.css precached without its font
 * files, so every icon site-wide rendered as an empty box offline — see
 * ARCHITECTURE.md §8.23/§8.25). This fails the build the moment a listed
 * path stops existing on disk, rather than only ever being noticed by an
 * offline user staring at broken icons.
 */
class AppShellManifestTest extends TestCase
{
    /**
     * Entries that resolve to a Core route (Core\Http\Controller\PwaController
     * ::offline()/favicon()), not a static file under public/ — the only
     * two in APP_SHELL_BASE_URLS with no file on disk to check.
     */
    private const ROUTE_ENTRIES = ['/offline', '/favicon.ico'];

    public function testEveryAppShellBaseUrlExistsOnDisk(): void
    {
        $publicDir = dirname(__DIR__, 3) . '/public';
        $swJs = (string) file_get_contents($publicDir . '/sw.js');

        $this->assertMatchesRegularExpression(
            '/const APP_SHELL_BASE_URLS = \[(.*?)\];/s',
            $swJs,
            'Could not find APP_SHELL_BASE_URLS in public/sw.js — has it been renamed?'
        );
        preg_match('/const APP_SHELL_BASE_URLS = \[(.*?)\];/s', $swJs, $matches);

        preg_match_all("/'([^']+)'/", $matches[1], $urlMatches);
        $urls = $urlMatches[1];
        $this->assertNotEmpty($urls, 'APP_SHELL_BASE_URLS parsed as empty — the extraction regex likely broke.');

        foreach ($urls as $url) {
            if (in_array($url, self::ROUTE_ENTRIES, true)) {
                continue;
            }

            $this->assertFileExists(
                $publicDir . $url,
                "APP_SHELL_BASE_URLS entry '{$url}' has no file on disk under public/ — either the path is wrong or the file was removed without updating sw.js."
            );
        }
    }
}
