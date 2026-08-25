<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * `import_journal` — the parent row of a Desk import.
 *
 * It stopped being a bare audit line when the CSV started being kept: the
 * file, the roster snapshot and (from the diff onwards) the report all
 * hang off it, so it is also what the retention purge deletes to make a
 * whole season's imports go together.
 */
class ImportJournalRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(
        int $scoutYearId,
        int $userId,
        int $lineCount,
        int $memberCount,
        int $newFunctions,
        ?int $fileId = null
    ): int {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, user_account_id, line_count, member_count, new_functions_count, file_id, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$scoutYearId, $userId, $lineCount, $memberCount, $newFunctions, $fileId, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM import_journal WHERE scout_year_id = ? ORDER BY imported_at DESC, id DESC'
        );
        $stmt->execute([$scoutYearId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The year's imports, newest first, as value objects.
     *
     * @return ImportRecord[]
     */
    public function findRecordsByYear(int $scoutYearId): array
    {
        return array_map(
            static fn(array $row): ImportRecord => ImportRecord::fromRow($row),
            $this->findByYear($scoutYearId)
        );
    }

    public function findById(int $id): ?ImportRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM import_journal WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ImportRecord::fromRow($row);
    }

    /**
     * The import that ran immediately before $id, in the same scout year —
     * the point of comparison a report is computed against, and null when
     * there is none because this is the season's first.
     */
    public function findPreviousInYear(int $scoutYearId, int $id): ?ImportRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM import_journal
             WHERE scout_year_id = ? AND id < ?
             ORDER BY imported_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ImportRecord::fromRow($row);
    }

    /**
     * Every scout year that has at least one import, newest year first.
     *
     * @return int[] scout_years.id
     */
    public function findYearsWithImports(): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT ij.scout_year_id
             FROM import_journal ij
             JOIN scout_years sy ON sy.id = ij.scout_year_id
             ORDER BY sy.start_date DESC'
        );

        return $stmt === false ? [] : array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Store the diff this import produced.
     *
     * Written once, at the end of the import, and never rewritten: the
     * diff is a dated fact, and a report that could change under its own
     * heading would be worse than no report (see {@see ImportDiff}).
     */
    public function storeDiff(int $importId, ImportDiff $diff): void
    {
        $stmt = $this->pdo->prepare('UPDATE import_journal SET diff_json = ? WHERE id = ?');
        $stmt->execute([json_encode($diff->toArray(), JSON_UNESCAPED_UNICODE), $importId]);
    }

    /**
     * The stored diff, or null when this import never carried one — a row
     * written before diffs existed. An import that HAD nothing to compare
     * against carries a stored, deliberately unavailable diff instead, so
     * that the two cases stay distinguishable.
     */
    public function findDiff(int $importId): ?ImportDiff
    {
        $stmt = $this->pdo->prepare('SELECT diff_json FROM import_journal WHERE id = ?');
        $stmt->execute([$importId]);
        $json = $stmt->fetchColumn();

        if ($json === false || $json === null || $json === '') {
            return null;
        }

        $data = json_decode((string) $json, true);

        return is_array($data) ? ImportDiff::fromArray($data) : null;
    }

    public function countForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM import_journal WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes the import rows of a whole scout year.
     *
     * Whole seasons and never single rows, because that is what the
     * retention rule is about (`ImportRetentionService`) — and the
     * snapshots hanging off these rows go with them through
     * `fk_frs_import`'s ON DELETE CASCADE.
     */
    public function deleteForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM import_journal WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);

        return $stmt->rowCount();
    }
}
