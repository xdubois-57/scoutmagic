<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Repository;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Modules\Leadership\Value\StaffFunctionRow;

/**
 * Every read this module makes against core tables, and the only place it
 * decrypts anything (SECURITY.md §5).
 *
 * The module stores no member data of its own — it stores a vocabulary
 * mapping and nothing else — so everything the pages show is computed from
 * core's own tables on each request. That is affordable because the whole
 * unit is a few hundred rows; there is deliberately no cache, no
 * post-import recomputation and nothing to invalidate, which is also why
 * the module can never disagree with Desk.
 */
class LeadershipRepository
{
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption
    ) {
    }

    /**
     * One row per staff `member_functions` row of the year — see
     * StaffFunctionRow for why per function and not per person.
     *
     * @return list<StaffFunctionRow>
     */
    public function findStaffFunctions(int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT my.member_id,
                    my.id                       AS member_year_id,
                    mf.id                       AS member_function_id,
                    my.first_name_encrypted,
                    my.last_name_encrypted,
                    my.totem_encrypted,
                    my.birth_date_encrypted,
                    my.scout_year_offset,
                    my.formation_level,
                    f.label                     AS function_label,
                    f.role                      AS function_role,
                    mf.is_main_function,
                    mf.section_id,
                    s.name                      AS section_name,
                    s.desk_code                 AS section_desk_code,
                    mf.start_date
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN sections s ON mf.section_id = s.id
             WHERE my.scout_year_id = ?
               AND my.is_active = 1
               AND f.role IN (\'intendant\', \'chief\', \'admin\')
             ORDER BY my.id ASC, mf.is_main_function DESC, mf.id ASC'
        );
        $stmt->execute([$scoutYearId]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[] = new StaffFunctionRow(
                memberId: (int) $r['member_id'],
                memberYearId: (int) $r['member_year_id'],
                memberFunctionId: (int) $r['member_function_id'],
                firstName: (string) $this->decrypt($r['first_name_encrypted'], 'member_years.first_name'),
                lastName: (string) $this->decrypt($r['last_name_encrypted'], 'member_years.last_name'),
                totem: $this->decrypt($r['totem_encrypted'], 'member_years.totem'),
                birthDate: $this->decrypt($r['birth_date_encrypted'], 'member_years.birth_date'),
                scoutYearOffset: (int) $r['scout_year_offset'],
                formationLevel: $r['formation_level'] !== null ? (string) $r['formation_level'] : null,
                functionLabel: (string) $r['function_label'],
                functionRole: (string) $r['function_role'],
                isMainFunction: (bool) $r['is_main_function'],
                sectionId: $r['section_id'] !== null ? (int) $r['section_id'] : null,
                sectionName: $this->sectionLabel($r['section_name'], $r['section_desk_code']),
                functionStartDate: $r['start_date'] !== null ? (string) $r['start_date'] : null,
            );
        }

        return $rows;
    }

    /**
     * Headcount of animés per section id, for the year. The role filter is
     * the exact complement of the staff one above, and it is the *only*
     * thing separating a section's children from its leaders: both carry
     * the same `section_id` on their own `member_functions` row (the trap
     * Core\Member\SectionService::getSectionAnimes() documents at length —
     * an Intendant left out of the exclusion list once got counted as a
     * child on two other pages).
     *
     * Counted per distinct member, not per function row, so somebody with
     * two functions in one section is one child.
     *
     * @return array<int, int> section id → headcount
     */
    public function countAnimesBySection(int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT mf.section_id, COUNT(DISTINCT my.member_id) AS headcount
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ?
               AND my.is_active = 1
               AND mf.section_id IS NOT NULL
               AND f.role NOT IN (\'intendant\', \'chief\', \'admin\', \'superadmin\')
             GROUP BY mf.section_id'
        );
        $stmt->execute([$scoutYearId]);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $counts[(int) $r['section_id']] = (int) $r['headcount'];
        }

        return $counts;
    }

    /**
     * Persistent `members.id` values that held an animation function in the
     * given scout year.
     *
     * This is what answers "is this a first-year animateur?", asked of the
     * PREVIOUS year. It reads the persistent member identity on purpose:
     * `member_functions.start_date` is the start of that one function and
     * is reset the moment somebody changes section, so using it here would
     * file a chief of eight years standing who moved from Louveteaux to
     * Éclaireurs as a beginner.
     *
     * @return list<int>
     */
    public function findMemberIdsWithAnimationFunction(int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT my.member_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ?
               AND f.role IN (\'chief\', \'admin\')'
        );
        $stmt->execute([$scoutYearId]);

        return array_map(
            static fn (array $r): int => (int) $r['member_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * The scout year immediately before this one, by START DATE — never by
     * id, which stops being chronological the moment a past year is
     * back-filled (the same trap ARCHITECTURE.md §8.1 records for
     * `scout_year_offset` inheritance).
     */
    public function findPreviousScoutYearId(int $scoutYearId): ?int
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT prev.id
             FROM scout_years prev
             JOIN scout_years current ON current.id = ?
             WHERE prev.start_date < current.start_date
             ORDER BY prev.start_date DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Members holding a membership period in a section of the given branch,
     * for the year, with what is needed to place them in their branch-year.
     *
     * Section membership comes from `member_section_periods` rather than
     * from the year's function rows: that table is tied to the persistent
     * member and survives both a mid-year section change and the wholesale
     * overwrite every import does to `member_functions`.
     *
     * @return list<array{member_id: int, member_year_id: int, first_name: string, last_name: string, totem: ?string, birth_date: ?string, scout_year_offset: int, section_id: int, section_name: ?string}>
     */
    public function findMembersInBranchSections(int $scoutYearId, int $branchSortOrder, string $referenceDate): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT my.member_id,
                    my.id AS member_year_id,
                    my.first_name_encrypted,
                    my.last_name_encrypted,
                    my.totem_encrypted,
                    my.birth_date_encrypted,
                    my.scout_year_offset,
                    s.id  AS section_id,
                    s.name AS section_name,
                    s.desk_code AS section_desk_code
             FROM member_section_periods msp
             JOIN sections s ON msp.section_id = s.id
             JOIN age_branches ab ON s.age_branch_id = ab.id
             JOIN member_years my ON my.member_id = msp.member_id AND my.scout_year_id = msp.scout_year_id
             WHERE msp.scout_year_id = ?
               AND ab.sort_order = ?
               AND my.is_active = 1
               AND msp.start_date <= ?
               AND (msp.end_date IS NULL OR msp.end_date >= ?)
             ORDER BY my.member_id ASC'
        );
        $stmt->execute([$scoutYearId, $branchSortOrder, $referenceDate, $referenceDate]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'member_id' => (int) $r['member_id'],
                'member_year_id' => (int) $r['member_year_id'],
                'first_name' => (string) $this->decrypt($r['first_name_encrypted'], 'member_years.first_name'),
                'last_name' => (string) $this->decrypt($r['last_name_encrypted'], 'member_years.last_name'),
                'totem' => $this->decrypt($r['totem_encrypted'], 'member_years.totem'),
                'birth_date' => $this->decrypt($r['birth_date_encrypted'], 'member_years.birth_date'),
                'scout_year_offset' => (int) $r['scout_year_offset'],
                'section_id' => (int) $r['section_id'],
                'section_name' => $this->sectionLabel($r['section_name'], $r['section_desk_code']),
            ];
        }

        return $rows;
    }

    /**
     * Earliest recorded section period for a member in a scout year — the
     * only "first appearance" this site actually keeps, written by the Desk
     * import the first time it saw that member in that section.
     *
     * Used, and labelled as such on the page, when an intendance function
     * carries no `start_date`. It is NOT a Desk registration date and the
     * Stewards page never presents it as one:
     * `member_functions` is overwritten wholesale on every import
     * (MemberYearRepository::replaceFunctions()), so there is no per-import
     * history of functions to fall back on — this table is the nearest
     * real record, and where it too is empty the page shows no countdown
     * rather than an invented one.
     */
    /**
     * The same answer for a whole list of members, in one query.
     *
     * The stewards page called the single-member version once per line, so
     * a unit with a dozen intendants issued a dozen round trips to render
     * one table — and the number grows with the very list it is rendering.
     *
     * @param int[] $memberIds
     * @return array<int, string> members.id => earliest start date
     */
    public function findEarliestSectionPeriodStarts(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT member_id, MIN(start_date) AS earliest
               FROM member_section_periods
              WHERE member_id IN ({$placeholders}) AND scout_year_id = ?
              GROUP BY member_id"
        );
        $stmt->execute([...$memberIds, $scoutYearId]);

        $starts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['earliest'] !== null) {
                $starts[(int) $row['member_id']] = (string) $row['earliest'];
            }
        }

        return $starts;
    }

    public function findEarliestSectionPeriodStart(int $memberId, int $scoutYearId): ?string
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT MIN(start_date) FROM member_section_periods
             WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }

    /**
     * When the year's data was last imported from Desk — the validity date
     * every page in this module states, because nothing it shows is fresher
     * than that.
     */
    public function findLastImportAt(int $scoutYearId): ?string
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT MAX(imported_at) FROM import_journal WHERE scout_year_id = ?'
        );
        $stmt->execute([$scoutYearId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }

    /**
     * Distinct raw `formation_level` values in use by the year's staff,
     * with how many people carry each — the Formations page lists the
     * unrecognised ones from this.
     *
     * @return array<string, int> raw value → count
     */
    public function countFormationLevels(int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT my.formation_level, COUNT(DISTINCT my.member_id) AS holders
             FROM member_years my
             JOIN member_functions mf ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.scout_year_id = ?
               AND my.is_active = 1
               AND f.role IN (\'intendant\', \'chief\', \'admin\')
               AND my.formation_level IS NOT NULL
               AND my.formation_level <> \'\'
             GROUP BY my.formation_level'
        );
        $stmt->execute([$scoutYearId]);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $counts[(string) $r['formation_level']] = (int) $r['holders'];
        }

        return $counts;
    }

    /**
     * The raw formation level of one member for one scout year — what the
     * member's own card on their member page is built from.
     */
    public function findFormationLevelForMember(int $memberId, int $scoutYearId): ?string
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT formation_level FROM member_years
             WHERE member_id = ? AND scout_year_id = ? LIMIT 1'
        );
        $stmt->execute([$memberId, $scoutYearId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Section name for display: the configured name when a chief has set
     * one, the Desk code otherwise — never an empty cell, and never a
     * guessed label.
     */
    private function sectionLabel(mixed $name, mixed $deskCode): ?string
    {
        $name = is_string($name) && $name !== '' ? $name : null;
        if ($name !== null) {
            return $name;
        }

        return is_string($deskCode) && $deskCode !== '' ? $deskCode : null;
    }

    /**
     * A row whose ciphertext predates a key rotation, or was written by a
     * different installation, must not take a whole page down with it: the
     * field reads as absent, exactly as an empty column would, and the
     * pages already have to render "inconnu" for that case anyway.
     */
    private function decrypt(mixed $value, string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = $this->encryption->decrypt((string) $value, $context);
        } catch (\Throwable) {
            return null;
        }

        return $plain === '' ? null : $plain;
    }
}
