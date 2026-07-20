<?php

declare(strict_types=1);

namespace Core\View;

class SectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Get all sections grouped by age branch. "Staff d'U" (STAFFDU) is
     * deliberately excluded from this public read-model — it's a real
     * section for internal purposes (section picker, trombinoscope,
     * calendar) but not listed on the public "Notre unité > Sections" page.
     *
     * @return array<array{branch_label: string, sections: array<array{name: ?string, desk_code: string, email: ?string}>}>
     */
    public function findAllGroupedByBranch(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.name, s.desk_code, s.email, ab.label AS branch_label, ab.sort_order
             FROM sections s
             JOIN age_branches ab ON s.age_branch_id = ab.id
             WHERE s.is_visible = 1 AND s.is_active = 1 AND s.desk_code != \'STAFFDU\'
             ORDER BY ab.sort_order, s.desk_code'
        );

        if ($stmt === false) {
            return [];
        }

        $groups = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $branchLabel = $row['branch_label'];
            if (!isset($groups[$branchLabel])) {
                $groups[$branchLabel] = [
                    'branch_label' => $branchLabel,
                    'sections' => [],
                ];
            }
            $groups[$branchLabel]['sections'][] = [
                'name' => $row['name'],
                'desk_code' => $row['desk_code'],
                'email' => $row['email'],
            ];
        }

        return array_values($groups);
    }
}
