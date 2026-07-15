<?php

declare(strict_types=1);

namespace Modules\MemberStats\Service;

use Modules\MemberStats\Repository\MemberStatsRepository;

/**
 * Turns raw (decrypted) per-member rows into anonymous aggregate counts for the
 * statistics page.
 *
 * Branch age ranges are federation domain knowledge and are NOT stored in the
 * database. Each member is mapped to one of the four "animés" branches by
 * matching their branch label, then placed into a year-within-branch computed
 * from their birth year and the branch age range. Only aggregate counts leave
 * this service — never individual member data.
 */
class MemberStatsService
{
    /**
     * The four Les Scouts "animés" branches, in display order (which matches
     * age_branches.sort_order for these canonical branches). Each member's
     * year within the branch is derived from age = referenceYear - birthYear.
     *
     * @var array<int, array{key: string, name: string, age_min: int, age_max: int, color: string}>
     */
    private const BRANCHES = [
        ['key' => 'baladin',   'name' => 'Baladins',   'age_min' => 6,  'age_max' => 7,  'color' => '#378ADD'],
        ['key' => 'louveteau', 'name' => 'Louveteaux', 'age_min' => 8,  'age_max' => 11, 'color' => '#639922'],
        ['key' => 'eclaireur', 'name' => 'Éclaireurs', 'age_min' => 12, 'age_max' => 15, 'color' => '#1D9E75'],
        ['key' => 'pionnier',  'name' => 'Pionniers',  'age_min' => 16, 'age_max' => 17, 'color' => '#D85A30'],
    ];

    public function __construct(private MemberStatsRepository $repository)
    {
    }

    /**
     * The reference calendar year for a scout year is its START year: a scout
     * year "2025-2026" runs from September 2025, and Les Scouts defines each
     * branch's cohorts by that start year (e.g. baladins in 2025-2026 are the
     * children born in 2018 and 2019). This must NOT depend on today's date, so
     * the same scout year always yields the same figures.
     */
    public static function referenceYearFromLabel(string $label): int
    {
        if (preg_match('/(\d{4})/', $label, $m) === 1) {
            return (int) $m[1];
        }
        return (int) date('Y');
    }

    /**
     * Build the aggregated statistics view model for a scout year.
     *
     * @param int $referenceYear Calendar year used to convert birth year → age
     *                           (typically the current year).
     * @return array{
     *     totals: array{total: int, male: int, female: int, other: int},
     *     max_count: int,
     *     branches: array<int, array{
     *         name: string, age_range: string, color: string,
     *         rows: array<int, array{year_label: string, birth_year: int, total: int, male: int, female: int, other: int}>
     *     }>
     * }
     */
    public function getStatistics(int $scoutYearId, int $referenceYear): array
    {
        // Initialise an empty count grid: branch key → year index (1-based) → bucket.
        $counts = [];
        foreach (self::BRANCHES as $branch) {
            $years = $branch['age_max'] - $branch['age_min'] + 1;
            for ($y = 1; $y <= $years; $y++) {
                $counts[$branch['key']][$y] = ['total' => 0, 'male' => 0, 'female' => 0, 'other' => 0];
            }
        }

        $rows = $this->repository->getMemberBranchData($scoutYearId);
        foreach ($rows as $row) {
            $branch = $this->matchBranch($row['branch_label']);
            if ($branch === null) {
                continue; // not one of the four animés branches (e.g. Staff d'U, Route)
            }

            $birthYear = $this->extractYear($row['birth_date']);
            if ($birthYear === null) {
                continue; // cannot place a member without a usable birth year
            }

            $age = $referenceYear - $birthYear;
            $yearIndex = $this->clampYearIndex($age, $branch);
            $bucket = $this->classifyGender($row['gender']);

            $counts[$branch['key']][$yearIndex]['total']++;
            $counts[$branch['key']][$yearIndex][$bucket]++;
        }

        return $this->buildViewModel($counts, $referenceYear);
    }

