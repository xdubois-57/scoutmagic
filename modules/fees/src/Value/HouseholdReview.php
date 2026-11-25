<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Core\Member\HouseholdFeeCategory;

/**
 * One physical household and its retained tariff range. Certain encoding
 * errors, known future changes and incomplete checks are independent:
 * the same household may legitimately appear in several views.
 */
final class HouseholdReview
{
    /**
     * @param HouseholdReviewMember[] $members
     * @param int[] $leavingMemberYearIds
     */
    public function __construct(
        public readonly string $addressBlindIndex,
        public readonly string $addressLabel,
        public readonly array $members,
        public readonly int $deskSize,
        public readonly int $projectedSize,
        public readonly ?HouseholdFeeCategory $expectedCategory,
        public readonly ?HouseholdFeeCategory $projectedCategory,
        public readonly bool $needsCorrection,
        public readonly bool $willChange,
        public readonly ?int $differenceCents,
        public readonly ?int $projectedDifferenceCents,
        public readonly array $leavingMemberYearIds,
        public readonly int $incomingRegistrations,
        public readonly HouseholdFeeCount $retainedCount,
        public readonly HouseholdFeeCount $projectedCount,
        public readonly bool $projectionIndeterminate,
        public readonly ?IgnoredHousehold $ignored = null
    ) {
    }

    public function hasIndeterminateChecks(): bool
    {
        return $this->projectionIndeterminate || $this->indeterminateMembers() !== [];
    }

    /** @return HouseholdReviewMember[] */
    public function indeterminateMembers(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn(HouseholdReviewMember $m): bool => $m->isIndeterminate()
        ));
    }

    /** @return HouseholdReviewMember[] the ones actually on the wrong tariff */
    public function mismatchedMembers(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn(HouseholdReviewMember $m): bool => $m->needsCorrection()
        ));
    }

    /** @return HouseholdReviewMember[] the departures that will move this household */
    public function leavingMembers(): array
    {
        $leaving = array_flip($this->leavingMemberYearIds);

        return array_values(array_filter(
            $this->members,
            static fn(HouseholdReviewMember $m): bool => isset($leaving[$m->memberYearId])
        ));
    }
}
