<?php

declare(strict_types=1);

namespace Core\Import;

class MemberYearRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Upsert a member_year record. Returns the member_year ID.
     *
     * @param array<string, mixed> $encryptedData
     */
    public function upsert(int $memberId, int $scoutYearId, array $encryptedData): int
    {
        $existing = $this->findByMemberAndYear($memberId, $scoutYearId);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE member_years SET
                    first_name_encrypted = ?, last_name_encrypted = ?,
                    gender_encrypted = ?, birth_date_encrypted = ?,
                    phone_encrypted = ?, mobile_encrypted = ?,
                    email_encrypted = ?, email_blind_index = ?,
                    totem_encrypted = ?, quali_encrypted = ?,
                    patrol_encrypted = ?, formation_level = ?,
                    federation_mail_consent = ?, unit_mail_consent = ?,
                    fee_category_id = ?, unit_code = ?
                WHERE id = ?'
            );
            $stmt->execute([
                $encryptedData['first_name_encrypted'],
                $encryptedData['last_name_encrypted'],
                $encryptedData['gender_encrypted'],
                $encryptedData['birth_date_encrypted'],
                $encryptedData['phone_encrypted'],
                $encryptedData['mobile_encrypted'],
                $encryptedData['email_encrypted'],
                $encryptedData['email_blind_index'],
                $encryptedData['totem_encrypted'],
                $encryptedData['quali_encrypted'],
                $encryptedData['patrol_encrypted'],
                $encryptedData['formation_level'],
                $encryptedData['federation_mail_consent'] ? 1 : 0,
                $encryptedData['unit_mail_consent'] ? 1 : 0,
                $encryptedData['fee_category_id'],
                $encryptedData['unit_code'],
                $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (
                member_id, scout_year_id,
                first_name_encrypted, last_name_encrypted,
                gender_encrypted, birth_date_encrypted,
                phone_encrypted, mobile_encrypted,
                email_encrypted, email_blind_index,
                totem_encrypted, quali_encrypted,
                patrol_encrypted, formation_level,
                federation_mail_consent, unit_mail_consent,
                fee_category_id, unit_code, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $scoutYearId,
            $encryptedData['first_name_encrypted'],
            $encryptedData['last_name_encrypted'],
            $encryptedData['gender_encrypted'],
            $encryptedData['birth_date_encrypted'],
            $encryptedData['phone_encrypted'],
            $encryptedData['mobile_encrypted'],
            $encryptedData['email_encrypted'],
            $encryptedData['email_blind_index'],
            $encryptedData['totem_encrypted'],
            $encryptedData['quali_encrypted'],
            $encryptedData['patrol_encrypted'],
            $encryptedData['formation_level'],
            $encryptedData['federation_mail_consent'] ? 1 : 0,
            $encryptedData['unit_mail_consent'] ? 1 : 0,
            $encryptedData['fee_category_id'],
            $encryptedData['unit_code'],
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{id: int, member_id: int, scout_year_id: int}|null
     */
    public function findByMemberAndYear(int $memberId, int $scoutYearId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_id, scout_year_id FROM member_years WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'member_id' => (int) $row['member_id'],
            'scout_year_id' => (int) $row['scout_year_id'],
        ];
    }

    /**
     * Find all member_year IDs for a given email blind index and scout year.
     *
     * @return array<int, array{id: int, member_id: int}>
     */
    public function findAllByEmail(string $emailBlindIndex, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_id FROM member_years WHERE email_blind_index = ? AND scout_year_id = ?'
        );
        $stmt->execute([$emailBlindIndex, $scoutYearId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => [
            'id' => (int) $row['id'],
            'member_id' => (int) $row['member_id'],
        ], $rows);
    }

    /**
     * Replace all addresses for a member_year.
     *
     * @param array<int, array<string, mixed>> $addresses
     */
    public function replaceAddresses(int $memberYearId, array $addresses): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM member_addresses WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (
                member_year_id, address_type,
                street_encrypted, number_encrypted, box_encrypted,
                complement_encrypted, postal_code_encrypted,
                city_encrypted, country_encrypted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($addresses as $addr) {
            $stmt->execute([
                $memberYearId,
                $addr['address_type'],
                $addr['street_encrypted'],
                $addr['number_encrypted'],
                $addr['box_encrypted'],
                $addr['complement_encrypted'],
                $addr['postal_code_encrypted'],
                $addr['city_encrypted'],
                $addr['country_encrypted'],
            ]);
        }
    }

    /**
     * Replace all functions for a member_year.
     *
     * @param array<int, array<string, mixed>> $functions
     */
    public function replaceFunctions(int $memberYearId, array $functions): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM member_functions WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (
                member_year_id, function_id, section_id, age_branch_id,
                start_date, end_date, mandate_end, is_main_function
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($functions as $fn) {
            $stmt->execute([
                $memberYearId,
                $fn['function_id'],
                $fn['section_id'],
                $fn['age_branch_id'],
                $fn['start_date'],
                $fn['end_date'],
                $fn['mandate_end'],
                $fn['is_main_function'] ? 1 : 0,
            ]);
        }
    }
}
