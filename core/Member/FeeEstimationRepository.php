<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;

/**
 * The PROJECTED household count, and only that one.
 *
 * Two counts exist and they answer different questions (ARCHITECTURE.md
 * §8.34): what Desk contains today, which is what the federation bills,
 * and what it will contain once the known movements are encoded. This
 * repository owns the second. The first lives in Core\Member\Household\
 * HouseholdRepository, which reports member_years.leaving instead of
 * filtering on it — the two are deliberately separate reads rather than
 * one flagged method, so a caller cannot get the wrong one by passing the
 * wrong boolean.
 */
class FeeEstimationRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * The key a household is identified by: the blind index of its
     * normalized address.
     *
     * **Derived here rather than by the caller** (SECURITY.md §5,
     * ARCHITECTURE.md §13). It used to be computed in
     * {@see \Core\Member\FeeEstimationService}, which meant a Service knew
     * the purpose string `'address'` — and a purpose string is the one part
     * of a blind index that must never be guessed twice: two layers writing
     * it from memory is how an index silently stops matching the rows it was
     * built for. One layer owns it, and it is the one that already owns the
     * encryption dependency.
     *
     * It is returned rather than kept private because
     * {@see \Modules\Registration\Api\HouseholdRegistrationCountProvider}
     * keys its own counts on this very value — its contract is the key, not
     * the address. The Service therefore relays an opaque token across that
     * boundary instead of deriving one.
     */
    public function householdKeyFor(string $normalizedAddress): string
    {
        return $this->encryption->blindIndex($normalizedAddress, 'address');
    }

    /**
     * Distinct member_years matching the normalized address blind index,
     * for the given scout year, excluding anyone marked leaving (Core\
     * Member\DepartureService) — a household member who won't be back
     * shouldn't inflate the suggested fee category.
     *
     * Deliberately NOT what the federation bills: Desk still holds a
     * member marked leaving, so an invoice or an encoded fee category is
     * compared against Core\Member\Household\Household::deskSize(), never
     * against this.
     */
    public function countProjectedHouseholdMembers(string $addressBlindIndex, int $scoutYearId): int
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
