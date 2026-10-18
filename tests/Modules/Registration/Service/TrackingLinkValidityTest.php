<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Service\TrackingLinkValidity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How long a request's tracking link works (issue #932): calendar months
 * from the moment of filing, the last valid day of a shorter arrival month,
 * the same time of day, and a stored setting that cannot give a link an
 * absurd lifetime.
 */
class TrackingLinkValidityTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function expiries(): array
    {
        return [
            'the ticket\'s own example' => ['2026-10-18 12:00:00', 18, '2028-04-18 12:00:00'],
            'one month' => ['2026-10-18 12:00:00', 1, '2026-11-18 12:00:00'],
            'across the year end' => ['2026-12-15 09:30:00', 2, '2027-02-15 09:30:00'],
            'twelve months' => ['2026-10-18 12:00:00', 12, '2027-10-18 12:00:00'],
            'a 31st clamped to a 30-day month' => ['2026-08-31 10:00:00', 2, '2026-10-31 10:00:00'],
            'a 31st clamped to the end of a short February' => ['2026-08-31 10:00:00', 6, '2027-02-28 10:00:00'],
            'a 29 February kept in a leap year' => ['2027-08-29 23:59:59', 6, '2028-02-29 23:59:59'],
            'a 30 August clamped to 29 February of a leap year' => ['2027-08-30 08:00:00', 6, '2028-02-29 08:00:00'],
            'a 29 February filed, arriving in a common year' => ['2028-02-29 06:00:00', 12, '2029-02-28 06:00:00'],
            'a 29 February filed, arriving in a leap year' => ['2028-02-29 06:00:00', 48, '2032-02-29 06:00:00'],
            'a 31 January, one month on' => ['2026-01-31 00:00:00', 1, '2026-02-28 00:00:00'],
            'the upper bound' => ['2026-10-18 12:00:00', 120, '2036-10-18 12:00:00'],
        ];
    }

    #[DataProvider('expiries')]
    public function testExpiresAtAddsCalendarMonthsWithoutOverflowing(string $received, int $months, string $expected): void
    {
        $this->assertSame(
            $expected,
            TrackingLinkValidity::expiresAt(new \DateTimeImmutable($received), $months)->format('Y-m-d H:i:s')
        );
    }

    /** The time zone of the filing is the one the expiry is stated in. */
    public function testTheExpiryStaysInTheZoneOfTheFiling(): void
    {
        $received = new \DateTimeImmutable('2026-03-10 02:30:00', new \DateTimeZone('Europe/Brussels'));

        // The wall-clock time is kept across the summer-time change.
        $expires = TrackingLinkValidity::expiresAt($received, 6);

        $this->assertSame('Europe/Brussels', $expires->getTimezone()->getName());
        $this->assertSame('2026-09-10 02:30:00', $expires->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function storedValues(): array
    {
        return [
            'the default' => ['18', 18],
            'padded' => [' 6 ', 6],
            'the lower bound' => ['1', 1],
            'the upper bound' => ['120', 120],
            'an integer' => [24, 24],
            'zero' => ['0', 18],
            'beyond the bound' => ['121', 18],
            'negative' => ['-3', 18],
            'a decimal' => ['1.5', 18],
            'words' => ['dix-huit', 18],
            'empty' => ['', 18],
            'null' => [null, 18],
        ];
    }

    #[DataProvider('storedValues')]
    public function testAStoredValueOutsideTheBoundsReadsAsTheDefault(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, TrackingLinkValidity::monthsFrom($stored));
    }
}
