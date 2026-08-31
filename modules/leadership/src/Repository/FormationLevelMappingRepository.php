<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Repository;

use Core\Database\Connection;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Service\FormationLevelResolver;

/**
 * The module's one table: which normalised step a raw Desk formation level
 * means. No member data, no scout year — see schema.sql for why neither
 * belongs here.
 */
class FormationLevelMappingRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * The whole mapping, keyed by folded raw value — the shape
     * Service\FormationLevelResolver consumes. Small by construction: one
     * row per distinct wording a unit has ever had to decide about.
     *
     * @return array<string, string> folded raw value → step value
     */
    public function findAll(): array
    {
        $stmt = $this->connection->getPdo()->query(
            'SELECT raw_value_key, step FROM leadership_formation_levels'
        );

        $mapping = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $mapping[(string) $row['raw_value_key']] = (string) $row['step'];
        }

        return $mapping;
    }

    /**
     * @return list<array{raw_value: string, step: string}>
     */
    public function findAllRows(): array
    {
        $stmt = $this->connection->getPdo()->query(
            'SELECT raw_value, step FROM leadership_formation_levels ORDER BY raw_value ASC'
        );

        $rows = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['raw_value' => (string) $row['raw_value'], 'step' => (string) $row['step']];
        }

        return $rows;
    }

    /**
     * Record (or change) what a raw value means. Keyed on the folded value,
     * so "Animateur Breveté" and "animateur brevete" are one decision
     * rather than two rows that could contradict each other; the verbatim
     * value is kept alongside so the page can show the admin the string
     * they actually saw in Desk.
     */
    public function save(string $rawValue, FormationStep $step): void
    {
        $key = FormationLevelResolver::keyFor($rawValue);
        if ($key === '') {
            return;
        }

        $pdo = $this->connection->getPdo();
        $now = date('Y-m-d H:i:s');

        // Portable insert-or-update: no MySQL-only ON DUPLICATE KEY, since
        // this repository is exercised against SQLite in the tests (same
        // reason as Modules\Gallery\Repository\S3SecretRepository::set()).
        $exists = $pdo->prepare('SELECT 1 FROM leadership_formation_levels WHERE raw_value_key = ?');
        $exists->execute([$key]);

        if ($exists->fetchColumn() !== false) {
            $stmt = $pdo->prepare(
                'UPDATE leadership_formation_levels
                 SET raw_value = ?, step = ?, updated_at = ?
                 WHERE raw_value_key = ?'
            );
            $stmt->execute([$rawValue, $step->value, $now, $key]);

            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO leadership_formation_levels (raw_value, raw_value_key, step, updated_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$rawValue, $key, $step->value, $now]);
    }

    /**
     * Reclassify the mappings an admin recorded before the BACV and the
     * Woodbadge had boxes of their own (roadmap IT-19).
     *
     * Everything a unit had decided was a brevet landed on the legacy box,
     * whatever kind of brevet it was; the ONE ratio now needs the two
     * apart. What the raw wording says is the only evidence available, and
     * it is enough for the two named ones: a folded key carrying « bacv »
     * is a BACV, one carrying « wood » is a Woodbadge. **Everything else
     * stays on the legacy box** — a brevet whose kind nobody recorded is
     * not a BACV just because the site would prefer a cleaner number.
     *
     * Two set-based statements rather than a row loop: the table is small
     * by construction, but this runs on a live request and a loop over it
     * would be round trips for nothing. Only rows still on the legacy box
     * are touched, so the whole thing is idempotent — a second run matches
     * nothing — and re-running it can never undo a decision an admin has
     * since made by hand, since such a row no longer says 'brevet'.
     *
     * BACV is applied first: a wording mentioning both is a BACV that also
     * says which weekend it was earned on far more often than it is a
     * Woodbadge, and one of the two has to win a comparison nobody can
     * settle from a string.
     *
     * @return int rows reclassified
     */
    public function reclassifyLegacyBrevetRows(): int
    {
        $pdo = $this->connection->getPdo();
        $now = date('Y-m-d H:i:s');
        $changed = 0;

        foreach ([
            ['%bacv%', FormationStep::BACV],
            ['%wood%', FormationStep::WOODBADGE],
        ] as [$pattern, $step]) {
            $stmt = $pdo->prepare(
                'UPDATE leadership_formation_levels
                 SET step = ?, updated_at = ?
                 WHERE step = ? AND raw_value_key LIKE ?'
            );
            $stmt->execute([$step->value, $now, FormationStep::BREVET->value, $pattern]);
            $changed += $stmt->rowCount();
        }

        return $changed;
    }

    /** Undo a mapping: the value goes back to whatever the heuristic makes of it. */
    public function delete(string $rawValue): void
    {
        $key = FormationLevelResolver::keyFor($rawValue);
        if ($key === '') {
            return;
        }

        $stmt = $this->connection->getPdo()->prepare(
            'DELETE FROM leadership_formation_levels WHERE raw_value_key = ?'
        );
        $stmt->execute([$key]);
    }
}
