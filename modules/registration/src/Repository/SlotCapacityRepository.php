<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

class SlotCapacityRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<int, array<int, int>> capacity[ageBranchId][yearInBranch] = capacity
     */
    public function findAllAsMap(): array
    {
        $stmt = $this->pdo->query('SELECT age_branch_id, year_in_branch, capacity FROM registration_slot_capacities');
        if ($stmt === false) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['age_branch_id']][(int) $row['year_in_branch']] = (int) $row['capacity'];
        }

        return $map;
    }

    public function capacityFor(int $ageBranchId, int $yearInBranch): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT capacity FROM registration_slot_capacities WHERE age_branch_id = ? AND year_in_branch = ?'
        );
        $stmt->execute([$ageBranchId, $yearInBranch]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    public function upsert(int $ageBranchId, int $yearInBranch, int $capacity): void
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
}
