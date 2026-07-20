<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * "Staff d'U" (chef d'unité staff) is a real, generic section like any
 * other — not a virtual/derived query — so it appears everywhere a section
 * does (section picker, trombinoscope, calendar) with no special-casing.
 *
 * A member's role is only known once an admin confirms the function's role
 * on Config Desk (never at raw Desk CSV import time — new functions always
 * import as role 'identified'), so membership must be (re)synced both at
 * the end of a Desk import and whenever a function's role changes.
 */
class UnitStaffSectionService
{
    public const DESK_CODE = 'STAFFDU';
    private const BRANCH_LABEL = "Staff d'U";

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Idempotently ensure the "Staff d'U" age branch and section exist, and
     * force the section active. It must survive
     * ImportSectionRepository::deactivateAll() even though nothing in a
     * Desk CSV row ever references it directly — membership comes only
     * from syncMembership(), never from a "Section" column value.
     */
    public function ensureSection(): int
    {
        $branchId = $this->ensureBranch();

        $stmt = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $stmt->execute([self::DESK_CODE]);
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)'
            );
            $stmt->execute([self::DESK_CODE, $branchId, self::BRANCH_LABEL]);
            return (int) $this->pdo->lastInsertId();
        }

        $sectionId = (int) $existingId;
        $stmt = $this->pdo->prepare('UPDATE sections SET is_active = 1 WHERE id = ?');
        $stmt->execute([$sectionId]);

        return $sectionId;
    }

    private function ensureBranch(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute([self::DESK_CODE]);
        $branchId = $stmt->fetchColumn();
        if ($branchId !== false) {
            return (int) $branchId;
        }

        // sort_order 50 is the canonical "Staff d'U" slot, between Pionniers
        // (40) and Route (60) — see AgeBranchRepository::canonicalSortOrder().
        $stmt = $this->pdo->prepare(
            'INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 50)'
        );
        $stmt->execute([self::DESK_CODE, self::BRANCH_LABEL]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Assign every member holding a chef d'unité (role = 'admin') function
     * with no section yet, in the given scout year, to the "Staff d'U"
     * section — and clear the assignment for anyone previously synced in
     * who no longer qualifies (role changed away from 'admin'). Portable
     * two-step select-then-update (no multi-table UPDATE...JOIN) so this
     * also runs against the SQLite test database.
     */
    public function syncMembership(int $scoutYearId): void
    {
        $staffduId = $this->ensureSection();

        $toAssign = $this->findFunctionIds(
            'SELECT mf.id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE f.role = \'admin\' AND mf.section_id IS NULL
               AND my.scout_year_id = ? AND my.is_active = 1',
            [$scoutYearId]
        );
        $this->updateSectionId($toAssign, $staffduId);

        $toClear = $this->findFunctionIds(
            'SELECT mf.id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.section_id = ? AND f.role != \'admin\'
               AND my.scout_year_id = ? AND my.is_active = 1',
            [$staffduId, $scoutYearId]
        );
        $this->updateSectionId($toClear, null);
    }

    /**
     * @param array<int, mixed> $params
     * @return int[]
     */
    private function findFunctionIds(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $memberFunctionIds
     */
    private function updateSectionId(array $memberFunctionIds, ?int $sectionId): void
    {
        if (count($memberFunctionIds) === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($memberFunctionIds), '?'));
        $stmt = $this->pdo->prepare("UPDATE member_functions SET section_id = ? WHERE id IN ({$placeholders})");
        $stmt->execute([$sectionId, ...$memberFunctionIds]);
    }
}
