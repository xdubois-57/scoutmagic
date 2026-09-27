<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * Batch data access behind SectionRosterService — one query for the whole
 * roster (however many sections/members), never one query per section or
 * per member.
 */
final class SectionRosterRepository
{
    public function __construct(
        private \PDO $pdo,
        private \Core\Security\EncryptionService $encryption
    ) {
    }

    /**
     * Identity and contact details for each member_year, **decrypted here**
     * (issue #551).
     *
     * `SectionRosterService` used to take {@see findMemberYearRows()}' raw
     * rows and decrypt six columns itself, which put six purpose strings in a
     * Service (SECURITY.md §5, ARCHITECTURE.md §13). They are spelled once,
     * here, beside the query that reads the columns they name.
     *
     * {@see findExportRecords()} does the same for the export, which needs
     * more columns than the roster shows; both reuse one fetch rather than
     * adding a second SELECT over the same table.
     *
     * @param int[] $memberYearIds
     * @return array<int, \Core\Member\RosterContact> keyed by member_year id
     */
    public function findRosterContacts(array $memberYearIds): array
    {
        $contacts = [];
        foreach ($this->findMemberYearRows($memberYearIds) as $memberYearId => $row) {
            $contacts[$memberYearId] = new \Core\Member\RosterContact(
                memberYearId: $memberYearId,
                firstName: $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
                lastName: $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
                totem: empty($row['totem_encrypted'])
                    ? null
                    : $this->encryption->decrypt($row['totem_encrypted'], 'member_years.totem'),
                email: empty($row['email_encrypted'])
                    ? null
                    : $this->encryption->decrypt($row['email_encrypted'], 'member_years.email'),
                phone: empty($row['phone_encrypted'])
                    ? null
                    : $this->encryption->decrypt($row['phone_encrypted'], 'member_years.phone'),
                mobile: empty($row['mobile_encrypted'])
                    ? null
                    : $this->encryption->decrypt($row['mobile_encrypted'], 'member_years.mobile')
            );
        }

        return $contacts;
    }

    /**
     * Everything the member export shows about each member_year, **decrypted
     * here** (issue #629).
     *
     * {@see \Core\Member\Export\MemberExportRowBuilder} used to take the raw
     * `member_years` and `member_addresses` rows and decrypt seventeen columns
     * itself. A purpose string is the one part of an encrypted read that must
     * never be written from memory by a second layer; they are spelled here,
     * beside the queries that read the columns they name.
     *
     * The address is the member_year's first one (lowest id), as the export
     * has always shown it.
     *
     * @param int[] $memberYearIds
     * @return array<int, \Core\Member\Export\MemberExportRecord> keyed by member_year id
     */
    public function findExportRecords(array $memberYearIds): array
    {
        $addresses = $this->findAddressRows($memberYearIds);
        $records = [];

        foreach ($this->findMemberYearRows($memberYearIds) as $memberYearId => $row) {
            $address = $addresses[$memberYearId][0] ?? [];
            $records[$memberYearId] = new \Core\Member\Export\MemberExportRecord(
                memberYearId: $memberYearId,
                memberId: (int) $row['member_id'],
                deskId: (string) $row['desk_id'],
                firstName: $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name'),
                lastName: $this->encryption->decrypt($row['last_name_encrypted'], 'member_years.last_name'),
                totem: $this->decryptOptional($row, 'totem_encrypted', 'member_years.totem'),
                quali: $this->decryptOptional($row, 'quali_encrypted', 'member_years.quali'),
                gender: $this->decryptOptional($row, 'gender_encrypted', 'member_years.gender'),
                birthDate: $this->decryptOptional($row, 'birth_date_encrypted', 'member_years.birth_date'),
                email: $this->decryptOptional($row, 'email_encrypted', 'member_years.email'),
                phone: $this->decryptOptional($row, 'phone_encrypted', 'member_years.phone'),
                mobile: $this->decryptOptional($row, 'mobile_encrypted', 'member_years.mobile'),
                street: $this->decryptOptional($address, 'street_encrypted', 'member_addresses.street'),
                number: $this->decryptOptional($address, 'number_encrypted', 'member_addresses.number'),
                box: $this->decryptOptional($address, 'box_encrypted', 'member_addresses.box'),
                postalCode: $this->decryptOptional($address, 'postal_code_encrypted', 'member_addresses.postal_code'),
                city: $this->decryptOptional($address, 'city_encrypted', 'member_addresses.city'),
                country: $this->decryptOptional($address, 'country_encrypted', 'member_addresses.country'),
                isActive: (bool) $row['is_active'],
                scoutYearOffset: (int) $row['scout_year_offset'],
                formationLevel: $row['formation_level'] !== null ? (string) $row['formation_level'] : null,
                supplementaryInsurance: $row['supplementary_insurance'] !== null
                    ? (string) $row['supplementary_insurance']
                    : null,
                leaving: (bool) $row['leaving'],
                leavingComment: $this->decryptOptional(
                    $row,
                    'leaving_comment_encrypted',
                    'member_years.leaving_comment'
                ),
                handicap: $this->decryptOptional($row, 'handicap_encrypted', 'member_years.handicap')
            );
        }

        return $records;
    }

