<?php

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\GeoPoint;
use Core\Geo\RoutingService;
use PHPUnit\Framework\TestCase;

/**
 * OSRM's demonstration server (#703): longitude first, the installation
 * named in the User-Agent, and every failure an ordinary null.
 */
class RoutingServiceTest extends TestCase
{
    public function testItAsksForADrivingRouteAndReadsItsDuration(): void
    {
        $asked = [];
        $fetch = static function (string $url, string $ua) use (&$asked): string {
            $asked = [$url, $ua];

            return '{"code":"Ok","routes":[{"duration":2519.6,"distance":41000}]}';
        };
        $service = new RoutingService('https://unite.example', $fetch);

        $seconds = $service->drivingSeconds(new GeoPoint(50.716, 4.611), new GeoPoint(50.5, 4.9));

        $this->assertSame(2520, $seconds);
        $this->assertSame(
            'https://router.project-osrm.org/route/v1/driving/4.611000,50.716000;4.900000,50.500000?overview=false',
            $asked[0]
        );
        $this->assertSame('ScoutMagic/1.0 (+https://unite.example)', $asked[1]);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function failures(): iterable
    {
        yield 'unreachable' => [null];
        yield 'not json' => ['<html>502 Bad Gateway</html>'];
        yield 'no route' => ['{"code":"NoRoute","routes":[]}'];
        yield 'no duration' => ['{"code":"Ok","routes":[{}]}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testEveryFailureIsAPlainNull(?string $body): void
    {
        $service = new RoutingService('', static fn(): ?string => $body);

        $this->assertNull($service->drivingSeconds(new GeoPoint(50.7, 4.6), new GeoPoint(50.5, 4.9)));
    }
}
