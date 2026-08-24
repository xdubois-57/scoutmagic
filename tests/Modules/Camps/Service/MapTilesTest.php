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
        $prompt = (string) file_get_contents(self::root() . '/core/View/RgpdContentService.php');

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

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
