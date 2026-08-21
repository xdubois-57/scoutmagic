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
 */
final class BookingMilestone
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $isDone,
        public readonly bool $isApplicable = true,
        /** A date, an amount, a deadline — whatever makes the line concrete. */
        public readonly ?string $detail = null
    ) {
    }
}
