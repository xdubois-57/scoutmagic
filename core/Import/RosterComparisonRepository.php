<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Member\UnitStaffSectionService;
use Core\Service\DateInput;

/**
 * The reads {@see RosterReplacementGuard} confronts a parsed CSV with,
 * before the import transaction opens.
 *
 * Every method here is scoped to ONE scout year, and that is the point
 * rather than an implementation detail: the first import of a season runs
 * against an empty roster, where nothing is "missing" and no barrier may
 * fire. A comparison that quietly widened to "all years" would refuse
 * every first import of September.
 *
 * "Present in the roster" always means `member_years.is_active = 1` — a
 * member already inactive before this import cannot be counted among the
 * people it deactivates, or every end-of-season import would trip the
 * barrier on the departures of the previous one.
 */
class RosterComparisonRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** How many members the year currently holds. */
    public function countActiveMembers(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_years WHERE scout_year_id = ? AND is_active = 1'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The Desk identifiers the year currently holds, as a set keyed by
     * desk_id — the CSV's own "Tiers" column is compared against this.
     *
     * @return array<string, true>
     */
    public function findActiveDeskIds(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.desk_id
             FROM member_years my
             JOIN members m ON m.id = my.member_id
             WHERE my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$scoutYearId]);

        $set = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $deskId) {
            $set[(string) $deskId] = true;
        }

        return $set;
    }

    /**
     * The Desk codes of the sections that currently have at least one
     * member this year, "Staff d'U" excluded.
     *
     * The exclusion is not cosmetic: no CSV row ever names that section
     * (`Core\Member\UnitStaffSectionService` derives it from confirmed
     * admin functions instead), so counting it would make every roster
     * look multi-section and every single-section file look like a
     * legitimate one-section unit.
     *
     * @return string[]
     */
    public function findActiveSectionCodes(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT s.desk_code
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             JOIN sections s ON s.id = mf.section_id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND s.desk_code != ?'
        );
        $stmt->execute([$scoutYearId, UnitStaffSectionService::DESK_CODE]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The Desk identifiers of the members who hold a chef d'unité
     * (role = 'admin') function this year — the Staff d'Unité, as
     * `Core\Security\RoleResolver` and `UnitStaffSectionService::
     * syncMembership()` both compute it.
     *
     * @return string[]
     */
    public function findAdminDeskIds(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT m.desk_id
             FROM member_functions mf
             JOIN member_years my ON my.id = mf.member_year_id
             JOIN members m ON m.id = my.member_id
             JOIN functions f ON f.id = mf.function_id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND f.role = ?'
        );
        $stmt->execute([$scoutYearId, 'admin']);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Role by Desk function code, for the codes a CSV names.
     *
     * A code absent from the map is a function this installation has
     * never seen: `MappingResolver::resolveFunction()` will create it at
     * role 'identified', so the caller must read an absence as the lowest
     * role and never as "unknown, assume the best".
     *
     * @param string[] $deskCodes
     * @return array<string, string>
     */
    public function findRolesByFunctionCode(array $deskCodes): array
    {
        $deskCodes = array_values(array_unique($deskCodes));
        if ($deskCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($deskCodes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT desk_code, role FROM functions WHERE desk_code IN ({$placeholders})"
        );
        $stmt->execute($deskCodes);

        $roles = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $roles[(string) $row['desk_code']] = (string) $row['role'];
        }

        return $roles;
    }

    /**
     * Whether this installation has at least one `is_super_admin`
     * account.
     *
     * That flag is the one administrative access no Desk import can
     * touch (SECURITY.md §3, `Core\Security\RoleResolver::resolve()`
     * consults it first), so its presence is what decides whether a
     * roster left without a single admin function is a recoverable
     * situation or a locked door.
     */
    public function hasSuperAdminAccount(): bool
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM user_accounts WHERE is_super_admin = 1');

        return $stmt !== false && (int) $stmt->fetchColumn() > 0;
    }

    /** Whether the account running the import carries `is_super_admin`. */
    public function isSuperAdminAccount(int $userAccountId): bool
    {
        $stmt = $this->pdo->prepare('SELECT is_super_admin FROM user_accounts WHERE id = ?');
        $stmt->execute([$userAccountId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (bool) $value;
    }

    /**
     * The Desk identifiers of the members the importing account is linked
     * to this year, through the Desk-imported address only.
     *
     * `RoleResolver` also resolves a login through a validated secondary
     * address (`member_emails`), which this deliberately does not: the
     * consequence built on top of this ("vous perdriez votre propre
     * accès") is a warning shown beside a refusal that already stands on
     * the counted signals, and a second query path here would only widen
     * the surface of a check nothing depends on.
     *
     * @return string[]
     */
    public function findDeskIdsForAccount(int $userAccountId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT m.desk_id
             FROM member_years my
             JOIN members m ON m.id = my.member_id
             JOIN user_accounts ua ON ua.email_blind_index = my.email_blind_index
             WHERE ua.id = ? AND my.scout_year_id = ? AND my.is_active = 1'
        );
        $stmt->execute([$userAccountId, $scoutYearId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** When the year's most recent import ran, or null when it never did. */
    public function findLastImportedAt(int $scoutYearId): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT imported_at FROM import_journal
             WHERE scout_year_id = ?
             ORDER BY imported_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : DateInput::fromStorage((string) $value);
    }
}
