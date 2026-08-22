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
    public function __construct(private \PDO $pdo)
    {
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
     * member_year ids — one query. Decryption stays the caller's
     * (Service-layer) job, same split as every other Core\Member class.
     *
     * @param int[] $memberYearIds
     * @return array<int, array<string, mixed>> keyed by member_years.id
     */
    public function findMemberYearRows(array $memberYearIds): array
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
    public function findAddressRows(array $memberYearIds): array
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
}
