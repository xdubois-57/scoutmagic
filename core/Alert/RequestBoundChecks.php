<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

/**
 * The throttle for the two checks that cannot run in the scheduled task.
 *
 * `CronSilenceCheck` cannot, because a cron that has stopped never runs
 * the task that would notice — an alert about the engine cannot live
 * inside the engine. `HttpsCheck` cannot, because a scheme belongs to a
 * request and a CLI pass has none. Both therefore run on ordinary web
 * requests, which raises the obvious problem this class exists for: a
 * check on every request is a database write on every request.
 *
 * So: at most once every {@see INTERVAL_SECONDS}, decided by the mtime of
 * one marker file. One `stat()` per request, and nothing else, on the
 * requests that are not due.
 *
 * A file rather than a `settings` row for the same reason
 * {@see \Core\Storage\DiskBudget} caches to a file: this has to work on
 * the request path without a write, and a settings row would be a query
 * and a cache invalidation to save a query. `storage/temp/` is deliberate
 * too — losing the marker to a sweep costs one extra evaluation, which is
 * the cheapest possible failure mode.
 *
 * **The interval is not a threshold.** Missing a pass delays an alert by a
 * quarter of an hour on a site somebody is browsing; the alerts themselves
 * are measured in hours and days (`AlertThresholds`), so nothing here
 * needs to be tight.
 */
final class RequestBoundChecks
{
    public const INTERVAL_SECONDS = 900;

    private const MARKER_RELATIVE_PATH = 'temp/operational-request-checks';

    public function __construct(private readonly string $storagePath)
    {
    }

    /**
     * Whether the request-bound checks are due.
     *
     * A missing marker means due — a fresh installation, or one whose
     * `storage/temp/` was just swept, should evaluate rather than wait out
     * an interval it has no record of.
     */
    public function due(?int $now = null): bool
    {
        $mtime = @filemtime($this->markerPath());
        if ($mtime === false) {
            return true;
        }

        return (($now ?? time()) - $mtime) >= self::INTERVAL_SECONDS;
    }

    /**
     * Claims this interval, before the checks run rather than after.
     *
     * Before, because two concurrent requests that both find the marker
     * stale would otherwise both evaluate; claiming first narrows that to
     * the width of a `touch()`. It is not a lock and does not pretend to
     * be one — the cost of a double evaluation is one redundant read, and
     * a real lock for that would be more machinery than the problem.
     *
     * Silent on failure: a read-only `storage/temp/` degrades to
     * "evaluate every request", which is worse for performance and
     * perfectly correct, and must never turn a page into a 500.
     */
    public function markRun(): void
    {
        $path = $this->markerPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        @touch($path);
    }

    private function markerPath(): string
    {
        return rtrim($this->storagePath, '/') . '/' . self::MARKER_RELATIVE_PATH;
    }
}
