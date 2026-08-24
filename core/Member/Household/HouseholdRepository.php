<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Household;

/**
 * Reads households out of member_addresses.address_normalized_blind_index
 * (ARCHITECTURE.md §8.34) — never out of a readable address, which is
 * encrypted and cannot be grouped on.
 *
 * Every query here selects active member_years and says NOTHING about
 * member_years.leaving beyond reporting it. Filtering on it belongs to
 * Core\Member\Household\Household, which needs both answers from one read;
 * a repository that filtered would make the Desk count unobtainable.
 */
class HouseholdRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every household of a scout year, keyed by address blind index.
     *
     * One query for the whole unit: the enumeration feeds a screen that
     * lists hundreds of households, and a per-household read would be an
     * N+1 walk over the roster.
     *
     * Rows whose blind index is NULL or empty are absent — an address the
     * import could not normalize is not a household of one, it is an
     * unknown, and a caller has to be able to tell the two apart
     * (Core\Member\Household\HouseholdService::withoutUsableAddress()).
     *
     * @return array<string, Household> address blind index => household
     */
    public function findHouseholdsForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT ma.address_normalized_blind_index AS blind_index,
                    my.id AS member_year_id,
                    my.member_id AS member_id,
                    my.leaving AS leaving
             FROM member_years my
             JOIN member_addresses ma ON ma.member_year_id = my.id
             WHERE my.scout_year_id = ?
               AND my.is_active = 1
               AND ma.address_normalized_blind_index IS NOT NULL
               AND ma.address_normalized_blind_index <> \'\'
             ORDER BY ma.address_normalized_blind_index, my.id'
        );
        $stmt->execute([$scoutYearId]);

        /** @var array<string, HouseholdMember[]> $grouped */
        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $grouped[(string) $row['blind_index']][] = new HouseholdMember(
                (int) $row['member_year_id'],
                (int) $row['member_id'],
                (bool) $row['leaving']
            );
        }

        $households = [];
        foreach ($grouped as $blindIndex => $members) {
            $households[$blindIndex] = new Household($blindIndex, $members);
        }

        return $households;
    }

    /**
     * The members of one household. Same shape as one entry of
     * {@see findHouseholdsForYear()}, for a caller holding one address.
     *
     * @return HouseholdMember[]
     */
    public function findMembersAtAddress(string $addressBlindIndex, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT my.id AS member_year_id,
                    my.member_id AS member_id,
                    my.leaving AS leaving
             FROM member_years my
             JOIN member_addresses ma ON ma.member_year_id = my.id
             WHERE ma.address_normalized_blind_index = ?
               AND my.scout_year_id = ?
               AND my.is_active = 1
             ORDER BY my.id'
        );
        $stmt->execute([$addressBlindIndex, $scoutYearId]);

        return array_map(
            static fn(array $row): HouseholdMember => new HouseholdMember(
                (int) $row['member_year_id'],
                (int) $row['member_id'],
                (bool) $row['leaving']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * The active member_years of a scout year whose every address row
     * lacks a usable blind index — the people no household statement can
     * be made about at all. They are neither compliant nor in breach, and
     * a caller that silently drops them reports a coverage it does not
     * have.
     *
     * @return int[] member_year ids
     */
    public function findMemberYearIdsWithoutUsableAddress(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.id
             FROM member_years my
             WHERE my.scout_year_id = ?
               AND my.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM member_addresses ma
                   WHERE ma.member_year_id = my.id
                     AND ma.address_normalized_blind_index IS NOT NULL
                     AND ma.address_normalized_blind_index <> \'\'
               )
             ORDER BY my.id'
        );
        $stmt->execute([$scoutYearId]);

        return array_map(static fn($id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
