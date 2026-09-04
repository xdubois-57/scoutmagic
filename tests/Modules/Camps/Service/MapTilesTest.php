<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Modules\Camps\Service\MapTiles;
use PHPUnit\Framework\TestCase;

/**
 * The tile provider is named in three languages that cannot check each
 * other: PHP (the Content-Security-Policy), JavaScript (the tile URL),
 * and French prose (the RGPD subprocessor list).
 *
 * Each failure is different and none is loud. A CSP that no longer
 * matches the tile URL gives a grey box with blocked requests in a
 * console nobody opens. An RGPD page naming a provider the site no longer
 * contacts — or worse, failing to name one it does — is a compliance
 * defect that no amount of testing the map itself would reveal.
 *
 * The map's browser-storage key is a fourth agreement of the same shape,
 * added when the map became expanded by default: JavaScript writes it,
 * module.json declares it to the consent banner and the cookie
 * preferences page, and nothing in either language can see the other.
 * AGENTS.md § Cookie consent makes the declaration mandatory, so a key
 * renamed on one side only is a site quietly storing something it never
 * told the visitor about.
 */
final class MapTilesTest extends TestCase
{
    public function testTheJavascriptDrawsTilesFromTheOriginTheCspAllows(): void
    {
        $js = (string) file_get_contents(self::root() . '/public/assets/js/camps-map.js');

        $this->assertStringContainsString(
            MapTiles::ORIGIN . '/',
            $js,
            'camps-map.js requests tiles from a host the CSP does not allow — every tile would be blocked.'
        );
    }

    public function testTheRgpdPageNamesTheTileAndGeocodingProvider(): void
    {
        $rgpd = (string) file_get_contents(self::root() . '/core/View/rgpd_default.html');

        $this->assertStringContainsString(
            MapTiles::PROVIDER_NAME,
            $rgpd,
            'The map hands a third party the IP of every chief who opens it; the RGPD page must say so.'
        );
        $this->assertStringContainsString('Nominatim', $rgpd);
    }

    public function testTheGeocoderCallsTheHostTheRgpdPageDescribes(): void
    {
        $service = (string) file_get_contents(self::root() . '/modules/camps/src/Service/GeocodingService.php');

        $this->assertStringContainsString(MapTiles::GEOCODER_ORIGIN . '/', $service);
    }

    public function testTheRgpdPromptTellsTheModelWhatToDoWhenTheModuleIsOff(): void
    {
        $prompt = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(self::root() . '/core/View/RgpdContentService.php')
        );

        // Without this rule the generated policy keeps naming a
        // subprocessor an installation without the module never contacts.
        $this->assertStringContainsString('module camps', $prompt);
        $this->assertStringContainsString("Fond de carte et géocodage", $prompt);
    }

    public function testLeafletIsVendoredRatherThanLoadedFromACdn(): void
    {
        $root = self::root();

        $this->assertFileExists($root . '/public/assets/vendor/leaflet/leaflet.js');
        $this->assertFileExists($root . '/public/assets/vendor/leaflet/leaflet.css');
        // Leaflet's stylesheet references these by relative path; without
        // them every marker renders as a broken image.
        $this->assertFileExists($root . '/public/assets/vendor/leaflet/images/marker-icon.png');
        $this->assertFileExists($root . '/public/assets/vendor/leaflet/images/marker-shadow.png');

        $list = (string) file_get_contents($root . '/modules/camps/views/list.html.twig');
        $this->assertStringNotContainsString('unpkg.com', $list);
        $this->assertStringNotContainsString('cdn.jsdelivr', $list);
    }

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

    public function testTheRgpdPageDescribesAMapThatIsOpenBeforeAnybodyAsksOnAWideScreen(): void
    {
        // The map used to be collapsed by default everywhere and the policy
        // said so, in those words. On a wide screen the tiles are now
        // fetched on load, and a sentence still promising the opposite is
        // not merely stale: it tells a reader their IP does not leave
        // unless they ask for a map, which on that screen is false.
        $rgpd = (string) file_get_contents(self::root() . '/core/View/rgpd_default.html');

        $this->assertStringContainsString(
            'dépliée par défaut sur un grand écran',
            $rgpd,
            'On a wide screen the map is open before anybody asks, and the policy has to say so.'
        );
        $this->assertStringNotContainsString(
            'repliée par défaut sur un grand écran',
            $rgpd,
            'A policy promising a folded map on the screen where it is open understates what leaves the browser.'
        );
    }

    public function testTheRgpdPageAlsoDescribesTheNarrowScreenWhereNothingIsFetched(): void
    {
        // The other half, and it must not be left out either: a phone
        // requests no tile at all until its reader opens the map. Saying
        // only « dépliée par défaut » would now overstate what leaves a
        // phone, which is the same failure in the other direction.
        $rgpd = (string) file_get_contents(self::root() . '/core/View/rgpd_default.html');

        $this->assertStringContainsString('repliée par défaut', $rgpd);
        $this->assertStringContainsString('aucune tuile', $rgpd);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
