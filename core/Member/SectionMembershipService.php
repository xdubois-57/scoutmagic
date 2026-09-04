<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Config\ScoutYearService;

/**
 * Keeps member_section_periods in sync with member_functions.section_id —
 * see schema/core.sql's member_section_periods comment for the full
 * rationale. Called from Core\Import\DeskImportService right after each
 * member's functions are replaced for the year being imported.
 */
class SectionMembershipService
{
    public function __construct(
        private SectionMembershipRepository $repository,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * Idempotent: re-running the same import (same desired section set)
     * touches nothing. $asOf is the import's own date — used both as the
     * close date for a section a member just left and the start date for
     * one they just joined.
     */
    public function syncForMember(int $memberId, int $memberYearId, int $scoutYearId, \DateTimeImmutable $asOf): void
    {
        $today = $asOf->format('Y-m-d');

        // Rollover safety net: a period still open from an earlier scout
        // year (i.e. the year it belongs to ended without this sync ever
        // running against it) is closed now, at THAT year's own end_date —
        // never at today's date, so it never bleeds into the current year.
        foreach ($this->repository->findOpenPeriodsForMemberOutsideYear($memberId, $scoutYearId) as $period) {
            $year = $this->scoutYearService->findById($period->scoutYearId);
            $this->repository->close($period->id, $year['end_date'] ?? $today);
        }

        $desiredSectionIds = $this->repository->findDistinctSectionIdsForMemberYear($memberYearId);
        $openSectionIds = $this->repository->findOpenSectionIdsForMemberAndYear($memberId, $scoutYearId);

        foreach ($openSectionIds as $sectionId) {
            if (!in_array($sectionId, $desiredSectionIds, true)) {
                $this->repository->closeOpenPeriods($memberId, $sectionId, $scoutYearId, $today);
            }
        }

        foreach ($desiredSectionIds as $sectionId) {
            if (!in_array($sectionId, $openSectionIds, true)) {
                $this->repository->open($memberId, $sectionId, $scoutYearId, $today);
            }
        }
    }

    /**
     * One-time seed (public/index.php, gated by the
     * 'member_section_periods_backfilled' setting) for installs where this
     * table was added after member/function data already existed —
     * without it, every existing member's Section documents box on the
     * member page stays empty until their section changes on a future Desk
     * import, since syncForMember() only ever reacts to a change (see this
     * class's own docblock). Reconstructs a period for every (member,
     * section, scout year) triple derivable from member_functions right
     * now: real join/leave dates aren't known, so each period is
     * approximated as covering the whole scout year (start_date =
     * scout_years.start_date), closed at that year's own end_date unless
     * it's the currently active scout year (left open, NULL end_date) —
     * this is what makes the period cover the configured reference date
     * (Core\Member\SectionDocumentOwnershipChecker) regardless of where in
     * the year it falls, not just "from today onward". A later Desk import
     * still corrects these as real changes happen — this is a best-effort
     * seed, not a source of truth. Skips any triple that already has a
     * period (defensive; the table is expected to be empty the one time
     * this actually runs).
     */
    public function backfillFromFunctions(): void
    {
        $currentScoutYearId = $this->scoutYearService->getCurrentYear()['id'];

        foreach ($this->repository->findDistinctMemberSectionYearTriples() as $triple) {
            if ($this->repository->hasAnyPeriod(
                $triple['member_id'],
                $triple['section_id'],
                $triple['scout_year_id']
            )) {
                continue;
            }

            $scoutYear = $this->scoutYearService->findById($triple['scout_year_id']);
            if ($scoutYear === null) {
                continue;
            }

            $periodId = $this->repository->open($triple['member_id'], $triple['section_id'], $triple['scout_year_id'],
                $scoutYear['start_date']);
            if ($triple['scout_year_id'] !== $currentScoutYearId) {
                $this->repository->close($periodId, $scoutYear['end_date']);
            }
        }
    }

    /**
     * Combines the 'section_document_reference_date' setting (DD-MM) with
     * a scout year's own start calendar year into a real Y-m-d date — the
     * point in time Core\Member\SectionDocumentOwnershipChecker checks
     * membership periods against for "active in section S for scout year
     * Y". Deliberately keyed off the scout year's start_date column
     * rather than its label, so it never depends on label formatting.
     *
     * @param array{id: int, label: string, start_date: string, end_date: string} $scoutYear
     */
    public static function resolveReferenceDate(string $referenceDateDdMm, array $scoutYear): string
    {
        [$day, $month] = explode('-', $referenceDateDdMm);
        $startYear = (int) substr($scoutYear['start_date'], 0, 4);

        return sprintf('%04d-%s-%s', $startYear, $month, $day);
    }
}
