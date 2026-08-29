<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract DTO for consuming modules (ARCHITECTURE.md §7.5) — a
 * calendar event as seen from outside the calendar module: no internal
 * calendar_id, no visibility enum, just enough to label a picker option.
 */
final class EventSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $calendarName,
        public readonly string $startDate,
        public readonly string $endDate,
        /**
         * The event's free-text description, when it has one — added for
         * finance's categorization hints, which quote it to the model.
         * Null for a picker that only needs a label.
         */
        public readonly ?string $description = null
    ) {
    }
}
