<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Security\Role;

/**
 * "Which sections is this account a chief/animateur of, for the effective
 * scout year" (ARCHITECTURE.md §8) — a new authorization boundary this
 * codebase didn't have before: RBAC here is otherwise purely hierarchical
 * (a `chief` sees whatever `chief` sees). Not a departure from
 * ARCHITECTURE.md §2 — this is exactly its intended shape: the RBAC guard
 * still filters route access up front, and a Controller narrows further
 * onto one resource using this service, the same way MemberService::
 * canAccess() narrows onto one member. The decision of what to DO with
 * the answer stays with the caller — this service only answers "which
 * sections", never "is this allowed" — which is what keeps it reusable
 * across different call sites (first consumer: the "Départs" page).
 */
class SectionStaffAuthorizationService
{
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        private SectionService $sectionService
    ) {
    }

    /**
     * @return array<int, array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string}>
     */
    public function getStaffedSections(string $email, string $accountRole, int $scoutYearId): array
    {
        // admin/superadmin: every section, unconditionally — the service
        // owns this rule so no caller re-derives it ad hoc.
        if (Role::fromString($accountRole)->hasAccess(Role::ADMIN)) {
            return $this->sectionService->getAllWithBranches();
        }

        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)));
        $pdo = $this->connection->getPdo();

        // Same trap as SectionService::getSectionStaff(): an animé's own
        // member_functions row carries the same section_id as their
        // section's staff — without the role filter, every animé would
        // come back as an "animateur" of their own section.
        $stmt = $pdo->prepare(
            'SELECT DISTINCT mf.section_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE my.email_blind_index = ? AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN (\'chief\', \'admin\')
               AND mf.section_id IS NOT NULL'
        );
        $stmt->execute([$blindIndex, $scoutYearId]);
        $sectionIds = array_map(static fn(array $row): int => (int) $row['section_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $sections = [];
        foreach ($sectionIds as $sectionId) {
            $section = $this->sectionService->getSection($sectionId);
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        // Same branch-then-desk_code order as SectionService::
        // getAllWithBranches() (ARCHITECTURE.md §8.8) — the query above has
        // no ORDER BY (it only needs a DISTINCT id list), so without this a
        // multi-section chief/animateur would see their sections in
        // arbitrary DB order instead of matching every other section
        // picker on the site.
        usort($sections, static fn(array $a, array $b): int
            => [$a['branch_sort_order'], $a['desk_code']] <=> [$b['branch_sort_order'], $b['desk_code']]);

        return $sections;
    }
}
