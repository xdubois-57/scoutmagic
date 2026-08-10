<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Import\AgeBranchRepository;
use Core\Member\MemberYearService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;

/**
 * Orchestrates Service\SlotMath against real data: configured brackets/
 * capacities, the current scout year's member_years (for projected
 * headcount), and pending requests (for the waitlist-tier adjustment).
 */
class SlotService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private SettingService $settingService,
        private AgeBracketRepository $ageBracketRepository,
        private SlotCapacityRepository $slotCapacityRepository,
        private RegistrationRequestRepository $requestRepository
    ) {
    }

    public function referenceMonthDay(): string
    {
        $value = (string) $this->settingService->get('registration_reference_date', null, '12-31');

        return preg_match('/^\d{2}-\d{2}$/', $value) === 1 ? $value : '12-31';
    }

    /**
     * The list of birth years per branch for the target scout year — the
     * public page's "which year was my child born" section. Ordered by
     * canonical branch order, each branch listing one birth year per
     * configured year-in-branch (oldest year-in-branch first).
     *
     * @return array<int, array{branch_label: string, birth_years: array<int>}> keyed by age_branch_id
     */
    public function birthYearsByBranch(string $targetScoutYearLabel): array
    {
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
            $this->referenceMonthDay()
        );

        $result = [];
        foreach ($brackets as $bracket) {
            $years = [];
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $years[] = SlotMath::birthYearForSlot($bracket, $yearInBranch, $referenceYear);
            }
            $result[$bracket->ageBranchId] = [
                'branch_label' => $bracket->branchLabel,
                'birth_years' => $years,
            ];
        }

        return $result;
    }

    /**
     * The public page's three waitlist baskets — only meaningful content
     * when the module's waitlist display setting is on (the caller decides
     * whether to show this at all).
     *
     * @return array<string, array<int>> tier => birth years (deduplicated, sorted)
     */
    public function waitlistTiersByBirthYear(int $targetScoutYearId, string $targetScoutYearLabel, int $currentScoutYearId): array
    {
        $availableThreshold = (float) $this->settingService->get('registration_waitlist_threshold_available', null, '0.5');
        $limitedThreshold = (float) $this->settingService->get('registration_waitlist_threshold_limited', null, '0.1');

        $brackets = $this->ageBracketRepository->findAllOrdered();
        $capacities = $this->slotCapacityRepository->findAllAsMap();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
            $this->referenceMonthDay()
        );

        $projected = $this->projectedHeadcountBySlot($currentScoutYearId, $brackets);
        $pending = $this->pendingCountBySlot($targetScoutYearId, $brackets, $referenceYear);

        $tiers = [SlotMath::TIER_AVAILABLE => [], SlotMath::TIER_LIMITED => [], SlotMath::TIER_HEAVY => []];

        foreach ($brackets as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $slotKey = $bracket->ageBranchId . ':' . $yearInBranch;
                $capacity = $capacities[$bracket->ageBranchId][$yearInBranch] ?? 0;
                $remaining = $capacity - ($projected[$slotKey] ?? 0) - ($pending[$slotKey] ?? 0);

                $tier = SlotMath::tierForRemaining($capacity, $remaining, $availableThreshold, $limitedThreshold);
                $birthYear = SlotMath::birthYearForSlot($bracket, $yearInBranch, $referenceYear);
                $tiers[$tier][] = $birthYear;
            }
        }

        foreach ($tiers as $tier => $years) {
            $years = array_values(array_unique($years));
            sort($years);
            $tiers[$tier] = $years;
        }

        return $tiers;
    }

    /**
     * Members currently one rank below each slot (or at the last rank of
     * the previous branch, for a branch's first slot) in the CURRENT scout
     * year, excluding anyone marked leaving (Core\Member\DepartureService)
     * — the animés who will occupy that slot next year if nothing changes.
     * Ages are computed exclusively via Core\Member\MemberYearService::
     * getEffectiveAge() (never a parallel calculation, per the module's
     * own "never recompute an age by hand" rule).
     *
     * @param array<\Modules\Registration\Repository\AgeBracket> $orderedBrackets
     * @return array<string, int> "{ageBranchId}:{yearInBranch}" => projected headcount for THAT slot
     */
    private function projectedHeadcountBySlot(int $currentScoutYearId, array $orderedBrackets): array
    {
        $memberYearService = new MemberYearService();
        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel($this->scoutYearLabel($currentScoutYearId));

        $stmt = $this->pdo->prepare(
            'SELECT birth_date_encrypted, scout_year_offset
             FROM member_years
             WHERE scout_year_id = ? AND is_active = 1 AND leaving = 0'
        );
        $stmt->execute([$currentScoutYearId]);

        // Feeder slot => headcount currently sitting there.
        $currentBySlot = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['birth_date_encrypted'] === null) {
                continue;
            }
            $birthDate = $this->encryption->decrypt($row['birth_date_encrypted']);
            $birthYear = MemberYearService::extractBirthYear($birthDate);
            $effective = $memberYearService->getEffectiveAge($birthYear, (int) $row['scout_year_offset'], $referenceYear);
            if ($effective->branchName === null || $effective->yearInBranch === null) {
                continue;
            }
            $sortOrder = AgeBranchRepository::canonicalSortOrder($effective->branchName);
            $bracket = $this->findBracketBySortOrder($orderedBrackets, $sortOrder);
            if ($bracket === null) {
                continue;
            }
            $key = $bracket->ageBranchId . ':' . $effective->yearInBranch;
            $currentBySlot[$key] = ($currentBySlot[$key] ?? 0) + 1;
        }

        // Re-key by the TARGET slot each of those feeds into.
        $projected = [];
        foreach ($orderedBrackets as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $feeder = SlotMath::feederSlot($orderedBrackets, $bracket->ageBranchId, $yearInBranch);
                if ($feeder === null) {
                    continue;
                }
                $feederKey = $feeder['age_branch_id'] . ':' . $feeder['year_in_branch'];
                $targetKey = $bracket->ageBranchId . ':' . $yearInBranch;
                $projected[$targetKey] = $currentBySlot[$feederKey] ?? 0;
            }
        }

        return $projected;
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $orderedBrackets
     * @return array<string, int> "{ageBranchId}:{yearInBranch}" => pending request count
     */
    private function pendingCountBySlot(int $targetScoutYearId, array $orderedBrackets, int $referenceCalendarYear): array
    {
        $counts = [];
        foreach ($this->requestRepository->findPendingForYear($targetScoutYearId) as $request) {
            $slot = SlotMath::slotForBirthDate($orderedBrackets, $request->birthDate, $referenceCalendarYear);
            if ($slot === null) {
                continue;
            }
            $key = $slot['age_branch_id'] . ':' . $slot['year_in_branch'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     */
    private function findBracketBySortOrder(array $brackets, int $sortOrder): ?\Modules\Registration\Repository\AgeBracket
    {
        foreach ($brackets as $bracket) {
            if ($bracket->branchSortOrder === $sortOrder) {
                return $bracket;
            }
        }

        return null;
    }

    private function scoutYearLabel(int $scoutYearId): string
    {
        $stmt = $this->pdo->prepare('SELECT label FROM scout_years WHERE id = ?');
        $stmt->execute([$scoutYearId]);
        $label = $stmt->fetchColumn();

        return $label !== false ? (string) $label : '';
    }
}
