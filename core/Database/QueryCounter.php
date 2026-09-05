<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * Process-wide tally of the SQL statements a request has executed, and of
 * the time the database spent on them.
 *
 * Fed by InstrumentedPdo, read by Core\Debug\RequestTimeline, which stamps
 * both figures on every checkpoint so a `?debug=1` timeline says not only
 * *where* the time went but *how many statements* each segment issued —
 * the one number that makes an N+1 visible without reading the code. A
 * static accumulator for the same reason RequestTimeline is one: the thing
 * being counted is the whole request, from before most services exist.
 *
 * Always on: an integer increment and a microtime() difference per
 * statement cost nothing measurable, and a counter that has to be switched
 * on is a counter nobody looks at when it matters.
 */
final class QueryCounter
{
    private static int $count = 0;
    private static float $seconds = 0.0;

    public static function record(float $seconds): void
    {
        self::$count++;
        self::$seconds += $seconds;
    }

    public static function count(): int
    {
        return self::$count;
    }

    public static function milliseconds(): float
    {
        return round(self::$seconds * 1000, 1);
    }

    public static function reset(): void
    {
        self::$count = 0;
        self::$seconds = 0.0;
    }
}
