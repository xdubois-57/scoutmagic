<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Member\Household\HouseholdKey;
use Modules\Registration\Api\HouseholdRegistrationCountProvider;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Concrete Api\HouseholdRegistrationCountProvider — a thin pass-through to
 * the repository's own per-household count, kept as its own class so
 * the composition root wires a stable Api-shaped object rather than the
 * repository directly (ARCHITECTURE.md §7.5).
 */
class HouseholdRegistrationCountService implements HouseholdRegistrationCountProvider
{
    public function __construct(private RegistrationRequestRepository $repository)
    {
    }

    public function countInHousehold(HouseholdKey $household, int $scoutYearId, ?int $excludeRequestId): int
    {
        return $this->repository->countRequestsInHousehold($household, $scoutYearId, $excludeRequestId);
    }

    /**
     * @param HouseholdKey[] $households
     * @return array<string, int>
     */
    public function countsInHouseholds(array $households, int $scoutYearId): array
    {
        return $this->repository->countRequestsInHouseholds($households, $scoutYearId);
    }
}
