<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use PHPUnit\Framework\TestCase;

/**
 * The camps map's browser-storage key is an agreement of the same shape as
 * the tile host (Tests\Core\Geo\MapTilesTest), added when the map became
 * expanded by default: JavaScript writes it, module.json declares it to the
 * consent banner and the cookie preferences page, and nothing in either
 * language can see the other. AGENTS.md § Cookie consent makes the
 * declaration mandatory, so a key renamed on one side only is a site
 * quietly storing something it never told the visitor about.
 *
 * It stayed in the camps module when the map itself moved to the core: the
 * fold is the camps list's, not every map's.
 */
final class CampsMapStorageTest extends TestCase
{
    public function testTheMapsStorageKeyIsDeclaredAsAFunctionalCookieOfThisModule(): void
    {
        $root = self::root();
        $js = (string) file_get_contents($root . '/public/assets/js/camps-map.js');

        // Read the key out of the JavaScript rather than writing it here
        // twice: a test that hardcodes the name goes on passing after a
        // rename on one side, which is the whole failure it exists for.
        $this->assertSame(
            1,
            preg_match("/STORAGE_KEY = '([a-z0-9_]+)'/", $js, $match),
            'camps-map.js no longer declares a single STORAGE_KEY this test can read.'
        );
        $key = $match[1];

        /** @var array{cookies?: array<int, array<string, string>>} $manifest */
        $manifest = json_decode((string) file_get_contents($root . '/modules/camps/module.json'), true);
        $declared = [];
        foreach ($manifest['cookies'] ?? [] as $cookie) {
            $declared[$cookie['name']] = $cookie;
        }

        $this->assertArrayHasKey(
            $key,
            $declared,
            "camps-map.js writes '{$key}' to localStorage but modules/camps/module.json declares no such entry — "
            . 'the consent banner and the cookie preferences page would both be an incomplete picture '
            . '(AGENTS.md § Cookie consent).'
        );
        // Functional and not necessary: the map works without it, so it
        // is gated on consent client-side, and a category of 'necessary'
        // here would silently exempt it from that gate.
        $this->assertSame('functional', $declared[$key]['category']);
        $this->assertNotEmpty($declared[$key]['purpose']);
        $this->assertNotEmpty($declared[$key]['duration']);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
