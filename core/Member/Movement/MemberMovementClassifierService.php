<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Movement;

use Core\Config\ScoutYearService;

/**
 * Generic, core-level classification of a member's situation this scout
 * year compared to the previous one — factored out so any current or
 * future feature can explain "why is this member here this year" without
 * re-deriving the rule (the registration module's own PassageService is a
 * *forward-looking* prediction from a single year's age math and is not
 * reused here — see its docblock; this classifier instead compares two
 * already-imported years' real recorded data).
 *
 * Deterministic decision rule, evaluated in this fixed priority order for
 * every member (documented here because §9 of the feature spec requires an
 * explicit, testable priority when more than one condition could apply):
 *
 *   1. UNKNOWN         — no previous scout year exists at all in the
 *                         database (nothing to compare against), or the
 *                         previous year's data can't be resolved to a real
 *                         section/branch (e.g. a dangling section
 *                         reference) — never guessed.
 *   2. NEW              — no active member_years row exists for this
 *                         member for ANY scout year before the current one.
 *   3. RETURNING        — an earlier active member_years row exists (this
 *                         year excluded), but not one immediately
 *                         preceding this year with an active section
 *                         function — covers both "gap year(s)" and "was
 *                         active last year but in a non-section function".
 *   4. BRANCH_CHANGE    — active in a section last year, and that
 *                         section's branch differs from this year's.
 *   5. SECTION_CHANGE   — active in a section last year, same branch, but
 *                         a different section.
 *   6. CONTINUING       — active in the exact same section last year and
 *                         this year (the default/fallback case).
 *
 * scout_year_offset (skipped/held-back years) never enters this
 * comparison: it only affects which BRANCH a birth date maps to, never
 * which scout year a member_years row belongs to — this classifier only
 * ever compares real recorded rows, never recomputed ages.
 */
final class MemberMovementClassifierService
{
    public function __construct(
        private MemberMovementRepository $repository,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * Classify a whole roster in one pass — at most 3 extra queries total,
     * however many members are in $currentRoster.
     *
     * @param array<int, array{member_id: int, section_id: int, age_branch_id: int}> $currentRoster
     *        One entry per member currently shown, keyed however the
     *        caller likes (not read) — member_id must be the persistent
     *        Core\Member identity (members.id), not a member_year id.
     * @return array<int, MemberMovementResult> keyed by member_id
     */
    public function classifyBatch(int $currentScoutYearId, array $currentRoster): array
    {
        $byMemberId = [];
        foreach ($currentRoster as $row) {
            $byMemberId[(int) $row['member_id']] = [
                'section_id' => (int) $row['section_id'],
                'age_branch_id' => (int) $row['age_branch_id'],
            ];
        }
        $memberIds = array_keys($byMemberId);
        if ($memberIds === []) {
            return [];
        }

        $currentYear = $this->scoutYearService->findById($currentScoutYearId);
        if ($currentYear === null) {
            return $this->allUnknown($memberIds);
        }

        $previousYear = $this->scoutYearService->findByLabel(ScoutYearService::previousLabel($currentYear['label']));
        if ($previousYear === null) {
            // No earlier scout year is recorded at all — there is nothing
            // to compare against, for anyone. Never fabricate "new".
            return $this->allUnknown($memberIds);
        }

        $previousSections = $this->repository->findActiveSectionsForYear($memberIds, $previousYear['id']);
        $activeLastYearIds = $this->repository->findActiveMemberIdsForYear($memberIds, $previousYear['id']);

        $needHistoryCheck = array_values(array_filter(
            $memberIds,
            fn(int $id) => !isset($previousSections[$id]) && !isset($activeLastYearIds[$id])
        ));
        $everActiveBefore = $needHistoryCheck === []
            ? []
            : $this->repository->findMemberIdsEverActiveBefore($needHistoryCheck, $previousYear['start_date']);

        $results = [];
        foreach ($byMemberId as $memberId => $current) {
            $results[$memberId] = $this->classifyOne(
                $current,
                $previousSections[$memberId] ?? null,
                isset($activeLastYearIds[$memberId]),
                isset($everActiveBefore[$memberId])
            );
        }

        return $results;
    }

    /**
     * @param array{section_id: int, age_branch_id: int} $current
     * @param array{section_id: int, age_branch_id: int}|null $previousSection
     */
    private function classifyOne(
        array $current,
        ?array $previousSection,
        bool $activeLastYearWithoutSection,
        bool $everActiveBeforeLastYear
    ): MemberMovementResult {
        if ($previousSection !== null) {
            if ($previousSection['section_id'] === $current['section_id']) {
                return new MemberMovementResult(MemberMovementStatus::CONTINUING, $previousSection['section_id'],
                    $previousSection['age_branch_id']);
            }
            if ($previousSection['age_branch_id'] === $current['age_branch_id']) {
                return new MemberMovementResult(MemberMovementStatus::SECTION_CHANGE, $previousSection['section_id'],
                    $previousSection['age_branch_id']);
            }
            return new MemberMovementResult(MemberMovementStatus::BRANCH_CHANGE, $previousSection['section_id'],
                $previousSection['age_branch_id']);
        }

        if ($activeLastYearWithoutSection) {
            // Known and active the previous year, but not in a
            // section-bearing function (e.g. hors-branche/administrative) —
            // an active section this year is a return, not a fresh arrival.
            return new MemberMovementResult(MemberMovementStatus::RETURNING);
        }

        if ($everActiveBeforeLastYear) {
            return new MemberMovementResult(MemberMovementStatus::RETURNING);
        }

        return new MemberMovementResult(MemberMovementStatus::NEW);
    }

    /**
     * @param int[] $memberIds
     * @return array<int, MemberMovementResult>
     */
    private function allUnknown(array $memberIds): array
    {
        $results = [];
        foreach ($memberIds as $memberId) {
            $results[$memberId] = new MemberMovementResult(MemberMovementStatus::UNKNOWN);
        }
        return $results;
    }
}
