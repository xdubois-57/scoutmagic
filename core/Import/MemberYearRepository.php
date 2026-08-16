<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
                    fee_category_id = ?, unit_code = ?,
                    handicap_encrypted = ?, supplementary_insurance = ?, is_active = 1
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
                $encryptedData['handicap_encrypted'],
                $encryptedData['supplementary_insurance'],
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
                fee_category_id, unit_code,
                handicap_encrypted, supplementary_insurance, scout_year_offset, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $encryptedData['handicap_encrypted'],
            $encryptedData['supplementary_insurance'],
            $this->inheritedScoutYearOffset($memberId, $scoutYearId),
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * `scout_year_offset` is the one member_years column Desk knows nothing
     * about — it is ScoutMagic-local, set by a chief on the member page for
     * someone who skipped or repeated a year (Core\Member\MemberYearService
     * ::getEffectiveAge()). Because the import's INSERT above never carried
     * it, every new scout year silently reset it to the schema default 0,
     * and an advanced/held-back member's branch and year-in-branch quietly
     * became wrong the moment their new year was imported — visible on the
     * member page, in member_stats, in the registration module's capacity
     * projections, and most starkly on "Prévisions", where a member Desk
     * had placed in Éclaireurs was still ranked by a Louveteaux-era age.
     *
     * A brand-new row therefore inherits the offset from the member's most
     * recent EARLIER scout year (by start_date — never by row id or scout
     * year id, neither of which is guaranteed chronological once a past
     * year is back-filled). Deliberately only on INSERT: the UPDATE branch
     * above must never touch the column, so a chief's correction to an
     * existing row always survives a re-import of the same year.
     */
    private function inheritedScoutYearOffset(int $memberId, int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.scout_year_offset
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id = ?
               AND my.scout_year_id != ?
               AND sy.start_date < (SELECT start_date FROM scout_years WHERE id = ?)
             ORDER BY sy.start_date DESC
             LIMIT 1'
        );
        $stmt->execute([$memberId, $scoutYearId, $scoutYearId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : 0;
    }

    /**
     * @return array{id: int, member_id: int, scout_year_id: int, email_blind_index: ?string}|null
     */
    public function findByMemberAndYear(int $memberId, int $scoutYearId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_id, scout_year_id, email_blind_index FROM member_years WHERE member_id = ? AND scout_year_id = ?'
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
            'email_blind_index' => $row['email_blind_index'],
        ];
    }

    /**
     * The member's own (Desk-imported) email blind index, from their most
     * recent scout year that actually has one — Core\Security\AuthService's
     * resolution point for "which user_accounts row does this member's
     * primary address belong to", used when a magic link is requested via
     * a secondary address instead (Core\Member\MemberEmailRepository) so
     * the link can still be tied to a real account. Not scout-year-gated
     * on purpose: unlike login authorization (Core\Security\RoleResolver::
     * isEmailAuthorizedToLogin(), which correctly requires a CURRENT-year
     * row), this only needs to find whichever user_accounts row Core\
     * Import\DeskImportService::ensureUserAccount() already created for
     * this member — that check happens separately, later in the flow.
     */
    public function findMostRecentEmailBlindIndexForMember(int $memberId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.email_blind_index FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id = ? AND my.email_blind_index IS NOT NULL
             ORDER BY sy.start_date DESC LIMIT 1'
        );
        $stmt->execute([$memberId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    /**
     * Find all member_year rows for a given email blind index and scout year.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllByEmail(string $emailBlindIndex, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.*, m.desk_id
             FROM member_years my
             JOIN members m ON my.member_id = m.id
             WHERE my.email_blind_index = ? AND my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$emailBlindIndex, $scoutYearId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The active member_year rows for a set of persistent member ids, for
     * one scout year — same row shape as findAllByEmail() (my.*, m.desk_id,
     * is_active = 1 only). Core\Security\RoleResolver's extension point
     * for matching a member reachable only through a valid secondary
     * email (Core\Member\MemberEmailRepository::
     * findMemberIdsByValidBlindIndex()), which yields member ids rather
     * than a blind index directly matchable against this table's own
     * column.
     *
     * @param int[] $memberIds
     * @return array<int, array<string, mixed>>
     */
    public function findAllByMemberIds(array $memberIds, int $scoutYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT my.*, m.desk_id
             FROM member_years my
             JOIN members m ON my.member_id = m.id
             WHERE my.member_id IN ({$placeholders}) AND my.scout_year_id = ? AND my.is_active = 1"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
                city_encrypted, country_encrypted, address_normalized_blind_index
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                $addr['address_normalized_blind_index'] ?? null,
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

    /**
     * Mark all member_years for a given scout year as inactive.
     */
    public function deactivateAllForYear(int $scoutYearId): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_years SET is_active = 0 WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);
    }

    /**
     * Mark a specific member_year as active.
     */
    public function activate(int $memberYearId): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_years SET is_active = 1 WHERE id = ?');
        $stmt->execute([$memberYearId]);
    }

    /**
     * Count active members for a given scout year.
     */
    public function countActiveByYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_years WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count distinct sections that have at least one active member function
     * for a given scout year.
     */
    public function countConfiguredSectionsForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT mf.section_id)
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             WHERE my.scout_year_id = ? AND my.is_active = 1 AND mf.section_id IS NOT NULL'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Update a member year's scout year offset (-1, 0, or +1).
     */
    public function updateScoutYearOffset(int $memberYearId, int $offset): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_years SET scout_year_offset = ? WHERE id = ?');
        $stmt->execute([$offset, $memberYearId]);
    }

    /**
     * Find a member year by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.*, m.desk_id
             FROM member_years my
             JOIN members m ON my.member_id = m.id
             WHERE my.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
