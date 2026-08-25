<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/**
 * Everything the "Justesse des tarifs" screen shows, in the four buckets it
 * shows it in — and the two numbers that stop the summary from claiming a
 * coverage it does not have.
 *
 * `$withoutAddressCount` is the second of those, and it is not a detail: a
 * member whose address normalises to nothing is neither compliant nor in
 * breach, and a screen that quietly left them out would read as "everybody
 * was checked".
 */
final class FeeAccuracyReport
{
    /**
     * @param HouseholdReview[] $toCorrect households whose encoding does not match what Desk holds today
     * @param HouseholdReview[] $upcoming households correct today that a known movement will move
     * @param HouseholdReview[] $ignored households a chef d'unité set aside, with their reason
     * @param int[] $withoutAddressMemberYearIds members no household statement covers
     */
    public function __construct(
        public readonly array $toCorrect,
        public readonly array $upcoming,
        public readonly array $ignored,
        public readonly array $withoutAddressMemberYearIds,
        public readonly int $householdCount
    ) {
    }

    public function withoutAddressCount(): int
    {
        return count($this->withoutAddressMemberYearIds);
    }

    /**
     * The signed total of what is wrong TODAY, in cents, or null when the
     * barème carries no amount for one of the categories involved.
     *
     * Positive means the unit is declaring less than it owes — the amount
     * that comes back in the regularisation invoice, and the reason the
     * sign is never hidden.
     */
    public function toCorrectDifferenceCents(): ?int
    {
        $total = 0;
        $known = false;
        foreach ($this->toCorrect as $review) {
            if ($review->differenceCents === null) {
                continue;
            }
            $total += $review->differenceCents;
            $known = true;
        }

        return $known ? $total : null;
    }
}
