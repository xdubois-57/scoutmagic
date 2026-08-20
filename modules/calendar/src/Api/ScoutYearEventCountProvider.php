<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * The calendar module's public API (ARCHITECTURE.md §7.5) for the "Année
 * scoute" workflow: how many events are already encoded inside a scout
 * year's own date range, which is what tells that workflow whether the
 * year's éphémérides have been entered on the site yet.
 *
 * Wired as a nullable dependency in the composition root, only when this
 * module is enabled; when it is null the workflow hides its "encoder les
 * éphémérides" step, since there is no calendar to encode them into.
 *
 * Deliberately a count over a plain date range rather than "for scout year
 * X": the calendar knows nothing about scout years, and core already
 * holds each year's start_date/end_date. Keeping the translation on the
 * core side is what stops the module from growing a second, divergent
 * notion of when a scout year runs.
 */
interface ScoutYearEventCountProvider
{
    /**
     * Events starting within [$fromDate, $toDate], both inclusive, both
     * 'Y-m-d'. Counts every calendar of the unit, whatever its visibility:
     * the question is "has anything been encoded yet", not "what may this
     * particular visitor see".
     */
    public function countEventsBetween(string $fromDate, string $toDate): int;
}
