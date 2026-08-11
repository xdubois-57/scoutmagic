<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Member\MemberYearService;

/**
 * Resolves each animés branch's age bracket directly from Core\Member\
 * MemberYearService::BRANCHES (the federation age ranges member_stats
 * already uses) matched against the real, Desk-imported age_branches
 * table — never a separate, admin-configurable copy of the same numbers.
 * There is deliberately nothing to save here any more: an earlier version
 * of this module kept its own registration_age_brackets table, editable
 * from Configuration > Inscriptions, but that just duplicated a fact
 * already centralised elsewhere in the codebase and risked the two
 * silently drifting apart — removed once that redundancy was pointed out.
 */
class AgeBracketRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every animés branch (Baladins/Louveteaux/Éclaireurs/Pionniers) that
     * actually exists in this unit's Desk-imported age_branches, in
     * canonical branch order. A branch outside those four (Staff d'U,
     * Route, Iama, unknown) never appears — MemberYearService::
     * branchForSortOrder() returns null for it, filtered out below.
     *
     * @return array<AgeBracket>
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT id, label, sort_order FROM age_branches ORDER BY sort_order');
        if ($stmt === false) {
            return [];
        }

        $brackets = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $bracket = $this->hydrate($row);
            if ($bracket !== null) {
                $brackets[] = $bracket;
            }
        }

        return $brackets;
    }

    /**
     * Null when $ageBranchId doesn't exist, or exists but isn't one of the
     * four animés branches this module has anything to do with.
     */
    public function findForBranch(int $ageBranchId): ?AgeBracket
    {
        $stmt = $this->pdo->prepare('SELECT id, label, sort_order FROM age_branches WHERE id = ?');
        $stmt->execute([$ageBranchId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @param array{id: int|string, label: string, sort_order: int|string} $row
     */
    private function hydrate(array $row): ?AgeBracket
    {
        $range = MemberYearService::branchForSortOrder((int) $row['sort_order']);
        if ($range === null) {
            return null;
        }

        return new AgeBracket(
            ageBranchId: (int) $row['id'],
            branchLabel: (string) $row['label'],
            branchSortOrder: (int) $row['sort_order'],
            entryAge: $range['age_min'],
            durationYears: $range['age_max'] - $range['age_min'] + 1
        );
    }
}
