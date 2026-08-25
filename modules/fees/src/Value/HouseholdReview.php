<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Core\Member\HouseholdFeeCategory;

/**
 * One household, judged.
 *
 * The two verdicts are independent and a household can carry both, which is
 * the whole reason they are two fields rather than one status:
 *
 * - $needsCorrection — what Desk holds today does not match the number of
 *   people Desk holds at this address. It is wrong now, it is on the next
 *   invoice, and it is worth a correction today.
 * - $willChange — Desk is right today and will stop being right once a
 *   movement already announced is acted on. Nothing to do yet; touching it
 *   now means two corrections instead of one.
 *
 * A household in both states is an arbitrage the screen shows and does not
 * make: correct now and again at the switch (two edits), or wait (one edit,
 * at the price of one inexact invoice in between).
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
        public readonly HouseholdFeeCategory $expectedCategory,
        public readonly HouseholdFeeCategory $projectedCategory,
        public readonly bool $needsCorrection,
        public readonly bool $willChange,
        public readonly ?int $differenceCents,
        public readonly ?int $projectedDifferenceCents,
        public readonly array $leavingMemberYearIds,
        public readonly int $incomingRegistrations,
        public readonly ?IgnoredHousehold $ignored = null
    ) {
    }

    /** @return HouseholdReviewMember[] the ones the comparison actually judged */
    public function comparableMembers(): array
    {
        return array_values(array_filter($this->members, static fn(HouseholdReviewMember $m): bool => $m->comparable));
    }

    /** @return HouseholdReviewMember[] on a tariff outside the three, or on none at all */
    public function unclassifiedMembers(): array
    {
        return array_values(array_filter($this->members, static fn(HouseholdReviewMember $m): bool => !$m->comparable));
    }

    /** @return HouseholdReviewMember[] the ones actually on the wrong tariff */
    public function mismatchedMembers(): array
    {
        return array_values(array_filter(
            $this->comparableMembers(),
            fn(HouseholdReviewMember $m): bool => !$m->matches($this->expectedCategory)
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
