<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Debug;

/**
 * Opt-in, per-request timing/memory checkpoints for diagnosing slow page
 * loads in production — activated by `?debug=1` on any URL, but only ever
 * written to the event journal for an authenticated admin (gated by the
 * caller, not this class, since role resolution happens well after this
 * needs to start recording). A plain static accumulator rather than an
 * injected service: the thing being measured is the whole request,
 * starting before most services exist, and ending after the poor-man's-
 * cron tail that runs past the point normal request-scoped objects are
 * still in scope for public/index.php to hand out.
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
            self::$active = isset($_GET['debug']);
            self::$start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        }

        return self::$active;
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
