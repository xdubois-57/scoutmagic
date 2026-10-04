<?php

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\GeoPoint;
use Core\Geo\MapsLink;
use PHPUnit\Framework\TestCase;

/**
 * The route link (#703): the reader's own map application, chosen from
 * their device, to the point when there is one and the address otherwise.
 * {@see MapsLink::url()} — a place on OpenStreetMap — is unchanged.
 */
class MapsLinkTest extends TestCase
{
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15';
    private const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 Safari/605.1.15';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/130.0 Mobile';
    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    public function testAnAppleDeviceGetsPlansWithARouteFromWhereItIs(): void
    {
        $point = new GeoPoint(50.716, 4.611);

        $this->assertSame(
            'https://maps.apple.com/?daddr=50.716000%2C4.611000&dirflg=d',
            MapsLink::directions($point, 'Rue du Parc 1, Wavre', self::IPHONE)
        );
        $this->assertStringStartsWith('https://maps.apple.com/', MapsLink::directions($point, '', self::MAC));
    }

    public function testAndroidGetsAGeoLinkSoThePhoneOffersItsOwnApplication(): void
    {
        $this->assertSame(
            'geo:50.716000,4.611000?q=50.716000%2C4.611000',
            MapsLink::directions(new GeoPoint(50.716, 4.611), 'Rue du Parc 1, Wavre', self::ANDROID)
        );
        $this->assertSame(
            'geo:0,0?q=Rue%20du%20Parc%201%2C%20Wavre',
            MapsLink::directions(null, ' Rue du Parc 1, Wavre ', self::ANDROID)
        );
    }

    public function testAnythingElseGetsGoogleMapsInTheBrowser(): void
    {
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=Rue%20du%20Parc%201%2C%20Wavre',
            MapsLink::directions(null, 'Rue du Parc 1, Wavre', self::WINDOWS)
        );
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination=50.716000%2C4.611000',
            MapsLink::directions(new GeoPoint(50.716, 4.611), '', '')
        );
    }

    public function testTheShowingLinkStillNamesOpenStreetMap(): void
    {
        $this->assertStringStartsWith(
            'https://www.openstreetmap.org/',
            MapsLink::url(new GeoPoint(50.716, 4.611), 'Rue du Parc 1, Wavre')
        );
    }
}
