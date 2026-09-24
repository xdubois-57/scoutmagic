<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * One line of the manager's checklist (§6.15).
 *
 * `$isApplicable` is what keeps the checklist honest. A booking on an asset
 * with no security deposit configured must not show an unticked "Caution
 * reçue" forever — an unreachable item reads as work outstanding, and a
 * checklist nobody can finish is one nobody reads.
 *
 * **How the line gets ticked is part of the line** (issue #462, D6): its
 * nature, the sentence saying what it asks, and the one action that moves
 * it on. The other decisions still open are the booking's, not a line's:
 * `BookingJourney::otherDecisions()` offers them once, in the heading. Without them the page could say
 * that a line was unticked, but not whether that was the manager's to do,
 * the renter's, or nobody's but the site's.
 */
final class BookingMilestone
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $isDone,
        public readonly bool $isApplicable = true,
        /** A date, an amount, a deadline — whatever makes the line concrete. */
        public readonly ?string $detail = null,
        public readonly MilestoneKind $kind = MilestoneKind::DERIVED,
        /** What the line asks, while it is not done: one sentence. */
        public readonly ?string $explanation = null,
        /**
         * The one action that moves this line on (D7) — never a refusal
         * nor a cancellation. Null when there is nothing to press: the
         * line derives itself, waits on the renter, or is ticked by hand.
         */
        public readonly ?MilestoneAction $action = null
    ) {
    }
}
