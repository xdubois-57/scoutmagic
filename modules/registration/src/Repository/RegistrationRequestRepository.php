<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Security\EncryptionService;

/**
 * All identity data is encrypted at rest (SECURITY.md §5) — this is the
 * only class that ever calls EncryptionService::encrypt()/decrypt() for a
 * registration request. Blind indexes exist for the two exact-match
 * lookups the module spec calls for: email (tracking-page linkage,
 * duplicate signal) and the normalized name+birth-date triple (Desk
 * reconciliation, a later iteration) — never used to refuse a submission.
 */
class RegistrationRequestRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string
     * } $fields
     * @param array<int> $siblingMemberIds
     * @return array{id: int, tracking_token: string} the raw tracking token, never
     *         stored or logged anywhere else — the caller must hand it to the
     *         parent (tracking link) and then discard it.
     */
    public function create(
        int $scoutYearId,
        array $fields,
        ?int $desiredSectionId,
        array $siblingMemberIds
    ): array {
        $trackingToken = bin2hex(random_bytes(32));
        $trackingTokenHash = password_hash($trackingToken, PASSWORD_DEFAULT);

        $nameDobBlind = $this->encryption->blindIndex(self::normalizeForNameDobBlindIndex(
            $fields['child_last_name'],
            $fields['child_first_name'],
            $fields['birth_date']
        ));

        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_requests (
                scout_year_id, parent_name_encrypted, child_last_name_encrypted, child_first_name_encrypted,
                gender_encrypted, birth_date_encrypted, street_encrypted, number_encrypted,
                postal_code_encrypted, city_encrypted, email_encrypted, email_blind_index,
                phone1_encrypted, phone2_encrypted, remarks_encrypted, name_dob_blind_index,
                desired_section_id, status, tracking_token_hash
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $scoutYearId,
            $this->encryption->encrypt($fields['parent_name']),
            $this->encryption->encrypt($fields['child_last_name']),
            $this->encryption->encrypt($fields['child_first_name']),
            $this->encryption->encrypt($fields['gender']),
            $this->encryption->encrypt($fields['birth_date']),
            $this->encryption->encrypt($fields['street']),
            $this->encryption->encrypt($fields['number']),
            $this->encryption->encrypt($fields['postal_code']),
            $this->encryption->encrypt($fields['city']),
            $this->encryption->encrypt($fields['email']),
            $this->encryption->blindIndex(self::normalizeEmail($fields['email'])),
            $this->encryption->encrypt($fields['phone1']),
            $fields['phone2'] !== null ? $this->encryption->encrypt($fields['phone2']) : null,
            $fields['remarks'] !== null && $fields['remarks'] !== '' ? $this->encryption->encrypt($fields['remarks']) : null,
            $nameDobBlind,
            $desiredSectionId,
            RegistrationRequest::STATUS_PENDING,
            $trackingTokenHash,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($siblingMemberIds !== []) {
            $siblingStmt = $this->pdo->prepare(
                'INSERT INTO registration_request_siblings (registration_request_id, member_id) VALUES (?, ?)'
            );
            foreach (array_unique($siblingMemberIds) as $memberId) {
                $siblingStmt->execute([$id, $memberId]);
            }
        }

        return ['id' => $id, 'tracking_token' => $trackingToken];
    }

    public function findById(int $id): ?RegistrationRequest
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Verifies a presented tracking token against request $id's stored
     * hash — password_verify(), same technique as Core\Member\
     * MemberEmailService's confirmation tokens (SECURITY.md §5). Never
     * expires while the request exists, per the module spec.
     */
    public function verifyTrackingToken(int $id, string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT tracking_token_hash FROM registration_requests WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();

        return $hash !== false && password_verify($token, (string) $hash);
    }

    /**
     * Every request whose primary email blind-indexes to $email — the
     * tracking-page linkage lookup (Service\TrackingService). Blind index
     * on purpose: the alternative (decrypt every row to compare) doesn't
     * scale and isn't needed for an exact match.
     *
     * @return array<RegistrationRequest>
     */
    public function findAllByEmailBlindIndex(string $blindIndex): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_requests WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every pending request targeting $scoutYearId — Service\SlotService
     * buckets these into slots by decrypted birth date (never a stored
     * slot column: the slot is a derived concept, see schema.sql).
     *
     * @return array<RegistrationRequest>
     */
    public function findPendingForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_requests WHERE scout_year_id = ? AND status = 'pending'"
        );
        $stmt->execute([$scoutYearId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int> member ids declared as siblings on this request
     */
    public function findSiblingMemberIds(int $requestId): array
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM registration_request_siblings WHERE registration_request_id = ?');
        $stmt->execute([$requestId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Same normalization spirit as Core\Member\AddressNormalizer: a stable,
     * comparison-only form, never displayed. Word-level (not substring)
     * normalization isn't needed here — names/dates don't have the
     * "substring accidentally matches a stop word" problem addresses do.
     */
    public static function normalizeForNameDobBlindIndex(string $lastName, string $firstName, string $birthDate): string
    {
        $fold = static fn(string $s): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? $s));

        return $fold($lastName) . '|' . $fold($firstName) . '|' . trim($birthDate);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RegistrationRequest
    {
        return new RegistrationRequest(
            id: (int) $row['id'],
            scoutYearId: (int) $row['scout_year_id'],
            parentName: $this->encryption->decrypt($row['parent_name_encrypted']),
            childLastName: $this->encryption->decrypt($row['child_last_name_encrypted']),
            childFirstName: $this->encryption->decrypt($row['child_first_name_encrypted']),
            gender: $this->encryption->decrypt($row['gender_encrypted']),
            birthDate: $this->encryption->decrypt($row['birth_date_encrypted']),
            street: $this->encryption->decrypt($row['street_encrypted']),
            number: $this->encryption->decrypt($row['number_encrypted']),
            postalCode: $this->encryption->decrypt($row['postal_code_encrypted']),
            city: $this->encryption->decrypt($row['city_encrypted']),
            email: $this->encryption->decrypt($row['email_encrypted']),
            phone1: $this->encryption->decrypt($row['phone1_encrypted']),
            phone2: $row['phone2_encrypted'] !== null ? $this->encryption->decrypt($row['phone2_encrypted']) : null,
            remarks: $row['remarks_encrypted'] !== null ? $this->encryption->decrypt($row['remarks_encrypted']) : null,
            desiredSectionId: $row['desired_section_id'] !== null ? (int) $row['desired_section_id'] : null,
            status: (string) $row['status'],
            receivedAt: new \DateTimeImmutable((string) $row['received_at'])
        );
    }
}
