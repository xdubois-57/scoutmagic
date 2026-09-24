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
 * calendar_id, no visibility enum, just enough to label a picker option —
 * and, since the carpool chantier, where it happens and which section it
 * belongs to.
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
        public readonly ?string $description = null,
        /**
         * The event's free-text place, when one was typed — added for the
         * carpool module, which offers it as the default address of a
         * carpool (docs/chantiers/covoiturage.md, IT-03).
         */
        public readonly ?string $location = null,
        /**
         * The section whose own calendar carries the event; null for a
         * supplementary calendar (Animateurs and the like). A consumer whose
         * visibility follows the section — the carpool staff view — needs
         * it; the calendar's internal id stays hidden.
         */
        public readonly ?int $sectionId = null,
        /** That section's display name, for a label; null with $sectionId. */
        public readonly ?string $sectionName = null
    ) {
    }
}