    /**
     * @param array<string, array<int, array{total: int, male: int, female: int, other: int}>> $counts
     * @return array{
     *     totals: array{total: int, male: int, female: int, other: int},
     *     max_count: int,
     *     branches: array<int, array{name: string, age_range: string, color: string, rows: array<int, array{year_label: string, birth_year: int, total: int, male: int, female: int, other: int}>}>
     * }
     */
    private function buildViewModel(array $counts, int $referenceYear): array
    {
        $totals = ['total' => 0, 'male' => 0, 'female' => 0, 'other' => 0];
        $maxCount = 0;
        $branches = [];

        foreach (self::BRANCHES as $branch) {
            $rows = [];
            $years = $branch['age_max'] - $branch['age_min'] + 1;
            for ($y = 1; $y <= $years; $y++) {
                $cell = $counts[$branch['key']][$y];
                $age = $branch['age_min'] + ($y - 1);

                $rows[] = [
                    'year_label' => $this->ordinalYearLabel($y),
                    'birth_year' => $referenceYear - $age,
                    'total' => $cell['total'],
                    'male' => $cell['male'],
                    'female' => $cell['female'],
                    'other' => $cell['other'],
                ];

                $totals['total'] += $cell['total'];
                $totals['male'] += $cell['male'];
                $totals['female'] += $cell['female'];
                $totals['other'] += $cell['other'];
                $maxCount = max($maxCount, $cell['total']);
            }

            $branches[] = [
                'name' => $branch['name'],
                'age_range' => $branch['age_min'] . '–' . $branch['age_max'],
                'color' => $branch['color'],
                'rows' => $rows,
            ];
        }

        return [
            'totals' => $totals,
            'max_count' => $maxCount,
            'branches' => $branches,
        ];
    }

    /**
     * Match a Desk branch label to one of the four animés branch definitions.
     * Uses the same substring approach as the core AgeBranchRepository.
     *
     * @return array{key: string, name: string, age_min: int, age_max: int, color: string}|null
     */
    private function matchBranch(string $label): ?array
    {
        $normalized = mb_strtolower(trim($label));
        foreach (self::BRANCHES as $branch) {
            if (str_contains($normalized, $branch['key'])) {
                return $branch;
            }
        }
        // 'éclaireur' with accent still contains 'eclaireur' only after stripping
        // the accent, so handle the accented form explicitly.
        if (str_contains($normalized, 'éclaireur')) {
            return self::BRANCHES[2];
        }
        return null;
    }

    /**
     * Clamp a member's age to the branch range and return the 1-based year index.
     *
     * @param array{key: string, name: string, age_min: int, age_max: int, color: string} $branch
     */
    private function clampYearIndex(int $age, array $branch): int
    {
        $index = $age - $branch['age_min'] + 1;
        $years = $branch['age_max'] - $branch['age_min'] + 1;
        return max(1, min($years, $index));
    }

    /**
     * Extract a plausible 4-digit birth year from a decrypted date string.
     * Handles both "YYYY-MM-DD" and "DD/MM/YYYY".
     */
    private function extractYear(?string $birthDate): ?int
    {
        if ($birthDate === null || $birthDate === '') {
            return null;
        }
        if (preg_match('/(?<!\d)(19\d{2}|20\d{2})(?!\d)/', $birthDate, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    private function classifyGender(?string $gender): string
    {
        $g = mb_strtolower(trim((string) $gender));
        if ($g === '') {
            return 'other';
        }
        // Desk exports use 'M'/'H' (male) and 'F' (female); accept common spellings.
        if ($g === 'm' || $g === 'h' || $g === 'g'
            || str_contains($g, 'gar') || str_contains($g, 'masc') || str_contains($g, 'homme')) {
            return 'male';
        }
        if ($g === 'f'
            || str_contains($g, 'fille') || str_contains($g, 'fem')) {
            return 'female';
        }
        return 'other';
    }

    private function ordinalYearLabel(int $year): string
    {
        return $year === 1 ? '1ère année' : $year . 'e année';
    }
}
