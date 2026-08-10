<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;

/**
 * Suggests a fee category from how many people already live at a given
 * address (ARCHITECTURE.md §8) — never a value applied automatically,
 * always contestable: a shared house, two families at the same number, a
 * shared-custody arrangement all produce false positives no address
 * normalization can catch. The caller always gets both the suggested
 * category AND the raw household size so it can show its work.
 *
 * Counts existing members only (Core\Member\FeeEstimationRepository) —
 * the "accepted registrations" module doesn't exist yet, and
 * ARCHITECTURE.md §7.5 requires that a module publish its own Api
 * interface for core to optionally depend on, not the reverse. Once
 * the future inscriptions module does that (iteration 5/6), the fix here
 * is additive, not a rewrite: add a nullable constructor parameter for
 * that interface and sum its count into the total below — no interface
 * is invented speculatively here since none exists to depend on yet.
 */
class FeeEstimationService
{
    public function __construct(
        private FeeEstimationRepository $repository,
        private EncryptionService $encryption
    ) {
    }

    public function estimate(?string $street, ?string $number, ?string $box, ?string $postalCode, int $scoutYearId): FeeEstimate
    {
        $normalized = AddressNormalizer::normalize($street, $number, $box, $postalCode);
        if ($normalized === '') {
            return new FeeEstimate(HouseholdFeeCategory::NORMAL, 0);
        }

        $blindIndex = $this->encryption->blindIndex($normalized);
        $count = $this->repository->countHouseholdMembers($blindIndex, $scoutYearId);

        return new FeeEstimate(HouseholdFeeCategory::fromHouseholdSize($count), $count);
    }
}
