<?php

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
