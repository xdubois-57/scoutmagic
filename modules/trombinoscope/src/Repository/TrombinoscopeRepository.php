<?php

declare(strict_types=1);

namespace Modules\Trombinoscope\Repository;

use Core\Database\Connection;

class TrombinoscopeRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Every active member_year assigned to a section whose function has role
     * 'chief' or 'admin' (no further filtering — the trombinoscope always
     * shows all active chief/chief-d'unité staff), with whether that
     * assignment is flagged as the section's lead.
     *
     * @return array<int, array{member_year_id: int, is_lead: bool}>
     */
    public function getEligibleStaffForSection(int $sectionId, int $scoutYearId): array
    {
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare(
            "SELECT mf.member_year_id, mf.is_main_function, mf.id AS mf_id,
                    COALESCE(tff.is_lead, 0) AS is_lead
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             LEFT JOIN trombinoscope_function_flags tff ON tff.function_id = f.id
             WHERE mf.section_id = ? AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN ('chief', 'admin')
             ORDER BY mf.is_main_function DESC, mf.id ASC"
        );
        $stmt->execute([$sectionId, $scoutYearId]);

        // A member can hold several qualifying functions for the same
        // section; keep one entry per member, marked as lead if ANY of their
        // functions there is flagged as lead.
        $byMember = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearId = (int) $row['member_year_id'];
            if (!isset($byMember[$memberYearId])) {
                $byMember[$memberYearId] = ['member_year_id' => $memberYearId, 'is_lead' => (bool) $row['is_lead']];
            } elseif ((bool) $row['is_lead']) {
                $byMember[$memberYearId]['is_lead'] = true;
            }
        }

        return array_values($byMember);
    }
}
