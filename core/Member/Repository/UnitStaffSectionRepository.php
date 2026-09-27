<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Repository;

/**
 * Every statement « Staff d'U » needs (issue #551).
 *
 * **Its own class rather than a corner of {@see SectionRepository}**, which
 * only ever reads: this one creates a section and an age branch, forces the
 * section active, and moves `member_functions.section_id` about. Folding
 * writes into a reader would make callers of the reader able to write by
 * accident, and #551's landing rule is explicit — what overlaps an existing
 * repository goes there, the rest earns a class.
 *
 * `\PDO` rather than `Core\Database\Connection` on purpose: its neighbours in
 * `core/Member/` (`SectionRosterRepository`, `FeeEstimationRepository`) take
 * `\PDO`, and migrating them is a different change from moving SQL out of a
 * Service. One reasoning per round (#551's own words about #413).
 */
class UnitStaffSectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function sectionIdByDeskCode(string $deskCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $stmt->execute([$deskCode]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function insertSection(string $deskCode, int $ageBranchId, string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$deskCode, $ageBranchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Forces the section active — it must survive
     * `ImportSectionRepository::deactivateAll()`, since no Desk CSV row ever
     * names it and membership comes from the sync alone.
     */
    public function activateSection(int $sectionId): void
    {
        $stmt = $this->pdo->prepare('UPDATE sections SET is_active = 1 WHERE id = ?');
        $stmt->execute([$sectionId]);
    }

    public function ageBranchIdByDeskCode(string $deskCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute([$deskCode]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function insertAgeBranch(string $deskCode, string $label, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)'
        );
        $stmt->execute([$deskCode, $label, $sortOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * `member_functions` rows that hold a chef d'unité function (role
     * `admin`) and no section yet, for an active member_year of this scout
     * year.
     *
     * @return int[]
     */
    public function functionIdsToAssign(int $scoutYearId): array
    {
        return $this->functionIds(
            "SELECT mf.id
               FROM member_functions mf
               JOIN member_years my ON mf.member_year_id = my.id
               JOIN functions f ON mf.function_id = f.id
              WHERE f.role = 'admin' AND mf.section_id IS NULL
                AND my.scout_year_id = ? AND my.is_active = 1",
            [$scoutYearId]
        );
    }

    /**
     * The other direction: rows sitting in the section whose function is no
     * longer `admin`, because a role was changed away from it.
     *
     * @return int[]
     */
    public function functionIdsToClear(int $sectionId, int $scoutYearId): array
    {
        return $this->functionIds(
            "SELECT mf.id
               FROM member_functions mf
               JOIN member_years my ON mf.member_year_id = my.id
               JOIN functions f ON mf.function_id = f.id
              WHERE mf.section_id = ? AND f.role != 'admin'
                AND my.scout_year_id = ? AND my.is_active = 1",
            [$sectionId, $scoutYearId]
        );
    }

    /**
     * Two-step select-then-update, never a multi-table `UPDATE … JOIN`, so
     * the same statement runs on the SQLite test database as on MySQL.
     *
     * @param int[] $memberFunctionIds
     */
    public function assignSection(array $memberFunctionIds, ?int $sectionId): void
    {
        if ($memberFunctionIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($memberFunctionIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE member_functions SET section_id = ? WHERE id IN ({$placeholders})"
        );
        $stmt->execute([$sectionId, ...$memberFunctionIds]);
    }

    /**
     * @param array<int, mixed> $params
     * @return int[]
     */
    private function functionIds(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
