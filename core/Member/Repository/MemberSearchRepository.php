<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Repository;

use Core\Database\Connection;
use Core\Member\MemberAddress;
use Core\Member\Service\MemberSearchResult;
use Core\Security\EncryptionService;

/**
 * Loads all members for a scout year, decrypting personal data in PHP so it can
 * be filtered in memory (encrypted columns cannot be searched in SQL).
 *
 * Uses a fixed number of queries (member_years + functions + addresses) rather
 * than one per member. Member counts are small (typically < 500 per year).
 */
class MemberSearchRepository
{
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Every member of one scout year, decrypted.
     *
     * **No `is_active` filter, deliberately.** A row deactivated mid-year
     * is still a member of that year and still someone a chef d'unité
     * searches for; the flag rides along on each result and the caller
     * decides. The search page defaults to the active ones, which is what
     * is wanted nine times in ten, and offers the other two scopes.
     *
     * @return MemberSearchResult[]
     */
    public function findAllForYear(
        int $scoutYearId,
        string $scoutYearLabel = '',
        string $scoutYearStartDate = '',
        /**
         * The address is seven more decryptions per row and the one field
         * a search rarely lands on: the service asks for it only for the
         * rows that matched (findAddressTexts()), and for everybody only
         * when nothing else matched.
         */
        bool $withAddresses = true
    ): array
    {
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare('SELECT * FROM member_years WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $functions = $this->loadMainFunctions($ids);
        $addresses = $withAddresses ? $this->loadAddresses($ids) : [];

        $results = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $fn = $functions[$id] ?? null;
            $results[] = new MemberSearchResult(
                memberYearId: $id,
                memberId: (int) $row['member_id'],
                scoutYearId: $scoutYearId,
                scoutYearLabel: $scoutYearLabel,
                scoutYearStartDate: $scoutYearStartDate,
                firstName: $this->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
                lastName: $this->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
                totem: $this->decryptNullable($row['totem_encrypted'], 'member_years.totem'),
                email: $this->decryptNullable($row['email_encrypted'], 'member_years.email'),
                phone: $this->decryptNullable($row['phone_encrypted'], 'member_years.phone'),
                mobile: $this->decryptNullable($row['mobile_encrypted'], 'member_years.mobile'),
                sectionName: $fn !== null ? $fn['section'] : null,
                functionLabel: $fn !== null ? $fn['label'] : null,
                addressText: $addresses[$id] ?? null,
                isActive: (bool) $row['is_active'],
            );
        }

        return $results;
    }

    /**
     * @param int[] $memberYearIds
     * @return array<int, array{label: string, section: string|null}>
     */
    private function loadMainFunctions(array $memberYearIds): array
    {
        $pdo = $this->connection->getPdo();
        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));

        $stmt = $pdo->prepare(
            'SELECT mf.member_year_id, f.label AS function_label,
                    s.name AS section_name, s.desk_code AS section_code, mf.is_main_function
             FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN sections s ON mf.section_id = s.id
             WHERE mf.member_year_id IN (' . $placeholders . ')
             ORDER BY mf.is_main_function DESC'
        );
        $stmt->execute($memberYearIds);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $myId = (int) $r['member_year_id'];
            // First row wins (main function ordered first).
            if (isset($map[$myId])) {
                continue;
            }
            $map[$myId] = [
                'label' => (string) $r['function_label'],
                'section' => $r['section_name'] ?? $r['section_code'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * The formatted first address of each given member year — what a
     * search result shows, and what an address search matches on.
     *
     * @param array<int, int> $memberYearIds
     * @return array<int, string> keyed by member_year_id; absent when there is no address
     */
    public function findAddressTexts(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));

        return $memberYearIds === [] ? [] : $this->loadAddresses($memberYearIds);
    }

    /**
     * @param int[] $memberYearIds
     * @return array<int, string>
     */
    private function loadAddresses(array $memberYearIds): array
    {
        $pdo = $this->connection->getPdo();
        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));

        $stmt = $pdo->prepare(
            'SELECT * FROM member_addresses WHERE member_year_id IN (' . $placeholders . ')'
        );
        $stmt->execute($memberYearIds);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $myId = (int) $r['member_year_id'];
            if (isset($map[$myId])) {
                continue; // first address only
            }
            $address = new MemberAddress(
                type: (string) $r['address_type'],
                street: $this->decryptNullable($r['street_encrypted'], 'member_addresses.street'),
                number: $this->decryptNullable($r['number_encrypted'], 'member_addresses.number'),
                box: $this->decryptNullable($r['box_encrypted'], 'member_addresses.box'),
                complement: $this->decryptNullable($r['complement_encrypted'], 'member_addresses.complement'),
                postalCode: $this->decryptNullable($r['postal_code_encrypted'], 'member_addresses.postal_code'),
                city: $this->decryptNullable($r['city_encrypted'], 'member_addresses.city'),
                country: $this->decryptNullable($r['country_encrypted'], 'member_addresses.country'),
            );
            $map[$myId] = $address->format();
        }

        return $map;
    }

    private function decrypt(mixed $value, string $context): string
    {
        return $value ? $this->encryption->decrypt($value, $context) : '';
    }

    private function decryptNullable(mixed $value, string $context): ?string
    {
        return $value ? $this->encryption->decrypt($value, $context) : null;
    }
}
