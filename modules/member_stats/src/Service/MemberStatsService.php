<?php

declare(strict_types=1);

namespace Modules\MemberStats\Service;

use Core\Member\MemberYearService;
use Modules\MemberStats\Repository\MemberStatsRepository;

/**
 * Turns raw (decrypted) per-member rows into anonymous aggregate counts for the
 * statistics page.
 *
 * Branch and year-within-branch are derived exclusively via
 * Core\Member\MemberYearService::getEffectiveAge() — the single source of
 * truth for "which branch-year is this member in" — from each member's birth
 * year and their scout_year_offset. Only aggregate counts leave this service —
 * never individual member data.
 */
class MemberStatsService
{
    public function __construct(
        private MemberStatsRepository $repository,
        private MemberYearService $memberYearService = new MemberYearService()
    ) {
    }

    /**
     * Build the aggregated statistics view model for a scout year.
     *
     * @param int $referenceYear Calendar year used to convert birth year → age
     *                           (typically the scout year's start year).
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
        foreach (MemberYearService::BRANCHES as $branch) {
            $years = $branch['age_max'] - $branch['age_min'] + 1;
            for ($y = 1; $y <= $years; $y++) {
                $counts[$branch['key']][$y] = ['total' => 0, 'male' => 0, 'female' => 0, 'other' => 0];
            }
        }

        $rows = $this->repository->getMemberBranchData($scoutYearId);
        foreach ($rows as $row) {
            $birthYear = MemberYearService::extractBirthYear($row['birth_date']);
            $effectiveAge = $this->memberYearService->getEffectiveAge($birthYear, $row['scout_year_offset'], $referenceYear);

            if (!$effectiveAge->isInKnownBranch()) {
                continue; // no usable birth year, or effective age outside the four animés branches
            }

            $bucket = $this->classifyGender($row['gender']);

            $counts[$effectiveAge->branchKey][$effectiveAge->yearInBranch]['total']++;
            $counts[$effectiveAge->branchKey][$effectiveAge->yearInBranch][$bucket]++;
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

        foreach (MemberYearService::BRANCHES as $branch) {
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
