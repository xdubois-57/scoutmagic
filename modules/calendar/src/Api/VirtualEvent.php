<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * An event another module computes on the fly, rather than one stored in
 * `calendar_events` (ARCHITECTURE.md §7.6).
 *
 * The providing module keeps the truth — a booking, a duty roster, a
 * deadline — and the calendar renders it. Nothing is written to
 * `calendar_events`, so there is no copy to keep in step and no way for the
 * two to disagree.
 *
 * **`$uid` is the provider's responsibility and must be stable.** It is what
 * lets the same underlying thing appear once when a reader subscribes to
 * two calendars that both carry it, and what lets a calendar client update
 * an event in place rather than showing a second copy of it every time the
 * feed is fetched. Derive it from the source's own identity
 * (`rental-booking-42@scoutmagic`), never from a position in a list or from
 * anything that changes when the event does.
 *
 * **`$isCancelled` produces `STATUS:CANCELLED`, not an omission.** A
 * subscriber who already has the event needs to be told it is off; silently
 * dropping it from the feed leaves it in their calendar forever.
 */
final class VirtualEvent
{
    public function __construct(
        /** Stable and globally unique — see the class docblock. */
        public readonly string $uid,
        public readonly int $calendarId,
        public readonly string $title,
        /** `Y-m-d`. */
        public readonly string $startDate,
        /** `Y-m-d`; the last day the event occupies, inclusive. */
        public readonly string $endDate,
        /** `H:i` or `H:i:s`, or null for an all-day event. */
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
        public readonly ?string $location = null,
        public readonly ?string $description = null,
        /** A link back into the providing module, for a reader allowed to follow it. */
        public readonly ?string $url = null,
        public readonly bool $isCancelled = false,
        /**
         * Bumped by the provider whenever the event's own details change,
         * so a subscribed client replaces its copy instead of ignoring the
         * update (RFC 5545 §3.8.7.4).
         */
        public readonly int $sequence = 0,
        /** When the underlying thing last changed, for `LAST-MODIFIED`. */
        public readonly ?\DateTimeImmutable $updatedAt = null,
        /**
         * Not settled yet — `STATUS:TENTATIVE` (RFC 5545 §3.8.1.11).
         *
         * The third value the standard defines, and the honest one for
         * anything a human still has to agree to: a rental request that
         * has been received but not confirmed is neither CONFIRMED nor
         * CANCELLED, and calling it CONFIRMED is how somebody blocks a
         * weekend in their own calendar for a booking that is later
         * refused. Ignored when $isCancelled is true — cancelled wins.
         */
        public readonly bool $isTentative = false
    ) {
    }

    /** The RFC 5545 `STATUS` value this event publishes. */
    public function icsStatus(): string
    {
        if ($this->isCancelled) {
            return 'CANCELLED';
        }

        return $this->isTentative ? 'TENTATIVE' : 'CONFIRMED';
    }

    public function isAllDay(): bool
    {
        return $this->startTime === null;
    }

    /** Whether this event touches [$from, $to], both inclusive. */
    public function overlaps(string $from, string $to): bool
    {
        return $this->startDate <= $to && $this->endDate >= $from;
    }
}
