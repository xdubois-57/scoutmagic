<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

class AgeBracketRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every configured bracket, ordered by the branch's canonical sort
     * order (Baladins → Louveteaux → Éclaireurs → Pionniers, per
     * Core\Import\AgeBranchRepository::canonicalSortOrder()) — Service\
     * SlotService relies on this order to find "the previous branch".
     *
     * @return array<AgeBracket>
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT rab.age_branch_id, rab.entry_age, rab.duration_years, ab.label, ab.sort_order
             FROM registration_age_brackets rab
             JOIN age_branches ab ON ab.id = rab.age_branch_id
             ORDER BY ab.sort_order'
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn(array $row) => new AgeBracket(
                ageBranchId: (int) $row['age_branch_id'],
                branchLabel: (string) $row['label'],
                branchSortOrder: (int) $row['sort_order'],
                entryAge: (int) $row['entry_age'],
                durationYears: (int) $row['duration_years']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function findForBranch(int $ageBranchId): ?AgeBracket
    {
        $stmt = $this->pdo->prepare(
            'SELECT rab.age_branch_id, rab.entry_age, rab.duration_years, ab.label, ab.sort_order
             FROM registration_age_brackets rab
             JOIN age_branches ab ON ab.id = rab.age_branch_id
             WHERE rab.age_branch_id = ?'
        );
        $stmt->execute([$ageBranchId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new AgeBracket(
            ageBranchId: (int) $row['age_branch_id'],
            branchLabel: (string) $row['label'],
            branchSortOrder: (int) $row['sort_order'],
            entryAge: (int) $row['entry_age'],
            durationYears: (int) $row['duration_years']
        );
    }

    /**
     * Create or update a branch's bracket — the admin config screen's only
     * write path for this table (Controller\RegistrationConfigController).
     */
    public function upsert(int $ageBranchId, int $entryAge, int $durationYears): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_age_brackets (age_branch_id, entry_age, duration_years)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE entry_age = VALUES(entry_age), duration_years = VALUES(duration_years)'
        );
        $stmt->execute([$ageBranchId, $entryAge, $durationYears]);
    }
}
