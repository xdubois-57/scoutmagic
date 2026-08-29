<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract DTO (ARCHITECTURE.md §7.5): one section's calendar
 * activity over a month, as seen from outside the calendar module — the
 * section's display color and, for each day that has at least one event,
 * the event titles falling on it. A multi-day event contributes its title
 * to every day it covers.
 *
 * Introduced for the SOS Staff d'U duty grid's "section activity" columns,
 * generic to any consumer that wants to mark busy days per section without
 * learning the calendar module's internal calendar/event model.
 */
final class SectionMonthActivity
{
    public function __construct(
        public readonly int $sectionId,
        /** Hex color of the section's calendar, e.g. `#800020`. */
        public readonly string $color,
        /**
         * `Y-m-d` day => titles of the events covering that day, only for
         * days inside the requested month that have at least one event.
         *
         * @var array<string, string[]>
         */
        public readonly array $eventTitlesByDay
    ) {
    }
}
