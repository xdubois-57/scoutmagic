<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Security\EncryptionService;

/**
 * The members a Desk import produced for a scout year, as the registration
 * module links its requests to them: whom an accepted request is the same
 * child as (`Service\ReconciliationService`), and where an encoded one is
 * written to (`Service\ExternalMailingListService`).
 *
 * Every member of the year counts here — active rows only, any role — and
 * not just the animés `PassageRosterRepository` is about: a request is
 * reconciled against whatever Desk sent, not against a filtered roster.
 *
 * These statements used to live in the two services (issue #646).
 * ARCHITECTURE.md §13 keeps PDO in the Repository layer and SECURITY.md §5
 * keeps decryption there, so what the services receive is already the
 * plain value — or, for reconciliation, the blind index that value hashes
 * to — never a ciphertext.
 */
class ImportedMemberRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * The `registration_name_dob` blind index of every active member of
     * $scoutYearId — the same index `RegistrationRequestRepository::create()`
     * stores on a request, so the two compare with a plain equality. A
     * member with no birth date has no such index and is absent.
     *
     * The rows are decrypted here because they have to be: a freshly
     * imported member_year has no stored index of this kind to read. The
     * set is bounded by one year, never every historical one.
     *
     * @return array<int, array{member_id: int, name_dob_blind_index: string}> in the order the database reads them
     */
    public function findNameDobBlindIndexesForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted
             FROM member_years WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);

        $indexes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['birth_date_encrypted'] === null) {
                continue;
            }
            $normalized = RegistrationRequestRepository::normalizeForNameDobBlindIndex(
                $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
                $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
                $this->encryption->decrypt($row['birth_date_encrypted'], 'member_years.birth_date')
            );
            $indexes[] = [
                'member_id' => (int) $row['member_id'],
                'name_dob_blind_index' => $this->encryption->blindIndex($normalized, 'registration_name_dob'),
            ];
        }

        return $indexes;
    }

    /**
     * The Desk address of each of $memberIds on their active $scoutYearId
     * row, decrypted as stored — null when Desk has none, never trimmed or
     * dropped: what to do with a member nobody can write to is the
     * caller's decision. A member with no active row that year is absent.
     *
     * @param array<int, int> $memberIds
     * @return array<int, array{member_id: int, email: ?string}>
     */
    public function findEmailsForYear(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values($memberIds);
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, email_encrypted FROM member_years
             WHERE member_id IN ({$placeholders}) AND scout_year_id = ? AND is_active = 1"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $members = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $members[] = [
                'member_id' => (int) $row['member_id'],
                'email' => $row['email_encrypted'] !== null
                    ? $this->encryption->decrypt($row['email_encrypted'], 'member_years.email')
                    : null,
            ];
        }

        return $members;
    }
}
