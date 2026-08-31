<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * Who this unit expects to have next year, for the modules that need to
 * know before Desk does.
 *
 * `Service\ForecastService` already combines the four sources, handles the
 * double-counting, honours `member_years.scout_year_offset` and carries
 * two regression-tested invariants. Three surfaces need that answer: the
 * Prévisions page (which keeps calling the service directly — this is a
 * façade, not a replacement), the Passage page's statistics box, and
 * `mass_mail` for next year's audiences.
 *
 * **The pattern is ARCHITECTURE.md §7.5**, alongside
 * `Api\ExternalMailingListProvider` and `llm_connector`'s
 * `LlmConnectorInterface`: this module publishes an interface under its own
 * `Api` namespace, a consumer declares a **nullable** dependency on it, and
 * the composition root injects an implementation only when `registration`
 * is enabled. A consumer built with null must go on working — with no
 * projection to show, not with an error — because a unit that has not
 * enabled the registration module has no projected year at all.
 *
 * **Nothing moves into `core/`.** Core must never know
 * `registration_section_transfers` or `registration_requests` exist.
 */
interface ProjectedPopulationProvider
{
    /**
     * The projected population of `$targetScoutYearId`, one entry per
     * person, unassigned people included (`sectionId` null) — they are
     * part of the unit's total and a consumer that dropped them would
     * under-count.
     *
     * @return array<int, ProjectedPerson>
     */
    public function projectedPopulation(int $targetScoutYearId): array;

    /**
     * The same population summed per section, in the unit's own section
     * order (branch, then desk code — the order the Prévisions page and
     * every section picker use), so a consumer can render it straight out.
     *
     * Sections nobody is projected into are absent rather than present with
     * a zero: « no Louveteaux next year » and « we have not decided yet »
     * are different statements, and only the projection can tell them
     * apart. Unassigned people are in none of these totals — they are in
     * projectedPopulation() with a null sectionId, which is what that null
     * is for.
     *
     * @return array<int, ProjectedSectionTotals>
     */
    public function projectedSectionTotals(int $targetScoutYearId): array;

    /**
     * The addresses reachable for `$targetScoutYearId` — a member's own
     * address for whoever Desk already knows, the family's contact address
     * for an accepted request that has not been encoded yet.
     *
     * People with no usable address are simply absent: a caller writing to
     * a year builds its list from what came back, and an entry with an
     * empty address would be one silent failure per send.
     *
     * @return array<int, ProjectedRecipient>
     */
    public function reachableRecipients(int $targetScoutYearId): array;
}
