<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract DTO for consuming modules (ARCHITECTURE.md §7.5) — one
 * event sitting on a SECTION's own calendar, carrying the section it
 * belongs to.
 *
 * EventSummary answers "label this option in a picker" and deliberately
 * hides which calendar an event is on; this one answers "which section is
 * this event about", which is the whole question for a consumer whose own
 * authorization boundary is the section (Modules\Presences). Same event,
 * two contracts, because the two questions are not the same.
 *
 * $startTime is null for an all-day event, exactly like the column behind
 * it — a consumer rendering « samedi 13 septembre » never needs it, one
 * ordering two events on the same day does.
 */
final class SectionEvent
{
    public function __construct(
        public readonly int $id,
        public readonly int $sectionId,
        public readonly string $title,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?string $startTime
    ) {
    }
}
