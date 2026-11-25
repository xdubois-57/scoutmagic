<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Config\AppClock;
use Core\Config\ScoutYearService;
use Core\Import\FeeCategoryRepository;
use Core\Import\RosterSnapshotRepository;
use Core\Member\Household\Household;
use Core\Member\Household\HouseholdService;
use Core\Member\HouseholdFeeCategory;
use Modules\Fees\FeeTariff;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Value\FeeAccuracyReport;
use Modules\Fees\Value\GuestEligibility;
use Modules\Fees\Value\HouseholdFeeCount;
use Modules\Fees\Value\HouseholdReview;
use Modules\Fees\Value\HouseholdReviewMember;
use Modules\Fees\Value\IgnoredHousehold;

/**
 * Uses the core's physical households, but counts only proven non-guests
 * towards their tariff. Proven guest eligibility takes precedence over the
 * Desk tariff. IAmA counts and keeps its encoded rate once guest eligibility
 * is excluded; unresolved guest facts retain uncertainty for every code.
 * Departures still count today; projected changes are a separate verdict.
 */
class FeeAccuracyService
{
    public function __construct(
        private HouseholdService $households,
        private HouseholdDetailRepository $details,
        private HouseholdTariffService $tariffs,
        private IgnoredHouseholdRepository $ignoredHouseholds,
        private FeeCategoryRepository $feeCategories,
        private RosterSnapshotRepository $snapshots,
        private TariffConfigurationService $configuration,
        private ScoutYearService $years
    ) {
    }

    public function report(int $scoutYearId): FeeAccuracyReport
    {
        $households = $this->households->householdsForYear($scoutYearId);
        $ignored = $this->ignoredHouseholds->findAllForYear($scoutYearId);
        $year = $this->years->findById($scoutYearId)['label'] ?? '';
        $period = $this->configuration->periodForYear($year);
        $facts = $this->snapshots->findMembershipFactsForYear($scoutYearId, AppClock::now());
        $guests = [];
        foreach ($facts as $memberId => $membership) {
            $guests[$memberId] = GuestTariffRule::evaluate($membership, $year, $period);
        }
        $amounts = [];
        foreach (FeeTariff::cases() as $tariff) {
            $amounts[$tariff->value] = $this->configuration->amountForYear($year, $tariff);
        }
        $memberYearIds = [];
        foreach ($households as $household) {
            array_push($memberYearIds, ...$household->memberYearIds());
        }
        $memberRows = $this->details->findMembers($memberYearIds);
        $addressLabels = $this->details->findAddressLabels(array_keys($households));
        $feeCategories = $this->feeCategoriesById();
        $toCorrect = $upcoming = $setAside = $indeterminate = [];
        foreach ($households as $blindIndex => $household) {
            // A stale ignore decision does not cover a changed household.
            // Reading never deletes it; a new decision replaces it.
            $entry = $ignored[$blindIndex] ?? null;
            if ($entry !== null && !$entry->stillApplies(self::compositionHash($household))) {
                $entry = null;
            }
            $review = $this->review(
                $household,
                $memberRows,
                $addressLabels[$blindIndex] ?? 'Adresse inconnue',
                $feeCategories,
                $guests,
                $amounts,
                $entry
            );
            if ($entry !== null) {
                $setAside[] = $review;
                continue;
            }
            if ($review->needsCorrection) {
                $toCorrect[] = $review;
            }
            if ($review->willChange) {
                $upcoming[] = $review;
            }
            if ($review->hasIndeterminateChecks()) {
                $indeterminate[] = $review;
            }
        }

        return new FeeAccuracyReport(
            $toCorrect,
            $upcoming,
            $setAside,
            $this->households->memberYearIdsWithoutUsableAddress($scoutYearId),
            count($households),
            $indeterminate
        );
    }

