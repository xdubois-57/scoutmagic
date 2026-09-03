<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats;

use Modules\UsageStats\Retention;
use PHPUnit\Framework\TestCase;

/**
 * Three scout years kept, and the boundary is 1 September — not 1 January,
 * which would cut a season in half and delete the autumn of a year whose
 * spring is still drawn on the twelve-month curve.
 */
class RetentionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('instants')]
    public function testTheCutoffIsTheFirstSeptemberOfTheThirdMostRecentSeason(string $now, string $expected): void
    {
        $this->assertSame($expected, Retention::cutoffMonth(new \DateTimeImmutable($now)));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function instants(): array
    {
        return [
            // 2026-08 is still the 2025-2026 season: keep 2023-09 onwards.
            'august, still last season' => ['2026-08-31 23:59:59', '2023-09'],
            // One second later the season has turned, and one more year goes.
            'september, the season has turned' => ['2026-09-01 00:00:00', '2024-09'],
            'mid-season' => ['2027-02-14 12:00:00', '2024-09'],
        ];
    }

    public function testTheKeptSpanIsThreeSeasonsWide(): void
    {
        $cutoff = Retention::cutoffMonth(new \DateTimeImmutable('2026-09-15'));

        $this->assertSame(3, Retention::SCOUT_YEARS_KEPT);
        // 2024-2025, 2025-2026, 2026-2027 — the running season and the two
        // before it.
        $this->assertSame('2024-09', $cutoff);
    }
}
