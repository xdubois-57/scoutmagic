<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;
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
        private EncryptionService $encryption,
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
            return new FeeEstimate(HouseholdFeeCategory::NORMAL, 0);
        }

        $blindIndex = $this->encryption->blindIndex($normalized);
        $count = $this->repository->countHouseholdMembers($blindIndex, $scoutYearId);
        if ($this->registrationCount !== null) {
            $count += $this->registrationCount->countAtAddress($blindIndex, $scoutYearId, $excludeRegistrationRequestId);
        }

        return new FeeEstimate(HouseholdFeeCategory::fromHouseholdSize($count), $count);
    }
}
