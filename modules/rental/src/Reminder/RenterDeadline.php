<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Reminder;

/**
 * When a step waiting on the renter counts as late (#708, IT-12): the day
 * its reminder would go out (`ReminderPlanner::renterDeadline()`), and the
 * date the renter was expected by, which is what a line says.
 */
final class RenterDeadline
{
    public function __construct(
        /** What the renter was expected by: a due date, or the contract's. */
        public readonly \DateTimeImmutable $expected,
        /** The first day the step counts as late — when its reminder goes. */
        public readonly \DateTimeImmutable $lateFrom
    ) {
    }

    public function isLate(\DateTimeImmutable $today): bool
    {
        return $this->lateFrom <= $today->setTime(0, 0);
    }
}
