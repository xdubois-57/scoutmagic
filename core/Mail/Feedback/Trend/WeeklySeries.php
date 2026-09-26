<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Trend;

/**
 * A measure week by week, with a hole wherever the week does not carry
 * enough evidence to say anything (issue #420).
 *
 * **One class for both trends**, because the three decisions that shape
 * them are the same three decisions — the week, the threshold and the left
 * edge — and DMARC and the seed mailboxes differ only in what they count.
 * Two copies of this would be two chances to answer « is the last week
 * partial » differently, and the screens would eventually disagree about
 * the same week.
 *
 * **The ISO calendar week, and the current one is drawn as it fills**
 * (maintainer's arbitration on #420). A point is « the week of the 14th »,
 * which a rolling seven-day slice cannot name; the admitted price is that
 * the last point — the one somebody is looking at — still moves. It is
 * flagged {@see self::$partial} so a screen can say so rather than let a
 * half-week read as a drop.
 *
 * **Below the threshold there is NO point, and the line breaks.** A hole
 * says « we do not know », which is the truth, and nothing can be read
 * across it. A greyed or zeroed point is eventually read like the others —
 * that is the whole reason this is a hole and not a value.
 *
 * **The left edge is the purge**, handed in by the caller from the
 * retention constant that actually deletes the rows, never restated here.
 * A curve that began before the purge would promise a « before » that was
 * deleted; one that began at the first row would promise that nothing was
 * sent before the site existed. Both are wrong in the same way, and the
 * hole is what makes them the same answer: « purged » and « never
 * happened » are both « not measured », which is the only thing this
 * series can honestly assert about a week it has no rows for.
 */
final class WeeklySeries
{
    /**
     * `o` is the ISO-8601 year and `W` its week — which is not `Y` and not
     * `%W`. In the last days of December the two years differ, and a key
     * built from `Y` would file week 1 of 2027 under 2026 and draw a point
     * a year out of place.
     */
    private const KEY_FORMAT = 'o-\WW';

    /**
     * @param list<array{week: string, from: \DateTimeImmutable, value: ?float, sample: int,
     *     partial: bool}> $points
     */
    private function __construct(public readonly array $points)
    {
    }

    /**
     * **The bucketing is done here, in PHP, and deliberately not in SQL.**
     * MySQL's `YEARWEEK(…, 3)` is the ISO week and SQLite has no
     * equivalent — `strftime('%W')` is a different week that starts on
     * Sunday — so a `GROUP BY` would give one answer under the engine CI
     * runs and another under the one a local checkout falls back to. The
     * same query on both engines, bucketed by a single `DateTimeImmutable`
     * here, cannot drift that way.
     *
     * **`sample` and `total` are separate on purpose, and the seed boxes are
     * why.** There the evidence is counted in MAILINGS — five copies of one
     * mailing to five boxes say one thing five times, which is the noise
     * `DomainRouting::MINIMUM_RUNS` exists to refuse — while the ratio has to
     * stay inbox copies over answered copies, because that is the share the
     * ranking screen already shows and a trend computing it differently would
     * be a second answer to the same question. For DMARC the two coincide:
     * the evidence and the denominator are both messages, and the caller
     * passes the same figure twice rather than this class guessing.
     *
     * @param iterable<array{at: \DateTimeImmutable, sample: int, hits: int, total: int}> $rows
     *   one row per measured event, already summed per event by the caller so
     *   this never holds more of them than there are events
     * @param int $minimumSample the evidence a week needs before its ratio
     *   means anything — counted in whatever the caller's `sample` counts,
     *   which is its business and not this class's
     */
    public static function build(
        iterable $rows,
        int $minimumSample,
        \DateTimeImmutable $edge,
        \DateTimeImmutable $now
    ): self {
        $tallies = [];
        foreach ($rows as $row) {
            $key = $row['at']->format(self::KEY_FORMAT);
            $tallies[$key]['sample'] = ($tallies[$key]['sample'] ?? 0) + $row['sample'];
            $tallies[$key]['hits'] = ($tallies[$key]['hits'] ?? 0) + $row['hits'];
            $tallies[$key]['total'] = ($tallies[$key]['total'] ?? 0) + $row['total'];
        }

        // **Refused rather than guarded around.** A threshold of zero would
        // make « no evidence » satisfy the test and then divide by it, and a
        // second check on the sample beside the threshold would be one
        // boundary with two mechanisms — the shape whose mutation survives
        // because each copy hides the other's absence.
        if ($minimumSample < 1) {
            throw new \InvalidArgumentException('A weekly trend needs a sample threshold of at least one.');
        }

        $current = $now->format(self::KEY_FORMAT);
        $points = [];

        foreach (self::weeksBetween($edge, $now) as $week) {
            $sample = $tallies[$week['key']]['sample'] ?? 0;
            $hits = $tallies[$week['key']]['hits'] ?? 0;
            $total = $tallies[$week['key']]['total'] ?? 0;

            $points[] = [
                'week' => $week['key'],
                'from' => $week['from'],
                // The hole. Never 0.0 for « no evidence », which would be
                // indistinguishable from a real week where nothing passed.
                //
                // **`$total < 1` is a second condition and not a duplicate of
                // the threshold**, because `sample` and `total` count
                // different things: a caller whose evidence is mailings can
                // hand over a week with enough mailings and nothing answered
                // yet. Without it that week divides by zero rather than
                // reading « not measured ».
                //
                // Cast before dividing: PHP's `/` hands back an INT when the
                // division comes out exact, so a week where everything passed
                // would be `1` where every other week is a float — and the
                // declared `?float` would be a shape this class does not keep.
                'value' => $sample >= $minimumSample && $total >= 1 ? (float) $hits / $total : null,
                'sample' => $sample,
                'partial' => $week['key'] === $current,
            ];
        }

        return new self($points);
    }

    /** Whether any week at all carried enough evidence to be drawn. */
    public function isEmpty(): bool
    {
        foreach ($this->points as $point) {
            if ($point['value'] !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every ISO week the edge and now fall in, oldest first, the two ends
     * included.
     *
     * **Walked a week at a time from the Monday of the edge's week**, rather
     * than derived from a day count divided by seven: the edge is a
     * retention constant subtracted from now, so it lands on whatever
     * weekday that arithmetic gives, and a division would put the first
     * bucket's boundary there instead of on a Monday — filing the days
     * before it under the wrong week.
     *
     * `modify('+7 days')` because it is calendar arithmetic. `modify()`
     * works in civil time, so `'+168 hours'` happens to give the same
     * answer across a daylight-saving change — measured, not assumed — but
     * timestamp arithmetic (`+ 604800`) would not, and the walk should not
     * be the reason somebody has to know which.
     *
     * @return list<array{key: string, from: \DateTimeImmutable}>
     */
    private static function weeksBetween(\DateTimeImmutable $edge, \DateTimeImmutable $now): array
    {
        $cursor = $edge->modify('monday this week')->setTime(0, 0);
        $last = $now->modify('monday this week')->setTime(0, 0);

        $weeks = [];
        while ($cursor <= $last) {
            $weeks[] = ['key' => $cursor->format(self::KEY_FORMAT), 'from' => $cursor];
            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }
}
