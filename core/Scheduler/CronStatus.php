<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

/**
 * What `Core\Scheduler\CronHealth` answers: is a real crontab driving this
 * installation, when did it last say so, and how often.
 *
 * Immutable and free of any I/O — every field is resolved once, by
 * CronHealth, against a single "now". A page that renders the same status
 * three times (a chip, a sentence, a warning) must not get three different
 * answers because two of them crossed a second boundary.
 */
final class CronStatus
{
    /** No trace of a real cron has ever been recorded on this installation. */
    public const STATE_NEVER = 'never';

    /** It ran at some point, but not recently enough to be trusted now. */
    public const STATE_STALE = 'stale';

    /** It ran within CronHealth::ACTIVE_WITHIN_SECONDS. */
    public const STATE_ACTIVE = 'active';

    /**
     * @param self::STATE_* $state
     * @param int|null $lastHeartbeatAt Unix timestamp of the last time public/cron.php STARTED (file, works pre-database).
     * @param int|null $lastFullPassAt Unix timestamp of the last time it completed far enough to reach the database.
     * @param int|null $medianIntervalSeconds Median gap between recorded passes, null with fewer than two of them.
     */
    public function __construct(
        public readonly string $state,
        public readonly ?int $lastHeartbeatAt,
        public readonly ?int $lastFullPassAt,
        public readonly ?int $medianIntervalSeconds,
        private readonly int $now
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    /**
     * The most recent trace of any kind — a started pass or a completed
     * one, whichever is newer. Null when there has never been either.
     */
    public function lastSeenAt(): ?int
    {
        $candidates = array_filter([$this->lastHeartbeatAt, $this->lastFullPassAt], static fn(?int $at): bool => $at !== null);

        return $candidates === [] ? null : max($candidates);
    }

    public function secondsSinceLastSeen(): ?int
    {
        $lastSeen = $this->lastSeenAt();

        return $lastSeen === null ? null : max(0, $this->now - $lastSeen);
    }

    /**
     * "Seen once, then nothing for a long time" — a harder verdict than
     * `stale`, which a one-minute crontab reaches after three minutes.
     * This is the one the support package reports as SILENCIEUX, and the
     * threshold it uses is CronHealth::STALE_AFTER_SECONDS.
     */
    public function isSilentBeyond(int $seconds): bool
    {
        $elapsed = $this->secondsSinceLastSeen();

        return $elapsed !== null && $elapsed > $seconds;
    }
}
