<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * Reads and writes `registration_slot_capacities`, whose `capacity` column
 * is deliberately nullable — see that table's own comment in schema.sql.
 *
 * **NULL is never 0 here, on any code path.** NULL means "no limit
 * recorded for this slot"; 0 means "this slot is deliberately closed".
 * Every method below therefore carries `?int` rather than `int`: casting a
 * NULL to a number on the way out of the database is the single mistake
 * that turns an unconfigured branch into a branch announced full, and the
 * only reliable place to refuse it is right here, where the value leaves
 * the database.
 */
class SlotCapacityRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every recorded slot, NULL capacities included — a slot absent from
     * this map has no row at all, which callers read the same way they read
     * a NULL (no limit).
     *
     * @return array<int, array<int, ?int>> capacity[ageBranchId][yearInBranch] = capacity or null
     */
    public function findAllAsMap(): array
    {
        $stmt = $this->pdo->query('SELECT age_branch_id, year_in_branch, capacity FROM registration_slot_capacities');
        if ($stmt === false) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['age_branch_id']][(int) $row['year_in_branch']] =
                $row['capacity'] === null ? null : (int) $row['capacity'];
        }

        return $map;
    }

    /**
     * The slot's capacity, or null when it has no row or a NULL one — both
     * mean "no limit", never zero.
     */
    public function capacityFor(int $ageBranchId, int $yearInBranch): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT capacity FROM registration_slot_capacities WHERE age_branch_id = ? AND year_in_branch = ?'
        );
        $stmt->execute([$ageBranchId, $yearInBranch]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (int) $value;
    }

    /**
     * @param ?int $capacity null = no limit, 0 = deliberately closed
     */
    public function upsert(int $ageBranchId, int $yearInBranch, ?int $capacity): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM registration_slot_capacities WHERE age_branch_id = ? AND year_in_branch = ?'
        );
        $stmt->execute([$ageBranchId, $yearInBranch]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare('UPDATE registration_slot_capacities SET capacity = ? WHERE id = ?');
            $update->execute([$capacity, (int) $existingId]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO registration_slot_capacities (age_branch_id, year_in_branch, capacity) VALUES (?, ?, ?)'
        );
        $insert->execute([$ageBranchId, $yearInBranch, $capacity]);
    }

    /**
     * Writes $capacity for a slot that has **no row at all**, and only
     * then — this is how the module's default capacity genuinely reaches
     * the database rather than merely being displayed (Service\SlotService::
     * seedMissingCapacities()).
     *
     * A slot whose row exists is never touched, whatever it holds: a
     * chief who cleared the box on purpose stored NULL, and re-seeding it
     * would keep undoing that decision on every page load. Returns true
     * when a row was actually created.
     */
    public function insertIfMissing(int $ageBranchId, int $yearInBranch, ?int $capacity): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM registration_slot_capacities WHERE age_branch_id = ? AND year_in_branch = ?'
        );
        $stmt->execute([$ageBranchId, $yearInBranch]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO registration_slot_capacities (age_branch_id, year_in_branch, capacity) VALUES (?, ?, ?)'
            );
            $insert->execute([$ageBranchId, $yearInBranch, $capacity]);
        } catch (\PDOException) {
            // Two chiefs opening the page at the same instant: the unique
            // index on (age_branch_id, year_in_branch) decides, and losing
            // that race means the row now exists — the outcome this method
            // wanted anyway, never a 500.
            return false;
        }

        return true;
    }
}
