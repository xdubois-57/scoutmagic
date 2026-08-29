<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): render a
 * standalone ICS calendar from VIRTUAL events only — the §7.6 value
 * object a contributing module already knows how to build. Introduced
 * for the renter's one-event tracking feed (rental); a consumer never
 * sees the calendar module's own event rows through this, which is what
 * keeps it a read-only formatting capability rather than a window onto
 * another module's data.
 */
interface IcsFeedBuilderInterface
{
    /**
     * @param list<VirtualEvent> $virtualEvents
     * @return string a complete VCALENDAR document
     */
    public function buildVirtualCalendar(string $calendarName, array $virtualEvents): string;
}
