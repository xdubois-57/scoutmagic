<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * Single source of truth for a member's effective age, branch, and year within
 * that branch, for a given scout year.
 *
 * Every existing and future feature that needs to know "which branch-year is
 * this member in" must call getEffectiveAge() — never recompute a branch/year
 * from birth year alone. scout_year_offset lets a chief shift a member's
 * effective age by one year (ahead or behind) relative to what their birth
 * year would normally give, e.g. for a member who skipped or repeated a year.
 *
 * This service never touches encrypted data: birth year decryption stays in
 * the Repository layer (SECURITY.md §5); callers pass already-decrypted values.
 */
class MemberYearService
{
    /**
     * The four Les Scouts "animés" branches, in federation-fixed age order.
     * These age ranges are federation domain knowledge and are NOT stored in
     * the database — the age_branches table holds the branch catalogue itself
     * (desk codes, labels, sort order) which this key matches by name.
     *
     * @var array<int, array{key: string, name: string, age_min: int, age_max: int, color: string}>
     */
    public const BRANCHES = [
        ['key' => 'baladin',   'name' => 'Baladins',   'age_min' => 6,  'age_max' => 7,  'color' => '#378ADD'],
        ['key' => 'louveteau', 'name' => 'Louveteaux', 'age_min' => 8,  'age_max' => 11, 'color' => '#639922'],
        ['key' => 'eclaireur', 'name' => 'Éclaireurs', 'age_min' => 12, 'age_max' => 15, 'color' => '#1D9E75'],
        ['key' => 'pionnier',  'name' => 'Pionniers',  'age_min' => 16, 'age_max' => 17, 'color' => '#D85A30'],
    ];

    /**
     * Compute a member's effective age and branch-year for a scout year.
     *
     *     effective_age = referenceYear − birthYear + scoutYearOffset
     *
     * @param ?int $birthYear      Calendar year of birth, already decrypted by the
     *                             caller's Repository. Null when unknown (e.g. no
     *                             birth date on file) — the result then carries a
     *                             null age and no branch.
     * @param int  $scoutYearOffset -1, 0, or +1 (member_years.scout_year_offset).
     * @param int  $referenceYear  The scout year's reference calendar year — its
     *                             START year (see referenceYearFromScoutYearLabel()).
     *                             Deliberately NOT today's date, so a member's
     *                             branch never shifts mid-year.
     */
    public function getEffectiveAge(?int $birthYear, int $scoutYearOffset, int $referenceYear): EffectiveAge
    {
        if ($birthYear === null) {
            return new EffectiveAge(null, null, null, null, null, null);
        }

        $age = $referenceYear - $birthYear + $scoutYearOffset;

        foreach (self::BRANCHES as $branch) {
            if ($age >= $branch['age_min'] && $age <= $branch['age_max']) {
                return new EffectiveAge(
                    age: $age,
                    branchKey: $branch['key'],
                    branchName: $branch['name'],
                    branchColor: $branch['color'],
                    yearInBranch: $age - $branch['age_min'] + 1,
                    totalYearsInBranch: $branch['age_max'] - $branch['age_min'] + 1
                );
            }
        }

        return new EffectiveAge($age, null, null, null, null, null);
    }

    /**
     * Map an age_branches.sort_order value (canonical order assigned by
     * AgeBranchRepository::canonicalSortOrder() at import time: 10/20/30/40
     * for Baladins/Louveteaux/Éclaireurs/Pionniers) to that branch's display
     * color. Branches outside the four animés branches (Staff d'U, Route,
     * Iama, unknown) get a neutral gray.
     */
    public static function colorForBranchSortOrder(int $sortOrder): string
    {
        $index = intdiv($sortOrder, 10) - 1;

        return self::BRANCHES[$index]['color'] ?? '#6c757d';
    }

    /**
     * Extract a calendar birth year from a decrypted birth date string.
     * Handles both "YYYY-MM-DD" and "DD/MM/YYYY". Returns null when the value
     * is empty or unparseable.
     */
    public static function extractBirthYear(?string $birthDate): ?int
    {
        if ($birthDate === null || $birthDate === '') {
            return null;
        }
        if (preg_match('/(?<!\d)(19\d{2}|20\d{2})(?!\d)/', $birthDate, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * The reference calendar year for a scout year is its START year: a scout
     * year "2025-2026" runs from September 2025, and Les Scouts defines each
     * branch's cohorts by that start year. This must NOT depend on today's
     * date, so the same scout year always yields the same branch-year — for
     * the current scout year as well as when looking at a past one.
     */
    public static function referenceYearFromScoutYearLabel(string $label): int
    {
        if (preg_match('/(\d{4})/', $label, $m) === 1) {
            return (int) $m[1];
        }
        return (int) date('Y');
    }
}
