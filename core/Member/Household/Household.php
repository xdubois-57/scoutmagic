<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Household;

use Core\Member\HouseholdFeeCategory;

/**
 * Everyone the site knows at one normalized address, for one scout year,
 * carrying BOTH counts rather than one (ARCHITECTURE.md §8.34):
 *
 * - **Desk** ({@see deskSize()}) — what the roster contains right now:
 *   every active member_year at this address, the ones marked leaving
 *   included. This is what the federation invoices, so it is the only
 *   count an invoice or an encoded fee category can be compared against.
 * - **Projected** ({@see projectedSize()}) — what the roster will contain
 *   once the movements already known are acted on: Desk minus the members
 *   marked leaving, plus the accepted/encoded registration requests at the
 *   same address. This is the count Core\Member\FeeEstimationService has
 *   always returned, and the one the registration fiche shows.
 *
 * The two differ for exactly two reasons, and both are readable here:
 * {@see leavingMemberYearIds()} says who goes, $incomingRegistrations says
 * how many arrive. A caller never has to re-derive the difference.
 *
 * A member_year with two distinct addresses (a Domicile and an Adresse
 * secondaire that normalize differently) belongs to two households — the
 * same behaviour FeeEstimationService has always had, since it counts
 * every address row matching the blind index. A screen that lists
 * households therefore shows such a member twice, on purpose: which of the
 * two addresses the federation bills on is not something the site knows.
 */
final class Household
{
    /**
     * @param HouseholdMember[] $members every active member_year at this address, leaving ones included
     * @param int $incomingRegistrations accepted/encoded registration requests at the same address (0 when the registration module is disabled)
     */
    public function __construct(
        public readonly string $addressBlindIndex,
        public readonly array $members,
        public readonly int $incomingRegistrations = 0
    ) {
    }

    /** @return int[] */
    public function memberYearIds(): array
    {
        return array_map(static fn(HouseholdMember $m): int => $m->memberYearId, $this->members);
    }

    /** @return int[] the member_year ids Desk still counts and the projection does not */
    public function leavingMemberYearIds(): array
    {
        return array_values(array_map(
            static fn(HouseholdMember $m): int => $m->memberYearId,
            array_filter($this->members, static fn(HouseholdMember $m): bool => $m->leaving)
        ));
    }

    /** What Desk contains today — the count the federation bills on. */
    public function deskSize(): int
    {
        return count($this->members);
    }

    /** What Desk will contain once the known movements are encoded. */
    public function projectedSize(): int
    {
        return $this->deskSize() - count($this->leavingMemberYearIds()) + $this->incomingRegistrations;
    }

    public function deskCategory(): HouseholdFeeCategory
    {
        return HouseholdFeeCategory::fromHouseholdSize($this->deskSize());
    }

    public function projectedCategory(): HouseholdFeeCategory
    {
        return HouseholdFeeCategory::fromHouseholdSize($this->projectedSize());
    }

    /**
     * True when the two counts land on two different fee categories — the
     * only difference that costs anything. Two counts can differ (4 → 3)
     * without the category moving, and a screen must not present that as
     * something to act on.
     */
    public function categoriesDiffer(): bool
    {
        return $this->deskCategory() !== $this->projectedCategory();
    }
}
