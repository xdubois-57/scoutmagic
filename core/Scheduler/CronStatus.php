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
     * Two recorded starts after the last recorded end: passes run one at a
     * time, so the earlier of the two started and never finished. One is
     * not enough — that one may simply be the pass running right now.
     */
    public const UNFINISHED_STARTS_THRESHOLD = 2;

    /**
     * @param self::STATE_* $state
     * @param int|null $lastHeartbeatAt Unix timestamp of the last time public/cron.php was launched (file, works
     *     pre-database, written even by a pass that stands down on the lock).
     * @param int|null $lastPassStartAt Unix timestamp of the last pass that took the lock and started its work
     *     (`cron_last_run`). Says nothing about whether that pass finished.
     * @param int|null $medianIntervalSeconds Median gap between recorded passes, null with fewer than two of them.
     * @param int|null $lastPassEndAt Unix timestamp of the last pass that went all the way through
     *     (`Core\Scheduler\CronPassEnd`), null when none has ever been recorded.
     * @param int $startsSinceLastPassEnd How many pass starts the ring buffer holds after $lastPassEndAt (0 when
     *     that is null: never recorded is unknown, see CronHealth::startsAfter()).
     */
    public function __construct(
        public readonly string $state,
        public readonly ?int $lastHeartbeatAt,
        public readonly ?int $lastPassStartAt,
        public readonly ?int $medianIntervalSeconds,
        private readonly int $now,
        public readonly ?int $lastPassEndAt = null,
        public readonly int $startsSinceLastPassEnd = 0
    ) {
    }

    /**
     * « The cron fires, but its passes do not get to the end » — the case
     * every start-of-pass stamp hides: a pass killed by the host, out of
     * memory, or by a fatal error still leaves the cron looking active.
     *
     * Independent of $state on purpose. A cron can be `active` (it fires
     * every minute) and failing all the same, and a page that folded the
     * two into one verdict would have to choose which half to hide.
     */
    public function passesDoNotFinish(): bool
    {
        return $this->state !== self::STATE_NEVER
            && $this->lastPassEndAt !== null
            && $this->startsSinceLastPassEnd >= self::UNFINISHED_STARTS_THRESHOLD;
    }

    /** Seconds since the last pass that finished, null when none ever did. */
    public function secondsSinceLastPassEnd(): ?int
    {
        return $this->lastPassEndAt === null ? null : max(0, $this->now - $this->lastPassEndAt);
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
        $candidates = array_filter(
            [$this->lastHeartbeatAt, $this->lastPassStartAt],
            static fn(?int $at): bool => $at !== null
        );

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
