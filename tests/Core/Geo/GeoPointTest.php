<?php

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Geo\GeoPoint;
use Core\Geo\GeoPointException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a human typed, as a point — the same refusals, in the same words, on
 * every form that places something on the map.
 */
final class GeoPointTest extends TestCase
{
    public function testTwoNumbersAreAPoint(): void
    {
        $point = GeoPoint::fromInput('50.443210', '4.887650');

        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(50.44321, $point->latitude, 0.0000001);
        $this->assertSame('50.443210, 4.887650', $point->line());
    }

    public function testACommaIsADecimalSeparator(): void
    {
        $this->assertSame('50.500000, 4.250000', GeoPoint::fromInput(' 50,5 ', '4,25')?->line());
    }

    public function testTwoBlankBoxesAreNoPointAtAll(): void
    {
        $this->assertNull(GeoPoint::fromInput('', '  '));
        $this->assertNull(GeoPoint::fromInput(null, null));
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function refusals(): array
    {
        return [
            'only a latitude' => ['50.4', '', 'latitude ET la longitude'],
            'only a longitude' => [null, '4.8', 'latitude ET la longitude'],
            'not a number' => ['cinquante', '4.8', 'doit être un nombre'],
            'latitude out of range' => ['91', '4.8', 'comprise entre -90'],
            'longitude out of range' => ['50', '-181', 'comprise entre -180'],
        ];
    }

    #[DataProvider('refusals')]
    public function testWhatIsNotAPointIsRefusedInASentence(?string $lat, ?string $lng, string $expected): void
    {
        $this->expectException(GeoPointException::class);
        $this->expectExceptionMessage($expected);

        GeoPoint::fromInput($lat, $lng);
    }

    public function testColumnsWithAnEmptyHalfAreNoPoint(): void
    {
        $this->assertNull(GeoPoint::fromColumns(null, '4.8'));
        $this->assertNull(GeoPoint::fromColumns('', ''));
        $this->assertSame('50.000000, 4.000000', GeoPoint::fromColumns('50', 4)?->line());
    }
}
