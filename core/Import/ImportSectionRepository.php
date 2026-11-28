<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class ImportSectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, desk_code: string, age_branch_id: int, name: ?string}|null
     */
    public function findByDeskCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sections WHERE desk_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'age_branch_id' => (int) $row['age_branch_id'],
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
        ];
    }

    public function create(string $deskCode, int $branchId, ?string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateInfo(int $id, ?string $name, ?string $email): void
    {
        $stmt = $this->pdo->prepare('UPDATE sections SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $id]);
    }

    /**
     * Ids of the sections currently active.
     *
     * @return list<int>
     */
    public function activeIds(): array
    {
        return array_map(
            'intval',
            $this->pdo->query('SELECT id FROM sections WHERE is_active = 1 ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * Recompute `sections.is_active` from the rosters themselves (issue
     * #1460): a section is active when at least one active member year of
     * a scout year starting on or after $fromStartDate holds a function in
     * it, and the section whose desk code is $alwaysActiveDeskCode (Staff
     * d'U, never named by a Desk « Section » column) always is.
     *
     * The flag is one column read by every list of sections, none of them
     * with a year in hand, so it cannot describe "the year being looked
     * at". What it describes is the year everybody is served and the ones
     * after it: an import of another year — the next one being prepared, a
     * past one replayed in configuration mode — then changes nothing the
     * public year shows, and a section that only exists next year stays
     * visible to the staff preparing it. A section with no members in any
     * of those years is kept (never deleted), only inactive, and comes back
     * the moment an import gives it members again.
     *
     * One statement over every section, from what is stored, so it is the
     * same answer whatever order years were imported in and however often.
     */
    public function recomputeActive(string $fromStartDate, string $alwaysActiveDeskCode): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sections SET is_active = CASE
                 WHEN desk_code = ? THEN 1
                 WHEN id IN (
                     SELECT mf.section_id
                     FROM member_functions mf
                     JOIN member_years my ON my.id = mf.member_year_id
                     JOIN scout_years sy ON sy.id = my.scout_year_id
                     WHERE my.is_active = 1 AND mf.section_id IS NOT NULL AND sy.start_date >= ?
                 ) THEN 1
                 ELSE 0
             END'
        );
        $stmt->execute([$alwaysActiveDeskCode, $fromStartDate]);
    }
}
