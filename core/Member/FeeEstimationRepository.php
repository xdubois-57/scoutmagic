<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

class FeeEstimationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Distinct member_years matching the normalized address blind index,
     * for the given scout year, excluding anyone marked leaving (Core\
     * Member\DepartureService) — a household member who won't be back
     * shouldn't inflate the suggested fee category.
     */
    public function countHouseholdMembers(string $addressBlindIndex, int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT my.id)
             FROM member_years my
             JOIN member_addresses ma ON ma.member_year_id = my.id
             WHERE ma.address_normalized_blind_index = ?
               AND my.scout_year_id = ?
               AND my.is_active = 1
               AND my.leaving = 0'
        );
        $stmt->execute([$addressBlindIndex, $scoutYearId]);

        return (int) $stmt->fetchColumn();
    }
}