    public static function compositionHash(Household $household): string
    {
        $ids = $household->memberYearIds();
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    /**
     * @param array<int, array{member_id: int, first_name: string, last_name: string, totem: ?string,
     *     fee_category_id: ?int, leaving: bool, leaving_marked_at: ?string}> $rows
     * @param array<int, array{desk_code: string, label: string}> $categories
     * @param array<int, GuestEligibility> $guests
     * @param array<string, ?int> $amounts
     */
    private function review(
        Household $household,
        array $rows,
        string $address,
        array $categories,
        array $guests,
        array $amounts,
        ?IgnoredHousehold $ignored
    ): HouseholdReview {
        $retained = self::count($household, $guests, false);
        $projectedCount = self::count($household, $guests, true);
        $expected = $retained->category();
        $projected = $projectedCount->category();
        $members = $this->reviewMembers($household, $rows, $categories, $guests, $expected);
        $mismatches = array_values(array_filter(
            $members,
            static fn(HouseholdReviewMember $m): bool => $m->needsCorrection()
        ));
        $unknownMembers = array_filter($members, static fn(HouseholdReviewMember $m): bool => $m->isIndeterminate());
        $difference = $mismatches === [] || $unknownMembers !== []
            ? null : self::correctionDifference($mismatches, $amounts);
        $remainingCandidates = array_filter(
            $members,
            static fn(HouseholdReviewMember $m): bool => !$m->leaving && $m->guest->qualifies === false
                && $m->encodedCategory !== FeeTariff::IAMA
        );
        $willChange = $remainingCandidates !== [] && $expected !== null
            && $projected !== null && $expected !== $projected;
        $projectedDifference = $willChange ? self::projectedDifference(
            $members,
            $amounts,
            $expected,
            $projected,
            $household->incomingRegistrations
        ) : null;

        return new HouseholdReview(
            $household->addressBlindIndex,
            $address,
            $members,
            $household->deskSize(),
            $household->projectedSize(),
            $expected,
            $projected,
            $mismatches !== [],
            $willChange,
            $difference,
            $projectedDifference,
            $household->leavingMemberYearIds(),
            $household->incomingRegistrations,
            $retained,
            $projectedCount,
            $remainingCandidates !== [] && $projected === null
                && ($household->leavingMemberYearIds() !== [] || $household->incomingRegistrations > 0),
            $ignored
        );
    }

    /**
     * @param array<int, array{member_id: int, first_name: string, last_name: string, totem: ?string,
     *     fee_category_id: ?int, leaving: bool, leaving_marked_at: ?string}> $rows
     * @param array<int, array{desk_code: string, label: string}> $categories
     * @param array<int, GuestEligibility> $guests
     * @return list<HouseholdReviewMember>
     */
    private function reviewMembers(
        Household $household,
        array $rows,
        array $categories,
        array $guests,
        ?HouseholdFeeCategory $expected
    ): array {
        $members = [];
        foreach ($household->members as $person) {
            $row = $rows[$person->memberYearId] ?? null;
            if ($row === null) {
                continue;
            }
            $encoded = $this->tariffs->tariffForFeeCategoryId($row['fee_category_id']);
            $category = $categories[$row['fee_category_id']] ?? null;
            $guest = $guests[$person->memberId] ?? new GuestEligibility(null, GuestEligibility::UNKNOWN_STARTS);
            $applicable = match (true) {
                $guest->qualifies === true => FeeTariff::GUEST,
                $guest->qualifies === null => null,
                $encoded === FeeTariff::IAMA => FeeTariff::IAMA,
                default => $expected === null ? null : FeeTariff::from($expected->value),
            };
            $members[] = new HouseholdReviewMember(
                $person->memberId,
                $person->memberYearId,
                $row['first_name'],
                $row['last_name'],
                $row['totem'],
                $row['fee_category_id'],
                $category['label'] ?? null,
                $category['desk_code'] ?? null,
                $encoded,
                $applicable,
                $guest,
                $applicable === null ? [] : $this->tariffs->deskCodesFor($applicable),
                $person->leaving,
                $row['leaving_marked_at']
            );
        }

        return $members;
    }

    /** @param array<int, GuestEligibility> $guests */
    private static function count(Household $household, array $guests, bool $projected): HouseholdFeeCount
    {
        $minimum = 0;
        $maximum = 0;
        foreach ($household->members as $person) {
            if ($projected && $person->leaving) {
                continue;
            }
            $guest = $guests[$person->memberId]->qualifies ?? null;
            $minimum += $guest === false ? 1 : 0;
            $maximum += $guest === true ? 0 : 1;
        }
        // Accepted registrations carry only a count here, not individual
        // membership dates or tariffs. Each may or may not count.
        if ($projected) {
            $maximum += $household->incomingRegistrations;
        }

        return new HouseholdFeeCount($minimum, $maximum);
    }

    /**
     * @param list<HouseholdReviewMember> $members
     * @param array<string, ?int> $amounts
     */
    private static function projectedDifference(
        array $members,
        array $amounts,
        ?HouseholdFeeCategory $expected,
        ?HouseholdFeeCategory $projected,
        int $incoming
    ): ?int {
        $perPerson = self::difference($amounts, $projected?->value, $expected?->value);
        if ($perPerson === null || $incoming > 0) {
            return null;
        }
        $count = 0;
        foreach ($members as $member) {
            if ($member->leaving || $member->guest->qualifies === true
                || $member->encodedCategory === FeeTariff::IAMA) {
                continue;
            }
            if ($member->guest->qualifies !== false || $member->encodedCategory === null) {
                return null;
            }
            $count++;
        }

        return $perPerson * $count;
    }

    /** @param list<HouseholdReviewMember> $members
     *  @param array<string, ?int> $amounts
     */
    private static function correctionDifference(array $members, array $amounts): ?int
    {
        $total = 0;
        foreach ($members as $member) {
            $difference = self::difference(
                $amounts,
                $member->expectedCategory?->value,
                $member->encodedCategory?->value
            );
            if ($difference === null) {
                return null;
            }
            $total += $difference;
        }

        return $total;
    }

    /** @param array<string, ?int> $amounts */
    private static function difference(array $amounts, ?string $expected, ?string $encoded): ?int
    {
        $expectedAmount = $amounts[$expected] ?? null;
        $encodedAmount = $amounts[$encoded] ?? null;

        return $expectedAmount === null || $encodedAmount === null ? null : $expectedAmount - $encodedAmount;
    }

    /** @return array<int, array{desk_code: string, label: string}> */
    private function feeCategoriesById(): array
    {
        $byId = [];
        foreach ($this->feeCategories->findAll() as $category) {
            $byId[$category['id']] = ['desk_code' => $category['desk_code'], 'label' => $category['label']];
        }

        return $byId;
    }
}
