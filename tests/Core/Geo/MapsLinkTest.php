<?php

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\GeoPoint;
use Core\Geo\MapsLink;
use PHPUnit\Framework\TestCase;

/**
 * The route link (#703, #1345): the reader picks Plans, Google Maps or
 * Waze, and every one of them opens the same destination — the point when
 * there is one, the address otherwise.
 *
 * The three providers are named in three languages that cannot check each
 * other, like the messaging apps (Tests\Core\Phone\MessagingAppsTest) and
 * the map tiles (Tests\Core\Geo\MapTilesTest): the links here, the default
 * RGPD page and the rule the generated policy keeps.
 */
class MapsLinkTest extends TestCase
{
    public function testPlansOpensARouteToThePoint(): void
    {
        $this->assertSame(
            'https://maps.apple.com/?daddr=50.716000%2C4.611000&dirflg=d',
            MapsLink::directionsFor(MapsLink::APPLE, new GeoPoint(50.716, 4.611), 'Rue du Parc 1, Wavre')
        );
    }

    public function testGoogleMapsOpensARouteToThePoint(): void
    {
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=50.716000%2C4.611000',
            MapsLink::directionsFor(MapsLink::GOOGLE, new GeoPoint(50.716, 4.611), 'Rue du Parc 1, Wavre')
        );
    }

    public function testWazeNavigatesToThePointAndIgnoresTheAddressWhenThereIsOne(): void
    {
        $this->assertSame(
            'https://waze.com/ul?ll=50.716000%2C4.611000&navigate=yes',
            MapsLink::directionsFor(MapsLink::WAZE, new GeoPoint(50.716, 4.611), 'Rue du Parc 1, Wavre')
        );
    }

    /**
     * The address is what the reader sees; the point is what the link
     * carries. A precise point must never be replaced by a name a
     * gazetteer may place somewhere else.
     */
    public function testEveryProviderUsesThePointAndNeverTheDisplayedAddressWhenThereIsAPoint(): void
    {
        $point = new GeoPoint(50.716, 4.611);

        foreach (array_keys(MapsLink::providers()) as $provider) {
            $url = MapsLink::directionsFor($provider, $point, 'Une adresse très différente 99');
            $this->assertStringContainsString('50.716000', $url, $provider);
            $this->assertStringContainsString('4.611000', $url, $provider);
            $this->assertStringNotContainsString('adresse', $url, $provider);
        }
    }

    public function testWithoutAPointEveryProviderFallsBackOnTheTrimmedAddressEncodedForAUrl(): void
    {
        $address = ' Rue de l\'Église 12 & Cie, 5000 Namur ';
        $encoded = rawurlencode('Rue de l\'Église 12 & Cie, 5000 Namur');

        $this->assertSame(
            'https://maps.apple.com/?daddr=' . $encoded . '&dirflg=d',
            MapsLink::directionsFor(MapsLink::APPLE, null, $address)
        );
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=' . $encoded,
            MapsLink::directionsFor(MapsLink::GOOGLE, null, $address)
        );
        $this->assertSame(
            'https://waze.com/ul?q=' . $encoded . '&navigate=yes',
            MapsLink::directionsFor(MapsLink::WAZE, null, $address)
        );
    }

    public function testASpecialCharacterOfTheAddressCannotAddAParameterToTheLink(): void
    {
        foreach (array_keys(MapsLink::providers()) as $provider) {
            $url = MapsLink::directionsFor($provider, null, 'Rue 1&navigate=no#x');

            $this->assertStringNotContainsString('&navigate=no', $url, $provider);
            $this->assertStringNotContainsString('#x', $url, $provider);
        }
    }

    public function testTheProvidersAreExactlyThreeAndInTheOrderOfTheSheet(): void
    {
        $this->assertSame(
            [MapsLink::APPLE, MapsLink::GOOGLE, MapsLink::WAZE],
            array_keys(MapsLink::providers())
        );
        $this->assertSame(
            ['Plans', 'Google Maps', 'Waze'],
            array_values(MapsLink::providers())
        );
    }

    public function testAnUnknownProviderIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MapsLink::directionsFor('geo', null, 'Rue du Parc 1');
    }

    public function testTheChoicesCarryTheLabelAndTheLinkOfEachProvider(): void
    {
        $point = new GeoPoint(50.716, 4.611);
        $choices = MapsLink::choices($point, 'Rue du Parc 1, Wavre');

        $this->assertSame(['apple', 'google', 'waze'], array_column($choices, 'key'));
        $this->assertSame(['Plans', 'Google Maps', 'Waze'], array_column($choices, 'label'));
        foreach ($choices as $choice) {
            $this->assertSame(
                MapsLink::directionsFor($choice['key'], $point, 'Rue du Parc 1, Wavre'),
                $choice['url']
            );
        }
    }

    public function testNothingOpensAnAppSchemeOrGeoLink(): void
    {
        // Three providers on every device, no « other application » entry.
        foreach (MapsLink::choices(null, 'Rue du Parc 1') as $choice) {
            $this->assertStringStartsWith('https://', $choice['url']);
        }
    }

    public function testTheNoJavaScriptLinkIsTheWebLinkOfGoogleMaps(): void
    {
        $this->assertSame(
            MapsLink::directionsFor(MapsLink::GOOGLE, null, 'Rue du Parc 1'),
            MapsLink::fallback(null, 'Rue du Parc 1')
        );
    }

    public function testTheOriginsAreTheOnesTheRgpdPageNames(): void
    {
        $rgpd = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(dirname(__DIR__, 3) . '/core/View/rgpd_default.html')
        );

        foreach ([MapsLink::APPLE_HOST, MapsLink::GOOGLE_HOST, MapsLink::WAZE_HOST] as $host) {
            $this->assertStringContainsString($host, $rgpd, 'The RGPD page does not name ' . $host);
        }
    }

    public function testTheGeneratedPolicyKeepsTheRouteProviders(): void
    {
        $prompt = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(dirname(__DIR__, 3) . '/core/View/RgpdContentService.php')
        );

        foreach ([MapsLink::APPLE_HOST, MapsLink::GOOGLE_HOST, MapsLink::WAZE_HOST] as $host) {
            $this->assertStringContainsString($host, $prompt, 'The RGPD prompt does not keep ' . $host);
        }
    }

    public function testNoScriptWritesARouteLinkOfItsOwn(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/public/assets/js/*.js') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            $name = basename($file);
            $this->assertStringNotContainsString('maps.apple.com', $code, $name . ' writes a Plans link of its own.');
            $this->assertStringNotContainsString('google.com/maps', $code, $name . ' writes a Google Maps link of its own.');
            $this->assertStringNotContainsString('waze.com', $code, $name . ' writes a Waze link of its own.');
        }
    }
}
