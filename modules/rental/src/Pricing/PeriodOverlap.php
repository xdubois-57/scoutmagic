<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

use Core\Service\DateInput;

/**
 * The days two price periods have in common (issue #1355, D9).
 *
 * Two seasons covering the same day is a configuration mistake the engine
 * can only paper over — it applies the first one configured and warns on
 * the quote. So a new period that shares a day with another is refused when
 * it is added, and the ones already stored are pointed out, never changed.
 *
 * **Bounds are inclusive**, as in PricePeriod::covers(): two periods that
 * share their last and first day overlap. The three shapes are handled:
 *
 * - one-off against one-off: the intersection of two date ranges;
 * - yearly against yearly: the month/day pairs both cover, over a leap
 *   year so that 29 February counts, a range that wraps the new year
 *   (20/12 → 5/1) included;
 * - one-off against yearly: the days of the one-off range that the yearly
 *   one covers, every year it crosses.
 *
 * The answer is a list of runs of consecutive days, so a message can say
 * « du 25/8 au 31/8 » rather than list thirty dates.
 */
final class PeriodOverlap
{
    /** Any leap year: the calendar a yearly period's month/day pairs are laid on. */
    private const LEAP_YEAR = 2024;

    /**
     * A one-off period longer than this is compared on its first this-many
     * days only — a bound on the loop, never reached by a real season.
     */
    private const MAX_DAYS = 3700;

    /**
     * The runs of days $a and $b both cover, in order: each run is
     * `[first, last]`, as `Y-m-d` when a one-off period is involved and
     * as `m-d` when both recur yearly. Empty when they share no day.
     *
     * @return list<array{string, string}>
     */
    public static function commonRuns(PricePeriod $a, PricePeriod $b): array
    {
        if (!$a->recursYearly && !$b->recursYearly) {
            $first = max($a->startDate, $b->startDate);
            $last = min($a->endDate, $b->endDate);

            return $first <= $last ? [[$first, $last]] : [];
        }

        if ($a->recursYearly && $b->recursYearly) {
            return self::yearlyRuns($a, $b);
        }

        [$oneOff, $yearly] = $a->recursYearly ? [$b, $a] : [$a, $b];

        return self::runsOver($oneOff->startDate, $oneOff->endDate, $yearly, 'Y-m-d');
    }

    /**
     * Both recur: lay them on one leap year, then join a run ending on
     * 31 December to one starting on 1 January — the same stretch of the
     * calendar, seen from both ends of the year.
     *
     * @return list<array{string, string}>
     */
    private static function yearlyRuns(PricePeriod $a, PricePeriod $b): array
    {
        $runs = [];
        foreach (self::runsOver(self::LEAP_YEAR . '-01-01', self::LEAP_YEAR . '-12-31', $a, 'm-d') as $run) {
            $runs = array_merge($runs, self::runsWithin($run, $b));
        }

        $count = count($runs);
        if ($count > 1 && $runs[0][0] === '01-01' && $runs[$count - 1][1] === '12-31') {
            $runs[0] = [$runs[$count - 1][0], $runs[0][1]];
            array_pop($runs);
        }

        return $runs;
    }

    /**
     * The days of $run (`m-d` pairs on the leap year) that $period covers.
     *
     * @param array{string, string} $run
     * @return list<array{string, string}>
     */
    private static function runsWithin(array $run, PricePeriod $period): array
    {
        return self::runsOver(self::LEAP_YEAR . '-' . $run[0], self::LEAP_YEAR . '-' . $run[1], $period, 'm-d');
    }

    /**
     * The runs of days from $from to $to, inclusive, that $period covers.
     *
     * @return list<array{string, string}>
     */
    private static function runsOver(string $from, string $to, PricePeriod $period, string $format): array
    {
        // Stored `Y-m-d` values, read like every other stored date: a
        // malformed one compares as nothing rather than as today.
        $day = DateInput::iso($from);
        $end = DateInput::iso($to);
        if ($day === null || $end === null) {
            return [];
        }
        $runs = [];
        $open = null;
        $previous = $day;

        for ($i = 0; $day <= $end && $i < self::MAX_DAYS; $i++, $day = $day->modify('+1 day')) {
            if ($period->covers($day)) {
                $open ??= $day->format($format);
            } elseif ($open !== null) {
                $runs[] = [$open, $previous->format($format)];
                $open = null;
            }
            $previous = $day;
        }

        if ($open !== null) {
            $runs[] = [$open, $previous->format($format)];
        }

        return $runs;
    }

    /**
     * How a run reads in a sentence: « le 25/8 » or « du 25/8 au 31/8 »,
     * with the year when the dates carry one.
     *
     * @param array{string, string} $run
     */
    public static function describeRun(array $run): string
    {
        [$first, $last] = [self::shortDate($run[0]), self::shortDate($run[1])];

        return $first === $last ? 'le ' . $first : 'du ' . $first . ' au ' . $last;
    }

    /** « 1/7 → 31/8, chaque année » or « 15/8/2027 → 25/8/2027 ». */
    public static function describePeriod(PricePeriod $period): string
    {
        return $period->recursYearly
            ? self::shortDate(substr($period->startDate, 5)) . ' → ' . self::shortDate(substr($period->endDate, 5))
                . ', chaque année'
            : self::shortDate($period->startDate) . ' → ' . self::shortDate($period->endDate);
    }

    /** `Y-m-d` → « 25/8/2027 », `m-d` → « 25/8 ». */
    private static function shortDate(string $date): string
    {
        $parts = array_map('intval', explode('-', $date));

        return count($parts) === 3
            ? $parts[2] . '/' . $parts[1] . '/' . $parts[0]
            : $parts[1] . '/' . $parts[0];
    }
}
