<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\Household\Household;
use Core\Member\Household\HouseholdService;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Value\FeeAccuracyReport;
use Modules\Fees\Value\HouseholdReview;
use Modules\Fees\Value\HouseholdReviewMember;
use Modules\Fees\Value\IgnoredHousehold;

/**
 * Confronts the fee category encoded in Desk with the one the number of
 * people at the same address implies.
 *
 * **What it compares against is the DESK count**, never the projected one
 * (`Core\Member\Household\Household`, ARCHITECTURE.md §8.34). The
 * federation bills what Desk contains, so a household with a departure
 * announced is not in breach today — it is correct today and will move
 * later, which is a different tab and a different sentence.
 *
 * **The two verdicts are independent.** A household can need correcting AND
 * be about to move; the screen shows both and lets the treasurer arbitrate.
 *
 * **A member on a tariff outside the three is not judged.** "Tarif
 * animateur", "Tarif réduit" and an iAM membership are not household
 * tariffs, so their holder is counted in the household's size — the
 * federation counts people, not tariffs — but never reported as wrong.
 */
class FeeAccuracyService
{
    public function __construct(
        private HouseholdService $households,
        private HouseholdDetailRepository $details,
        private HouseholdTariffService $tariffs,
        private IgnoredHouseholdRepository $ignoredHouseholds,
        private FeeCategoryRepository $feeCategories
    ) {
    }

    public function report(int $scoutYearId): FeeAccuracyReport
    {
        $households = $this->households->householdsForYear($scoutYearId);
        $ignored = $this->ignoredHouseholds->findAllForYear($scoutYearId);

        $memberYearIds = [];
        foreach ($households as $household) {
            foreach ($household->memberYearIds() as $id) {
                $memberYearIds[] = $id;
            }
        }

        $memberRows = $this->details->findMembers($memberYearIds);
        $addressLabels = $this->details->findAddressLabels(array_keys($households));
        $feeCategoryLabels = $this->feeCategoryLabels();

        $toCorrect = [];
        $upcoming = [];
        $setAside = [];

        foreach ($households as $blindIndex => $household) {
            // A decision taken about a different set of people does not
            // cover this one: the household comes back rather than staying
            // silently excluded (Value\IgnoredHousehold::stillApplies()).
            // The stale row is left where it is — a GET writes nothing —
            // and is replaced the next time somebody sets this household
            // aside.
            $ignoredEntry = $ignored[$blindIndex] ?? null;
            if ($ignoredEntry !== null && !$ignoredEntry->stillApplies(self::compositionHash($household))) {
                $ignoredEntry = null;
            }

            $review = $this->review($household, $blindIndex, $memberRows, $addressLabels, $feeCategoryLabels,
                $ignoredEntry);

            if ($ignoredEntry !== null) {
                $setAside[] = $review;
                continue;
            }

            if ($review->needsCorrection) {
                $toCorrect[] = $review;
            }
            if ($review->willChange) {
                $upcoming[] = $review;
            }
        }

        return new FeeAccuracyReport(
            $toCorrect,
            $upcoming,
            $setAside,
            $this->households->memberYearIdsWithoutUsableAddress($scoutYearId),
            count($households)
        );
    }

    /**
     * The fingerprint an "ignore this household" decision is taken about.
     *
     * Member ids, sorted, hashed — a household coming back is exactly the
     * event of someone arriving or leaving, and a decision taken about
     * three people should not silently cover a fourth. Sorted because the
     * query's own order is not a promise.
     */
    public static function compositionHash(Household $household): string
    {
        $ids = $household->memberYearIds();
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }

    /**
     * @param array<
     *     int,
     *     array{
     *         member_id: int,
     *         first_name: string,
     *         last_name: string,
     *         totem: ?string,
     *         fee_category_id: ?int,
     *         leaving: bool,
     *         leaving_marked_at: ?string
     *     }
     * > $memberRows
     * @param array<string, string> $addressLabels
     * @param array<int, string> $feeCategoryLabels
     */
    private function review(
        Household $household,
        string $blindIndex,
        array $memberRows,
        array $addressLabels,
        array $feeCategoryLabels,
        ?IgnoredHousehold $ignored
    ): HouseholdReview {
        $expected = $household->deskCategory();
        $projected = $household->projectedCategory();

        $members = [];
        foreach ($household->members as $householdMember) {
            $row = $memberRows[$householdMember->memberYearId] ?? null;
            if ($row === null) {
                continue;
            }

            $encodedCategory = $this->tariffs->categoryForFeeCategoryId($row['fee_category_id']);
            $members[] = new HouseholdReviewMember(
                $householdMember->memberId,
                $householdMember->memberYearId,
                $row['first_name'],
                $row['last_name'],
                $row['totem'],
                $row['fee_category_id'],
                $row['fee_category_id'] === null ? null : ($feeCategoryLabels[$row['fee_category_id']] ?? null),
                $encodedCategory,
                $encodedCategory !== null,
                $householdMember->leaving,
                $row['leaving_marked_at']
            );
        }

        $mismatched = array_filter(
            $members,
            static fn(HouseholdReviewMember $m): bool => $m->comparable && !$m->matches($expected)
        );

        $difference = null;
        foreach ($mismatched as $member) {
            $memberDifference = $this->tariffs->differenceCents($expected, $member->encodedCategory);
            if ($memberDifference !== null) {
                $difference = ($difference ?? 0) + $memberDifference;
            }
        }

        // What the household will cost once the known movement is acted on,
        // minus what those same people cost today: the projected size at the
        // projected tariff against the same size at today's tariff. Labelled
        // "à la bascule" on screen, never "en écart" — nothing about it is
        // wrong yet. Only computed when the category actually moves: four
        // people becoming three is famille either way, and a figure there
        // would be an invented change.
        $projectedDifference = null;
        if ($expected !== $projected) {
            $perMember = $this->tariffs->differenceCents($projected, $expected);
            if ($perMember !== null) {
                $projectedDifference = $perMember * max(0, $household->projectedSize());
            }
        }

        return new HouseholdReview(
            $blindIndex,
            $addressLabels[$blindIndex] ?? 'Adresse inconnue',
            $members,
            $household->deskSize(),
            $household->projectedSize(),
            $expected,
            $projected,
            $mismatched !== [],
            $household->categoriesDiffer(),
            $difference,
            $projectedDifference,
            $household->leavingMemberYearIds(),
            $household->incomingRegistrations,
            $ignored
        );
    }

    /** @return array<int, string> */
    private function feeCategoryLabels(): array
    {
        $labels = [];
        foreach ($this->feeCategories->findAll() as $feeCategory) {
            $labels[$feeCategory['id']] = $feeCategory['label'];
        }

        return $labels;
    }
}
