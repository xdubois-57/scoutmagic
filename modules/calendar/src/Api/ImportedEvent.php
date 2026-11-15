<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract DTO (ARCHITECTURE.md §7.5): one event copied from
 * another calendar, already in this module's own terms — Brussels local
 * dates (`YYYY-MM-DD`) and times (`HH:MM`), an inclusive end date, and no
 * start time for an event that lasts the whole day.
 *
 * The caller converts; this module only checks and stores. A consumer
 * that reads a calendar written elsewhere (an ICS file, a time zone, an
 * exclusive DTEND) knows those rules, and this module knows its own.
 */
final class ImportedEvent
{
    public function __construct(
        public readonly string $title,
        public readonly string $startDate,
        public readonly ?string $endDate = null,
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
        public readonly ?string $location = null,
        public readonly ?string $description = null
    ) {
    }
}
