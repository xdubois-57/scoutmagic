<?php

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
        public readonly string $endDate
    ) {
    }
}