    /**
     * @param int[] $sectionIds
     * @return SectionRosterEntry[] one per (section, member_year) —
     *         deduplicated when a member has several functions in the same
     *         section (main function preferred, then lowest
     *         member_functions id, same tie-break convention used
     *         throughout Core\Member/the registration module)
     */
    public function findRosterEntries(array $sectionIds, int $scoutYearId): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT mf.section_id, s.age_branch_id, mf.member_year_id, my.member_id,
                    f.role AS function_role, f.label AS function_label,
                    mf.is_main_function, mf.id AS mf_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN sections s ON mf.section_id = s.id
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.section_id IN ($placeholders) AND my.scout_year_id = ? AND my.is_active = 1
             ORDER BY mf.section_id, mf.member_year_id, mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute([...$sectionIds, $scoutYearId]);

        $entries = [];
        $seen = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sectionId = (int) $row['section_id'];
            $memberYearId = (int) $row['member_year_id'];
            $key = $sectionId . ':' . $memberYearId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entries[] = new SectionRosterEntry(
                sectionId: $sectionId,
                ageBranchId: (int) $row['age_branch_id'],
                memberYearId: $memberYearId,
                memberId: (int) $row['member_id'],
                bucket: SectionRosterEntry::bucketForRole((string) $row['function_role']),
                functionLabel: (string) $row['function_label'],
                isMainFunction: (bool) $row['is_main_function']
            );
        }

        return $entries;
    }

    /**
     * The member-search export's selection: one winning entry per
     * member_year (main function preferred, then lowest member_functions
     * id — same tie-break as findRosterEntries(), which resolves per
     * (section, member_year) instead). Deliberately NOT filtered on
     * my.is_active: the admin search page lists inactive members too
     * ("non inscrit" badge) and its export must not silently drop them.
     * A member_year with no function at all yields no entry here — the
     * caller (Export\MemberExportRowBuilder::buildForMemberYears())
     * synthesizes a sectionless one.
     *
     * @param int[] $memberYearIds
     * @return SectionRosterEntry[]
     */
    public function findEntriesByMemberYears(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT mf.section_id, s.age_branch_id, mf.member_year_id, my.member_id,
                    f.role AS function_role, f.label AS function_label,
                    mf.is_main_function, mf.id AS mf_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN sections s ON mf.section_id = s.id
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.member_year_id IN ($placeholders)
             ORDER BY mf.member_year_id, mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute($memberYearIds);

        $entries = [];
        $seen = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearId = (int) $row['member_year_id'];
            if (isset($seen[$memberYearId])) {
                continue;
            }
            $seen[$memberYearId] = true;

            $entries[] = new SectionRosterEntry(
                sectionId: (int) $row['section_id'],
                ageBranchId: (int) $row['age_branch_id'],
                memberYearId: $memberYearId,
                memberId: (int) $row['member_id'],
                bucket: SectionRosterEntry::bucketForRole((string) $row['function_role']),
                functionLabel: (string) $row['function_label'],
                isMainFunction: (bool) $row['is_main_function']
            );
        }

        return $entries;
    }

    /**
     * Base (undecrypted) member_years + members.desk_id rows for a batch of
     * member_year ids — one query. Private since issue #629: nothing outside
     * this class decrypts them any more, so nothing outside needs them.
     *
     * @param int[] $memberYearIds
     * @return array<int, array<string, mixed>> keyed by member_years.id
     */
    private function findMemberYearRows(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT my.*, m.desk_id
             FROM member_years my
             JOIN members m ON my.member_id = m.id
             WHERE my.id IN ($placeholders)"
        );
        $stmt->execute($memberYearIds);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Every function label held by each member_year, main function first —
     * unlike findRosterEntries() (which resolves ONE winning
     * section/bucket per member), this keeps the full list, for the
     * export's "Fonctions" column.
     *
     * @param int[] $memberYearIds
     * @return array<int, string[]> keyed by member_year_id
     */
    public function findAllFunctionLabels(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT mf.member_year_id, f.label AS function_label
             FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.member_year_id IN ($placeholders)
             ORDER BY mf.member_year_id, mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute($memberYearIds);

        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $labels[(int) $row['member_year_id']][] = (string) $row['function_label'];
        }
        return $labels;
    }

    /**
     * Every address for a batch of member_year ids — one query. Kept
     * separate from findMemberYearRows() since only the export (not the
     * on-screen roster table) needs it.
     *
     * @param int[] $memberYearIds
     * @return array<int, array<string, mixed>[]> keyed by member_year_id
     */
    private function findAddressRows(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM member_addresses WHERE member_year_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($memberYearIds);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['member_year_id']][] = $row;
        }

        return $rows;
    }

    /**
     * The decrypted column, or null when the row has no value for it — an
     * empty string counts as none, as it always has for these columns.
     *
     * @param array<string, mixed> $row
     */
    private function decryptOptional(array $row, string $column, string $purpose): ?string
    {
        return empty($row[$column]) ? null : $this->encryption->decrypt((string) $row[$column], $purpose);
    }
}
