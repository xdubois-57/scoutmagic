<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

/**
 * One evening of a section's year, as the register reads it.
 *
 * **`$pointed` is the field that keeps the page honest.** An evening
 * nobody opened has every animé « non renseigné », which is not a 0 %
 * evening — it is an evening the site knows nothing about. Drawing it as
 * a bar at zero would invent a collapse in attendance out of a Saturday
 * somebody forgot to point, so it is excluded from the graph and from the
 * average, and listed as unpointed instead.
 */
final class RegisterEvent
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $title,
        public readonly string $startDate,
        public readonly bool $pointed,
        public readonly int $present,
        public readonly int $excused,
        public readonly int $absent,
        public readonly int $notRecorded,
        /** Present animés over the section's whole roster, 0–100. */
        public readonly int $rate
    ) {
    }
}
