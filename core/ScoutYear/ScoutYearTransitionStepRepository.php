<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * Manual check-off state for the "Année scoute" workflow's steps
 * (schema/core.sql: scout_year_transition_steps).
 *
 * A row's presence is the "done" state — there is no boolean column and no
 * "undone" row: unmarking deletes. Everything is keyed by the scout year
 * the workflow targets, so each year's run starts blank without any reset.
 */
class ScoutYearTransitionStepRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every manually-marked step for one target year, keyed by step key.
     *
     * @return array<string, array{done_at: string, done_by: ?int}>
     */
    public function findByYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT step_key, done_at, done_by FROM scout_year_transition_steps WHERE scout_year_id = ?'
        );
        $stmt->execute([$scoutYearId]);

        $marks = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $marks[(string) $row['step_key']] = [
                'done_at' => (string) $row['done_at'],
                'done_by' => $row['done_by'] !== null ? (int) $row['done_by'] : null,
            ];
        }

        return $marks;
    }

    /**
     * Mark a step done. Idempotent: re-marking an already-marked step
     * refreshes who did it and when, rather than failing on the unique
     * index or silently keeping a stale author.
     */
    public function mark(int $scoutYearId, string $stepKey, ?int $doneBy): void
    {
        // Select-then-write rather than an upsert clause: the same portable
        // shape Core\Photo\SectionPhotoRepository::upsert() uses, so this
        // runs unchanged on MySQL and on the SQLite the test suite builds.
        $stmt = $this->pdo->prepare(
            'SELECT id FROM scout_year_transition_steps WHERE scout_year_id = ? AND step_key = ?'
        );
        $stmt->execute([$scoutYearId, $stepKey]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE scout_year_transition_steps SET done_at = ?, done_by = ? WHERE id = ?'
            );
            $update->execute([date('Y-m-d H:i:s'), $doneBy, (int) $existingId]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO scout_year_transition_steps (scout_year_id, step_key, done_at, done_by) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$scoutYearId, $stepKey, date('Y-m-d H:i:s'), $doneBy]);
    }

    /**
     * Unmark a step. A no-op when it was never marked.
     */
    public function unmark(int $scoutYearId, string $stepKey): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM scout_year_transition_steps WHERE scout_year_id = ? AND step_key = ?'
        );
        $stmt->execute([$scoutYearId, $stepKey]);
    }
}
