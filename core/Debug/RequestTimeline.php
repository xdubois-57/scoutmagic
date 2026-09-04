<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Debug;

use Core\Database\QueryCounter;

/**
 * Opt-in, per-request timing/memory checkpoints for diagnosing slow page
 * loads in production — activated by `?debug=1` on any URL, or for every
 * request while a Core\Debug\MeasurementWindow is open, but only ever
 * written to the event journal for an authenticated admin or inside that
 * window (gated by the caller, not this class, since role resolution
 * happens well after this needs to start recording). A plain static accumulator rather than an
 * injected service: the thing being measured is the whole request,
 * starting before most services exist, and ending past the point normal
 * request-scoped objects are still in scope for public/index.php to hand
 * out.
 *
 * Every entry also carries the running SQL statement count and the time
 * the database spent (Core\Database\QueryCounter, fed by every
 * Core\Database\InstrumentedPdo): the delta between two checkpoints is
 * the segment's own query count, which is how an N+1 is read off a
 * timeline instead of off the code.
 *
 * Recording is unconditional once active (checked once, cached) — the
 * cost of a `microtime(true)` + array push per mark() call is negligible,
 * so there is no need to gate individual call sites on isActive() beyond
 * what mark() already does internally.
 */
final class RequestTimeline
{
    private static ?bool $active = null;
    private static float $start = 0.0;

    /** @var array<int, array{label: string, t_ms: float, mem_mb: float}> */
    private static array $entries = [];

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = self::wasRequested();
            self::$start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        }

        return self::$active;
    }

    /**
     * Whether THIS request asked for its timeline with `?debug=1` — as
     * opposed to being recorded because a measurement window is open
     * (Core\Debug\MeasurementWindow). The two are journaled differently:
     * only the explicit request also gets the early `debug_request_hit`
     * entry, and only an admin's explicit request is journaled outside a
     * window.
     */
    public static function wasRequested(): bool
    {
        return isset($_GET['debug']);
    }

    /**
     * Records this request whatever its query string: what a measurement
     * window does to every request while it is open. Called before the
     * first mark(); the clock still starts at the request's own start.
     */
    public static function activate(): void
    {
        self::isActive();
        self::$active = true;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function mark(string $label, array $extra = []): void
    {
        if (!self::isActive()) {
            return;
        }

        self::$entries[] = [
            'label' => $label,
            't_ms' => round((microtime(true) - self::$start) * 1000, 1),
            'mem_mb' => round(memory_get_usage(true) / 1_048_576, 1),
            // Cumulative, like t_ms: the difference between two checkpoints
            // is what that segment issued and what the database spent on it.
            'sql' => QueryCounter::count(),
            'sql_ms' => QueryCounter::milliseconds(),
        ] + $extra;
    }

    /**
     * @return array<int, array{label: string, t_ms: float, mem_mb: float}>
     */
    public static function getEntries(): array
    {
        return self::$entries;
    }
}
