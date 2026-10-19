<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The module's third public API for the "Année scoute" workflow
 * (ARCHITECTURE.md §7.5), beside ScoutYearTransitionVetoProvider and
 * ScoutYearPreparationProvider: what that workflow's checks read about the
 * reenrollment campaign, the requests, the departures and the passages —
 * for an EXPLICIT target year, unlike ScoutYearPreparationProvider, because
 * the workflow names the year it is preparing and every check has to be
 * about that year and no other.
 *
 * Wired as a nullable dependency in the composition root, only when this
 * module is enabled. With it null, the workflow has none of the three
 * steps that are this module's pages (Départs, Passage, confirmation of the
 * requests), and none of the gaps that compare them with the import.
 *
 * "The year before the target year" below is the scout year whose label
 * precedes the target's (2026-2027 for 2027-2028): the year whose animés
 * leave or change branch. It is looked up, never created — a year that
 * does not exist has nobody in it, and every list about it is empty.
 *
 * Nothing here writes, and nothing is decided from a count: the campaign
 * state comes from the campaign's own clock and switch.
 */
interface ScoutYearRegistrationReviewProvider
{
    /**
     * Where the reenrollment campaign asking families about $targetYearId
     * stands at $now (default: the current moment).
     *
     * Null when the configured dates designate no campaign for that year
     * (no opening or closing date set) and the campaign switch is not on
     * for it — there is no campaign to speak of, which is neither « not
     * started » nor « finished ». The switch is the CURRENT campaign's (the
     * year after the public year): for any other year it is not read, and
     * that year's state follows its dates alone.
     */
    public function reenrollmentCampaign(int $targetYearId, ?\DateTimeImmutable $now = null): ?ReenrollmentCampaignState;

    /**
     * The requests for $targetYearId still waiting on the staff.
     */
    public function requestCounts(int $targetYearId): RegistrationRequestCounts;

    /**
     * The animés of the year before $targetYearId announced as leaving
     * (`member_years.leaving`, set on « Départs » or by the family's own
     * reenrollment answer), by name. `memberYearId` and `sectionId` are
     * those of that earlier year's row.
     *
     * @return list<ScoutYearMember>
     */
    public function announcedLeavers(int $targetYearId): array;

    /**
     * The animés who change branch in $targetYearId and still have no
     * destination section chosen — the Passage page's own list, by name, in
     * the page's order (section, then as listed there).
     *
     * @return list<PassageWithoutDestination>
     */
    public function passagesWithoutDestination(int $targetYearId): array;

    /**
     * What was prepared on the site for $targetYearId against what its
     * Desk import holds (chantier « Année scoute » §2.5).
     *
     * Meaningful only after a successful import of $targetYearId, which is
     * the caller's to know (`import_journal` is core's): before one, the
     * import holds nobody, every leaver is "absent" and every accepted
     * request "missing", and none of it means anything. The method does
     * not guess.
     */
    public function preparedImportGaps(int $targetYearId): PreparedImportGaps;
}
