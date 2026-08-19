<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Movement;

/**
 * Batch data access for MemberMovementClassifierService. Every method takes
 * a whole list of persistent member ids and resolves them in ONE query
 * (never one query per member) — this is what keeps classifying a whole
 * unit's roster free of N+1 queries.
 *
 * No personal data is decrypted or read here: only ids, dates and flags.
 */
final class MemberMovementRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * For each given member id, resolve the section/branch of their winning
     * active-with-a-section membership for that scout year (main function
     * preferred, then lowest member_functions id — same tie-break
     * convention as Modules\Registration\Service\PassageService). A member
     * absent from the result simply had no active member_years row with a
     * section-bearing function that year.
     *
     * @param int[] $memberIds
     * @return array<int, array{section_id: int, age_branch_id: int}> keyed by member_id
     */
    public function findActiveSectionsForYear(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT my.member_id, mf.section_id, s.age_branch_id, mf.is_main_function, mf.id AS mf_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN sections s ON mf.section_id = s.id
             WHERE my.member_id IN ($placeholders) AND my.scout_year_id = ? AND my.is_active = 1
               AND mf.section_id IS NOT NULL
             ORDER BY my.member_id, mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberId = (int) $row['member_id'];
            // Rows arrive ordered best-first per member_id — first one wins.
            if (isset($result[$memberId])) {
                continue;
            }
            $result[$memberId] = [
                'section_id' => (int) $row['section_id'],
                'age_branch_id' => (int) $row['age_branch_id'],
            ];
        }

        return $result;
    }

    /**
     * Member ids that have ANY active member_years row for this scout
     * year, regardless of whether any function carries a section — this is
     * what distinguishes "active but in a non-section function that year"
     * (e.g. an administrative/hors-branche role) from "not a member at
     * all" that year.
     *
     * @param int[] $memberIds
     * @return array<int, true> a set — check with isset()
     */
    public function findActiveMemberIdsForYear(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT member_id FROM member_years
             WHERE member_id IN ($placeholders) AND scout_year_id = ? AND is_active = 1"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $set = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $memberId) {
            $set[(int) $memberId] = true;
        }

        return $set;
    }

    /**
     * Member ids that have EVER had an active member_years row for a scout
     * year starting strictly before $beforeStartDate — used to tell a
     * genuinely brand-new member apart from one who is merely returning
     * after a gap of one or more years. Deliberately compares scout_years
     * .start_date, never scout_year_id, since ids are not guaranteed to be
     * chronological (a past year can be back-filled after the fact).
     *
     * @param int[] $memberIds
     * @return array<int, true> a set — check with isset()
     */
    public function findMemberIdsEverActiveBefore(array $memberIds, string $beforeStartDate): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT my.member_id
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id IN ($placeholders) AND my.is_active = 1 AND sy.start_date < ?"
        );
        $stmt->execute([...$memberIds, $beforeStartDate]);

        $set = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $memberId) {
            $set[(int) $memberId] = true;
        }

        return $set;
    }
}
