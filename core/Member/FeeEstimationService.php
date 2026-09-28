<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Modules\Registration\Api\HouseholdRegistrationCountProvider;

/**
 * Suggests a fee category from how many people already live at a given
 * address (ARCHITECTURE.md §8) — never a value applied automatically,
 * always contestable: a shared house, two families at the same number, a
 * shared-custody arrangement all produce false positives no address
 * normalization can catch. The caller always gets both the suggested
 * category AND the raw household size so it can show its work.
 *
 * Counts existing members (Core\Member\FeeEstimationRepository) plus,
 * when the registration module is enabled, its 'accepted'/'encoded'
 * registration requests at the same address (ARCHITECTURE.md §7.5 —
 * $registrationCount is wired in the composition root only then, and
 * degrades to counting members alone when null).
 */
class FeeEstimationService
{
    public function __construct(
        private FeeEstimationRepository $repository,
        private ?HouseholdRegistrationCountProvider $registrationCount = null
    ) {
    }

    /**
     * $excludeRegistrationRequestId: when estimating for a registration
     * request's own address (Modules\Registration\Controller\
     * RegistrationRequestController's fiche), that request's own id, so
     * it never counts itself via $registrationCount. Meaningless
     * (harmlessly ignored) when $registrationCount is null.
     */
    public function estimate(
        ?string $street,
        ?string $number,
        ?string $box,
        ?string $postalCode,
        int $scoutYearId,
        ?int $excludeRegistrationRequestId = null
    ): FeeEstimate {
        $normalized = AddressNormalizer::normalize($street, $number, $box, $postalCode);
        if ($normalized === '') {
            // Not "this household is on the normal tariff" — "no address to
            // look one up with". FeeEstimate::addressNotUsable() is what
            // keeps the two apart for the caller (see that class).
            return FeeEstimate::addressNotUsable();
        }

        // The key comes from the repository, which owns the encryption
        // dependency and the purpose string with it (SECURITY.md §5): this
        // Service used to compute the blind index itself, so it had to know
        // that `'address'` is the purpose — a detail no second layer should
        // be able to get wrong. What crosses to the module below is that same
        // key as an opaque token, because its contract keys on it.
        $householdKey = $this->repository->householdKeyFor($normalized);
        $count = $this->repository->countProjectedHouseholdMembers($householdKey->storable(), $scoutYearId);
        if ($this->registrationCount !== null) {
            $count += $this->registrationCount->countInHousehold(
                $householdKey,
                $scoutYearId,
                $excludeRegistrationRequestId
            );
        }

        return FeeEstimate::forHouseholdSize($count);
    }
}
