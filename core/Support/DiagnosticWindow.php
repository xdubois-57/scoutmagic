<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

/**
 * The span of past activity a support package looks back over: the event
 * journal and the scheduled tasks' counts, read together (issue #846).
 *
 * **Seven days, not 48 hours.** A problem is often noticed days after what
 * caused it — a restore on Monday, a missing archive found on Friday — and
 * a journal that had already let go of `backup_restored` left the
 * diagnosis inconclusive. The other windows of the archive (server logs,
 * cron latency, request timelines) are about what is happening NOW and
 * keep their own.
 *
 * Defined once, so the two exports that must agree cannot drift apart, and
 * so every sentence that announces the span — the archive's README, the
 * consent screen, the sheets — reads it from here.
 */
final class DiagnosticWindow
{
    public const DAYS = 7;

    private function __construct()
    {
    }

    /** The start of the window, as stored in a DATETIME column. */
    public static function cutoff(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable())->modify('-' . self::DAYS . ' days')->format('Y-m-d H:i:s');
    }

    /** « 7 jours », the span as every text of the archive says it. */
    public static function label(): string
    {
        return self::DAYS . ' jours';
    }
}
