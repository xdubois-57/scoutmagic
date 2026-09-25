<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Repository;

use Core\Database\Connection;

/**
 * The `sections` table, and who belongs to a section.
 *
 * Fifteen prepared statements used to live in `Core\Member\SectionService`,
 * which is a Service and therefore, by `ARCHITECTURE.md` §13, not allowed a
 * `Connection` at all. Nothing was exploitable — everything was prepared,
 * no parameter concatenated — and that is what let it stand: the bill for a
 * layering violation is paid later, by whoever looks for this code where
 * the architecture says it lives.
 *
 * **What did NOT move, and why it matters here**: the role filters. « Who
 * is staff of this section » and « who is an animé of it » are the same
 * query with opposite role lists, and they are written out as two literal
 * statements rather than one with a filter built from a string. An
 * `intendant` function was once missing from the animé exclusion, and a
 * member tagged Intendant was counted as a child on the Départs page; a
 * filter assembled from a variable is how that returns without anybody
 * reading it.
 */
final class SectionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int,
     *     is_visible: bool, is_active: bool, color: ?string
     * }>
     */
    public function allWithBranches(bool $includeHidden): array
    {
        $sql = 'SELECT s.id, s.desk_code, s.name, s.email, s.age_branch_id, s.is_visible, s.is_active, s.color,
                       ab.label AS branch_name, ab.sort_order AS branch_sort_order
                FROM sections s
                JOIN age_branches ab ON s.age_branch_id = ab.id
                WHERE s.is_active = 1' . (!$includeHidden ? ' AND s.is_visible = 1' : '');
        $sql .= ' ORDER BY ab.sort_order, s.desk_code';

        $stmt = $this->connection->getPdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        $sections = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sections[] = [
                ...$this->section($row),
                'is_visible' => (bool) $row['is_visible'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $sections;
    }

    /**
     * Batch lookup by id, WITHOUT the is_active/is_visible filtering
     * allWithBranches() applies: a caller resolving a historical section
     * reference must still resolve it once that section has gone inactive
     * or been hidden (§8.8 — a section with no members is deactivated,
     * never deleted).
     *
     * @param  int[] $sectionIds
     * @return array<int, array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string
     * }> keyed by id
     */
    public function findByIds(array $sectionIds): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT s.id, s.desk_code, s.name, s.email, s.age_branch_id, s.color,
                    ab.label AS branch_name, ab.sort_order AS branch_sort_order
             FROM sections s
             JOIN age_branches ab ON s.age_branch_id = ab.id
             WHERE s.id IN ($placeholders)"
        );
        $stmt->execute($sectionIds);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = $this->section($row);
        }

        return $result;
    }

    /**
     * @return array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string
     * }|null
     */
    public function findById(int $sectionId): ?array
    {
        return $this->oneBy('s.id = ?', $sectionId);
    }

    /**
     * Resolve a section from its Desk code — the only identifier
     * `MemberFunctionInfo` carries, so this is the entry point for a caller
     * that has nothing but a profile's function list to start from.
     *
     * @return array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string
     * }|null
     */
    public function findByDeskCode(string $deskCode): ?array
    {
        return $this->oneBy('s.desk_code = ?', $deskCode);
    }

    public function hasFunctionInSection(int $memberYearId, string $sectionDeskCode): bool
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM member_functions mf
             JOIN sections s ON mf.section_id = s.id
             WHERE mf.member_year_id = ? AND s.desk_code = ?
             LIMIT 1'
        );
        $stmt->execute([$memberYearId, $sectionDeskCode]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The member_year ids of one section's staff, or of its animés.
     *
     * @return list<int>
     */
    public function memberYearIdsInSection(int $sectionId, int $scoutYearId, bool $staff): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            $staff
                ? 'SELECT DISTINCT mf.member_year_id
                     FROM member_functions mf
                     JOIN member_years my ON mf.member_year_id = my.id
                     JOIN functions f ON mf.function_id = f.id
                    WHERE mf.section_id = ? AND my.scout_year_id = ? AND my.is_active = 1
                      AND f.role IN (\'chief\', \'admin\')'
                : 'SELECT DISTINCT mf.member_year_id
                     FROM member_functions mf
                     JOIN member_years my ON mf.member_year_id = my.id
                     JOIN functions f ON mf.function_id = f.id
                    WHERE mf.section_id = ? AND my.scout_year_id = ? AND my.is_active = 1
                      AND f.role NOT IN (\'chief\', \'admin\', \'intendant\')'
        );
        $stmt->execute([$sectionId, $scoutYearId]);

        return array_map(fn(array $row) => (int) $row['member_year_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The same question for many sections at once, keeping which section
     * each answer came from.
     *
     * @param  list<int> $sectionIds
     * @return list<array{section_id: int, member_year_id: int}>
     */
    public function memberYearIdsInSections(array $sectionIds, int $scoutYearId, bool $staff): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($sectionIds), '?'));
        $sql = $staff
            ? "SELECT DISTINCT mf.section_id, mf.member_year_id
                 FROM member_functions mf
                 JOIN member_years my ON mf.member_year_id = my.id
                 JOIN functions f ON mf.function_id = f.id
                WHERE mf.section_id IN ({$placeholders}) AND my.scout_year_id = ? AND my.is_active = 1
                  AND f.role IN ('chief', 'admin')"
            : "SELECT DISTINCT mf.section_id, mf.member_year_id
                 FROM member_functions mf
                 JOIN member_years my ON mf.member_year_id = my.id
                 JOIN functions f ON mf.function_id = f.id
                WHERE mf.section_id IN ({$placeholders}) AND my.scout_year_id = ? AND my.is_active = 1
                  AND f.role NOT IN ('chief', 'admin', 'intendant')";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute([...$sectionIds, $scoutYearId]);

        return array_map(
            static fn(array $row): array => [
                'section_id' => (int) $row['section_id'],
                'member_year_id' => (int) $row['member_year_id'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * The sections one member year ANIMATES — chief or admin on a real
     * section. An `intendant` function does not animate: the role exists to
     * reach the finances without being a chief, and carries no section
     * staff.
     *
     * @return list<int>
     */
    public function sectionIdsAnimatedBy(int $memberYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT mf.section_id
             FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.member_year_id = ?
               AND mf.section_id IS NOT NULL
               AND f.role IN (\'chief\', \'admin\')'
        );
        $stmt->execute([$memberYearId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * A section's animés in the PERSISTENT identity — `members.id` rather
     * than `member_years.id`, because `presences_records` is keyed on one
     * that must survive the September that saw it written (§4).
     *
     * @return list<int>
     */
    public function memberIdsOfAnimes(int $sectionId, int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT my.member_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.section_id = ? AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role NOT IN (\'chief\', \'admin\', \'intendant\')'
        );
        $stmt->execute([$sectionId, $scoutYearId]);

        return array_map(fn(array $row) => (int) $row['member_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function updateInfo(int $sectionId, ?string $name, ?string $email): void
    {
        $stmt = $this->connection->getPdo()->prepare('UPDATE sections SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $sectionId]);
    }

    public function updateVisibility(int $sectionId, bool $visible): void
    {
        $stmt = $this->connection->getPdo()->prepare('UPDATE sections SET is_visible = ? WHERE id = ?');
        $stmt->execute([$visible ? 1 : 0, $sectionId]);
    }

    public function updateColor(int $sectionId, ?string $color): void
    {
        $stmt = $this->connection->getPdo()->prepare('UPDATE sections SET color = ? WHERE id = ?');
        $stmt->execute([$color, $sectionId]);
    }

    /**
     * @return array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string
     * }|null
     */
    private function oneBy(string $where, string|int $value): ?array
    {
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT s.id, s.desk_code, s.name, s.email, s.age_branch_id, s.color,
                    ab.label AS branch_name, ab.sort_order AS branch_sort_order
             FROM sections s
             JOIN age_branches ab ON s.age_branch_id = ab.id
             WHERE {$where}"
        );
        $stmt->execute([$value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->section($row);
    }

    /**
     * One row's shape, written once.
     *
     * Four reads returned this same ten-line mapping, copied four times —
     * which is how `email` came to be absent from one of them for a while.
     *
     * @param  array<string, mixed> $row
     * @return array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string
     * }
     */
    private function section(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'age_branch_id' => (int) $row['age_branch_id'],
            'branch_name' => (string) $row['branch_name'],
            'branch_sort_order' => (int) $row['branch_sort_order'],
            'color' => $row['color'] !== null ? (string) $row['color'] : null,
        ];
    }
}
