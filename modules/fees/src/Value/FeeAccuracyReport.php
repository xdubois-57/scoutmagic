<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/** The page's five views, with explicit incomplete coverage and unknown amounts. */
final class FeeAccuracyReport
{
    /**
     * @param HouseholdReview[] $toCorrect households whose encoding does not match what Desk holds today
     * @param HouseholdReview[] $upcoming households correct today that a known movement will move
     * @param HouseholdReview[] $ignored households a chef d'unité set aside, with their reason
     * @param HouseholdReview[] $indeterminate households with incomplete current or projected checks
     * @param int[] $withoutAddressMemberYearIds members no household statement covers
     */
    public function __construct(
        public readonly array $toCorrect,
        public readonly array $upcoming,
        public readonly array $ignored,
        public readonly array $withoutAddressMemberYearIds,
        public readonly int $householdCount,
        public readonly array $indeterminate
    ) {
    }

    public function withoutAddressCount(): int
    {
        return count($this->withoutAddressMemberYearIds);
    }

    /**
     * The signed total of what is wrong TODAY, in cents, or null when the
     * roster or barème leaves any current check or amount unknown.
     *
     * Positive means the unit is declaring less than it owes — the amount
     * that comes back in the regularisation invoice, and the reason the
     * sign is never hidden.
     */
    public function toCorrectDifferenceCents(): ?int
    {
        foreach ($this->indeterminate as $review) {
            if ($review->indeterminateMembers() !== []) {
                return null;
            }
        }
        $total = 0;
        $known = false;
        foreach ($this->toCorrect as $review) {
            if ($review->differenceCents === null) {
                return null;
            }
            $total += $review->differenceCents;
            $known = true;
        }

        return $known ? $total : null;
    }
}
