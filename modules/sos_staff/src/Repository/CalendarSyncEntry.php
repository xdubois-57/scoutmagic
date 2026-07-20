<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

/**
 * One sos_calendar_sync row: bookkeeping linking a merged consecutive-duty
 * streak to the calendar module event it was synced into (module spec §5).
 * $calendarEventId is null when the calendar module was disabled at sync
 * time — the streak is still recorded so it isn't silently lost, just not
 * mirrored into a calendar.
 */
final class CalendarSyncEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $calendarEventId
    ) {
    }
}
