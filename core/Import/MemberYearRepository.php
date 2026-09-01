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
     * member_id => member_years.id for one scout year — the batched
     * counterpart of findByMemberAndYear() above, and like it deliberately
     * NOT filtered on is_active (a tracking page naming who an email went
     * to must keep naming a member who has since been deactivated).
     * idx_member_year (member_id, scout_year_id) guarantees at most one
     * row per pair.
     *
     * @param int[] $memberIds
     * @return array<int, int>
     */
    public function findIdsByMembersAndYear(array $memberIds, int $scoutYearId): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, member_id FROM member_years WHERE member_id IN ({$placeholders}) AND scout_year_id = ?"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ids[(int) $row['member_id']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * The member's own (Desk-imported) email blind index, from their most
     * recent scout year that actually has one — "which user_accounts row
     * does this member's primary address belong to", for the notification
     * senders that start from a member and need the account behind them
     * (Modules\Groups\Service\GroupRecipientResolver, Modules\Rental\
     * Service\RentalReminderService). Not scout-year-gated on purpose:
     * unlike login authorization (Core\Security\RoleResolver::
     * isEmailAuthorizedToLogin(), which correctly requires a CURRENT-year
     * row), this only needs to find whichever user_accounts row Core\
     * Import\DeskImportService::ensureUserAccount() already created for
     * this member.
     *
     * NOT a login resolution point: a magic link requested from a member's
     * secondary address is never attached to the account behind their
     * primary one (Core\Security\AuthService gives that address its own
     * account instead) — doing so would have let any confirmed secondary
     * address log in AS the member's primary account.
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
     * The members reachable from each of these email blind indexes, for
     * one scout year — the batched twin of findAllByEmail(), returning
     * ids rather than rows because its callers only need to know WHICH
     * members an account carries (their names come from
     * Core\Member\MemberService::findDisplayNamesByMemberIds(), which
     * batches too).
     *
     * is_active = 1, same as findAllByEmail(): an account's memberships
     * are the ones that currently exist, and a deactivated row is not one.
     *
     * @param string[] $emailBlindIndexes
     * @return array<string, int[]> blind index => member ids
     */
    public function findMemberIdsByEmailBlindIndexes(array $emailBlindIndexes, int $scoutYearId): array
    {
        $emailBlindIndexes = array_values(array_unique(array_filter(
            $emailBlindIndexes,
            static fn(string $index): bool => $index !== ''
        )));
        if ($emailBlindIndexes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emailBlindIndexes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT email_blind_index, member_id FROM member_years
             WHERE email_blind_index IN ({$placeholders}) AND scout_year_id = ? AND is_active = 1
             ORDER BY member_id"
        );
        $stmt->execute([...$emailBlindIndexes, $scoutYearId]);

        $byIndex = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byIndex[(string) $row['email_blind_index']][] = (int) $row['member_id'];
        }

        return $byIndex;
    }

    /**
     * The most recent email blind index of each of these members — the
     * batched twin of findMostRecentEmailBlindIndexForMember(), for a
     * caller holding a whole page of members rather than one.
     *
     * "Most recent" means the same thing it does there: the address on the
     * member's newest scout year that has one, which is what makes a
     * member who changed address still resolve to their current account.
     *
     * @param int[] $memberIds
     * @return array<int, string> member id => email blind index
     */
    public function findMostRecentEmailBlindIndexesForMembers(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        // Ordered oldest year first so the loop below keeps overwriting
        // with newer ones: one pass, no window function, and the same
        // answer per member as the singular query's own ORDER BY … LIMIT 1.
        $stmt = $this->pdo->prepare(
            "SELECT my.member_id, my.email_blind_index
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id IN ({$placeholders}) AND my.email_blind_index IS NOT NULL
             ORDER BY sy.start_date ASC"
        );
        $stmt->execute($memberIds);

        $indexes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $indexes[(int) $row['member_id']] = (string) $row['email_blind_index'];
        }

        return $indexes;
    }

    /**
     * The most recent encrypted name of each of these members — the same
     * shape as findMostRecentEmailBlindIndexesForMembers() above, and for
     * the same kind of caller: a page holding a list of member ids and
     * needing to print who they are.
     *
     * "Most recent" is the newest scout year the member has a row for, so
     * somebody who has left the unit is still named — which is the whole
     * point here, since the ids come from records that outlive a year.
     *
     * Returns the CIPHERTEXT: this class has no encryption service, and
     * Core\Member\MemberService is where a name gets decrypted.
     *
     * @param int[] $memberIds
     * @return array<int, array{first_name_encrypted: ?string, last_name_encrypted: ?string}>
     */
    public function findMostRecentNamesForMembers(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        // Oldest year first so the loop keeps overwriting with newer ones:
        // one pass, no window function, and the same answer per member as
        // an ORDER BY … LIMIT 1 per id would give.
        $stmt = $this->pdo->prepare(
            "SELECT my.member_id, my.first_name_encrypted, my.last_name_encrypted
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id IN ({$placeholders})
             ORDER BY sy.start_date ASC"
        );
        $stmt->execute($memberIds);

        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['member_id']] = [
                'first_name_encrypted' => $row['first_name_encrypted'],
                'last_name_encrypted' => $row['last_name_encrypted'],
            ];
        }

        return $names;
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
     * The whole active roster of one scout year, reduced to the columns a
     * name picker actually reads — and to nothing else.
     *
     * Two queries for the entire roster, never one per member: the obvious
     * alternative (Core\Member\SectionService::getSectionStaff() plus
     * getSectionAnimes(), which Modules\Groups' invite search uses) issues
     * three queries per member for addresses, functions and badges, so a
     * unit of three hundred members costs roughly nine hundred queries per
     * keystroke of a search-as-you-type box.
     *
     * The main function's section and label are resolved in the second
     * query and folded in by member_year id; a member with several
     * functions and no main one simply has neither.
     *
     * @return array<int, array<string, mixed>> One row per active member,
     *         carrying the encrypted name columns plus `section_name` and
     *         `function_label`.
     */
    public function findActiveRosterForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.id, my.member_id, my.first_name_encrypted, my.last_name_encrypted,
                    my.totem_encrypted, my.birth_date_encrypted
             FROM member_years my
             WHERE my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$scoutYearId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $memberYearIds = array_map(static fn(array $row) => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT mf.member_year_id, mf.is_main_function, s.name AS section_name, f.label AS function_label
             FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN sections s ON mf.section_id = s.id
             WHERE mf.member_year_id IN ({$placeholders})
             ORDER BY mf.is_main_function ASC"
        );
        $stmt->execute($memberYearIds);

        // Ordered main-function-last so the loop keeps overwriting with it:
        // one pass, and the same answer as a per-member "main function or
        // nothing" query would give. Same technique as
        // findMostRecentEmailBlindIndexesForMembers() above.
        $functions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $functions[(int) $row['member_year_id']] = [
                'section_name' => $row['section_name'] !== null ? (string) $row['section_name'] : null,
                'function_label' => $row['function_label'] !== null ? (string) $row['function_label'] : null,
            ];
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['section_name'] = $functions[(int) $row['id']]['section_name'] ?? null;
            $rows[$index]['function_label'] = $functions[(int) $row['id']]['function_label'] ?? null;
        }

        return $rows;
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
     * Every `members.id` still active for a scout year after an import.
     *
     * The input to Core\Import\DeskImportListener: a module holding a
     * reference to a member id reconciles against this list, and anything
     * outside it is what "no longer on the roster" means. Returned as ids
     * rather than rows on purpose — a listener needs set membership, not
     * member data, and must not be handed personal data it has no use for.
     *
     * @return int[]
     */
    public function findActiveMemberIdsForYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT member_id FROM member_years WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
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
     * The member_year row of this member's MOST RECENT scout year — the
     * one the admin member page shows whoever a search found them
     * through.
     *
     * Someone looking up a former member wants their latest known
     * contact details, not the ones from the year the search matched: a
     * chef d'unité reading a 2019 row believes they are reading current
     * data and phones a number that has not worked in years. The page
     * says which year it is showing for the same reason.
     *
     * Ordered by the scout year's own start_date rather than by id: an
     * `ensureYear()` call can create a past year after a later one, so
     * the ids are not chronological.
     *
     * @return array<string, mixed>|null
     */
    public function findMostRecentForMember(int $memberId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT my.*, m.desk_id
             FROM member_years my
             JOIN members m ON my.member_id = m.id
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id = ?
             ORDER BY sy.start_date DESC
             LIMIT 1'
        );
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
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
