<?php

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
     * @return MemberSearchResult[]
     */
    public function findAllForYear(int $scoutYearId): array
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
        $addresses = $this->loadAddresses($ids);

        $results = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $fn = $functions[$id] ?? null;
            $results[] = new MemberSearchResult(
                memberYearId: $id,
                firstName: $this->decrypt($row['first_name_encrypted']),
                lastName: $this->decrypt($row['last_name_encrypted']),
                totem: $this->decryptNullable($row['totem_encrypted']),
                email: $this->decryptNullable($row['email_encrypted']),
                phone: $this->decryptNullable($row['phone_encrypted']),
                mobile: $this->decryptNullable($row['mobile_encrypted']),
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
                street: $this->decryptNullable($r['street_encrypted']),
                number: $this->decryptNullable($r['number_encrypted']),
                box: $this->decryptNullable($r['box_encrypted']),
                complement: $this->decryptNullable($r['complement_encrypted']),
                postalCode: $this->decryptNullable($r['postal_code_encrypted']),
                city: $this->decryptNullable($r['city_encrypted']),
                country: $this->decryptNullable($r['country_encrypted']),
            );
            $map[$myId] = $address->format();
        }

        return $map;
    }

    private function decrypt(mixed $value): string
    {
        return $value ? $this->encryption->decrypt($value) : '';
    }

    private function decryptNullable(mixed $value): ?string
    {
        return $value ? $this->encryption->decrypt($value) : null;
    }
}
