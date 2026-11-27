<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Pricing;

use Modules\Rental\Pricing\PeriodOverlap;
use Modules\Rental\Pricing\PricePeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which days two price periods share (issue #1355, D9): bounds included,
 * one-off and yearly, across the new year.
 */
final class PeriodOverlapTest extends TestCase
{
    private static function oneOff(string $start, string $end): PricePeriod
    {
        return new PricePeriod(1, 'Ponctuelle', $start, $end, false);
    }

    private static function yearly(string $start, string $end): PricePeriod
    {
        return new PricePeriod(2, 'Annuelle', $start, $end, true);
    }

    /** @return array<string, array{PricePeriod, PricePeriod, list<array{string, string}>}> */
    public static function cases(): array
    {
        return [
            'one-off / one-off, a stretch in common' => [
                self::oneOff('2027-07-01', '2027-08-31'), self::oneOff('2027-08-15', '2027-09-15'),
                [['2027-08-15', '2027-08-31']],
            ],
            'one-off / one-off, sharing only the boundary day' => [
                self::oneOff('2027-07-01', '2027-08-31'), self::oneOff('2027-08-31', '2027-09-15'),
                [['2027-08-31', '2027-08-31']],
            ],
            'one-off / one-off, back to back' => [
                self::oneOff('2027-07-01', '2027-08-31'), self::oneOff('2027-09-01', '2027-09-15'),
                [],
            ],
            'yearly / yearly' => [
                self::yearly('2026-07-01', '2026-08-31'), self::yearly('2025-08-25', '2025-09-15'),
                [['08-25', '08-31']],
            ],
            'yearly / yearly, back to back' => [
                self::yearly('2026-07-01', '2026-08-31'), self::yearly('2026-09-01', '2026-09-15'),
                [],
            ],
            'yearly across the new year / yearly in January' => [
                self::yearly('2026-12-20', '2027-01-05'), self::yearly('2026-01-03', '2026-01-10'),
                [['01-03', '01-05']],
            ],
            'two yearly periods across the new year share one stretch, not two' => [
                self::yearly('2026-12-20', '2027-01-05'), self::yearly('2026-12-28', '2027-01-02'),
                [['12-28', '01-02']],
            ],
            'one-off / yearly, every year the one-off crosses' => [
                self::oneOff('2027-08-15', '2027-09-15'), self::yearly('2026-07-01', '2026-08-31'),
                [['2027-08-15', '2027-08-31']],
            ],
            'one-off over the new year / yearly across it' => [
                self::oneOff('2027-12-30', '2028-01-10'), self::yearly('2026-12-20', '2027-01-05'),
                [['2027-12-30', '2028-01-05']],
            ],
            'one-off / yearly with nothing in common' => [
                self::oneOff('2027-03-01', '2027-03-31'), self::yearly('2026-07-01', '2026-08-31'),
                [],
            ],
            'one-off over two summers / yearly summer: two stretches' => [
                self::oneOff('2027-08-01', '2028-07-15'), self::yearly('2026-07-01', '2026-08-31'),
                [['2027-08-01', '2027-08-31'], ['2028-07-01', '2028-07-15']],
            ],
        ];
    }

    /** @param list<array{string, string}> $expected */
    #[DataProvider('cases')]
    public function testTheDaysInCommonAreFoundEitherWayRound(PricePeriod $a, PricePeriod $b, array $expected): void
    {
        $this->assertSame($expected, PeriodOverlap::commonRuns($a, $b));
        $this->assertSame($expected === [], PeriodOverlap::commonRuns($b, $a) === []);
    }

    public function testARunAndAPeriodReadAsASentenceSays(): void
    {
        $this->assertSame('du 25/8 au 31/8', PeriodOverlap::describeRun(['08-25', '08-31']));
        $this->assertSame('le 31/8/2027', PeriodOverlap::describeRun(['2027-08-31', '2027-08-31']));
        $this->assertSame('1/7 → 31/8, chaque année', PeriodOverlap::describePeriod(self::yearly('2026-07-01', '2026-08-31')));
        $this->assertSame('15/8/2027 → 25/8/2027', PeriodOverlap::describePeriod(self::oneOff('2027-08-15', '2027-08-25')));
    }
}
