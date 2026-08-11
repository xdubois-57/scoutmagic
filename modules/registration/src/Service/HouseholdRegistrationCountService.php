<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Api\HouseholdRegistrationCountProvider;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Concrete Api\HouseholdRegistrationCountProvider — a thin pass-through to
 * the repository's own blind-index-based count, kept as its own class so
 * the composition root wires a stable Api-shaped object rather than the
 * repository directly (ARCHITECTURE.md §7.5).
 */
class HouseholdRegistrationCountService implements HouseholdRegistrationCountProvider
{
    public function __construct(private RegistrationRequestRepository $repository)
    {
    }

    public function countAtAddress(string $addressBlindIndex, int $scoutYearId, ?int $excludeRequestId): int
    {
        return $this->repository->countHouseholdAtAddress($addressBlindIndex, $scoutYearId, $excludeRequestId);
    }
}
