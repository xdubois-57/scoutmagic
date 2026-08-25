<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Household;

use Core\Member\AddressNormalizer;
use Core\Security\EncryptionService;
use Modules\Registration\Api\HouseholdRegistrationCountProvider;

/**
 * Answers, for one household or for every household of a scout year, the
 * two questions the site has to keep apart (ARCHITECTURE.md §8.34):
 * **what Desk contains today** — the only thing the federation bills — and
 * **what it will contain** once the movements already known are encoded.
 *
 * Core\Member\FeeEstimationService answers the second one alone, for the
 * registration fiche, and keeps doing exactly that. This service is the
 * one that reports both, plus the difference between them, and is what a
 * screen comparing an encoded fee category against reality reads from.
 *
 * The registration provider is the same optional module dependency
 * FeeEstimationService takes (ARCHITECTURE.md §7.5): null when the module
 * is disabled, in which case nobody is arriving and the two counts differ
 * only by who is leaving.
 */
class HouseholdService
{
    public function __construct(
        private HouseholdRepository $repository,
        private EncryptionService $encryption,
        private ?HouseholdRegistrationCountProvider $registrationCount = null
    ) {
    }

    /**
     * Every household of a scout year, keyed by address blind index, with
     * both counts already resolved.
     *
     * Two queries whatever the unit's size: one for the roster, one for
     * the registration requests of every address it found.
     *
     * @return array<string, Household>
     */
    public function householdsForYear(int $scoutYearId): array
    {
        $households = $this->repository->findHouseholdsForYear($scoutYearId);
        if ($households === [] || $this->registrationCount === null) {
            return $households;
        }

        $counts = $this->registrationCount->countsAtAddresses(array_keys($households), $scoutYearId);
        foreach ($counts as $blindIndex => $count) {
            if (!isset($households[$blindIndex])) {
                continue;
            }
            $households[$blindIndex] = new Household(
                $blindIndex,
                $households[$blindIndex]->members,
                $count
            );
        }

        return $households;
    }

    /**
     * The household at one address, or null when the address cannot be
     * normalized into anything comparable.
     *
     * **Null is not "a household of one"** — it is "the site cannot say".
     * Core\Member\FeeEstimationService returns NORMAL/0 in that same case,
     * which reads as a real answer and is the trap this method exists to
     * remove: a member with no usable address is neither compliant nor in
     * breach, and a caller must be able to put them aside rather than
     * count them as correct.
     *
     * An address that normalizes fine but matches nobody is a different
     * thing again: a real Household with no member, whose Desk count is 0.
     */
    public function householdAt(
        ?string $street,
        ?string $number,
        ?string $box,
        ?string $postalCode,
        int $scoutYearId,
        ?int $excludeRegistrationRequestId = null
    ): ?Household {
        $normalized = AddressNormalizer::normalize($street, $number, $box, $postalCode);
        if ($normalized === '') {
            return null;
        }

        $blindIndex = $this->encryption->blindIndex($normalized, 'address');
        $members = $this->repository->findMembersAtAddress($blindIndex, $scoutYearId);
        $incoming = $this->registrationCount?->countAtAddress($blindIndex, $scoutYearId, $excludeRegistrationRequestId) ?? 0;

        return new Household($blindIndex, $members, $incoming);
    }

    /**
     * The active member_years of the year no household statement covers,
     * because none of their addresses normalized to anything.
     *
     * A screen summarising households has to state this count rather than
     * let a reader assume everyone was checked.
     *
     * @return int[] member_year ids
     */
    public function memberYearIdsWithoutUsableAddress(int $scoutYearId): array
    {
        return $this->repository->findMemberYearIdsWithoutUsableAddress($scoutYearId);
    }
}
