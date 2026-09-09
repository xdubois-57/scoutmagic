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
 *
 * Resolution matches MemberService::getLinkedMembers(): the account's
 * Desk address AND every member it reaches as a currently-'valid'
 * secondary address. The one thing it deliberately does not resolve is
 * the temporary member override (ARCHITECTURE.md §8.42) — it exists for
 * admins, who already get every section from the rule above.
 */
class SectionStaffAuthorizationService
{
    /**
     * $memberEmailRepo is optional, exactly as it is on MemberService and
     * Core\Security\RoleResolver: given one, the account is resolved
     * through its currently-'valid' secondary addresses as well as its
     * Desk address. Omitting it can only ever return FEWER sections, so a
     * call site that forgets it fails closed — but it also silently
     * strips an animateur who signs in with a secondary address of every
     * section they staff, which is why the composition root passes it.
     */
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        private SectionService $sectionService,
        private ?MemberEmailRepository $memberEmailRepo = null
    ) {
    }

    /**
     * @return array<
     *     int,
     *     array{
     *         id: int,
     *         desk_code: string,
     *         name: ?string,
     *         email: ?string,
     *         age_branch_id: int,
     *         branch_name: string,
     *         branch_sort_order: int,
     *         color: ?string
     *     }
     * >
     */
    public function getStaffedSections(string $email, string $accountRole, int $scoutYearId): array
    {
        // admin/superadmin: every section, unconditionally — the service
        // owns this rule so no caller re-derives it ad hoc.
        if (Role::fromString($accountRole)->hasAccess(Role::ADMIN)) {
            return $this->sectionService->getAllWithBranches();
        }

        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
        $pdo = $this->connection->getPdo();

        // The same address reaches a member two ways, and this service is
        // the boundary that decides what they may edit — so it resolves
        // BOTH, exactly like MemberService::getLinkedMembers(): the Desk
        // address on member_years, and every member reachable through a
        // currently-'valid' secondary address (a 'pending'/'inactive' one
        // grants nothing, same rule as Core\Security\RoleResolver).
        // Matching only the Desk address made an animateur who signs in
        // with their secondary address the animateur of no section at all.
        $memberIds = $this->memberEmailRepo?->findMemberIdsByValidBlindIndex($blindIndex) ?? [];
        $memberIdPlaceholders = $memberIds !== [] ? implode(',', array_fill(0, count($memberIds), '?')) : null;

        // Same trap as SectionService::getSectionStaff(): an animé's own
        // member_functions row carries the same section_id as their
        // section's staff — without the role filter, every animé would
        // come back as an "animateur" of their own section.
        $stmt = $pdo->prepare(
            'SELECT DISTINCT mf.section_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             WHERE (my.email_blind_index = ?'
            . ($memberIdPlaceholders !== null ? " OR my.member_id IN ({$memberIdPlaceholders})" : '')
            . ') AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN (\'chief\', \'admin\')
               AND mf.section_id IS NOT NULL'
        );
        $stmt->execute([$blindIndex, ...$memberIds, $scoutYearId]);
        $sectionIds = array_map(static fn(array $row): int => (int) $row['section_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC));

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

    /**
     * The sections this account staffs in ANY of the given years, merged.
     *
     * The « animateur » half of the same transition rule Core\ScoutYear\
     * ScoutYearResolver::getAccessYearIds() states in full: between the 1st
     * of September and the Desk import, a section's staff exists in one of
     * the two years and not the other, so asking one year alone empties the
     * Espace animateurs of somebody who staffs a section either side of the
     * turnover.
     *
     * Deduplicated by section id — a section staffed in both years is one
     * section — and re-sorted afterwards, since merging two individually
     * ordered lists does not give an ordered list and every section picker
     * on the site shares this order (ARCHITECTURE.md §8.8).
     *
     * @param list<int> $scoutYearIds
     * @return array<int, array<string, mixed>>
     */
    public function getStaffedSectionsAcrossYears(string $email, string $accountRole, array $scoutYearIds): array
    {
        $byId = [];
        foreach ($scoutYearIds as $scoutYearId) {
            foreach ($this->getStaffedSections($email, $accountRole, $scoutYearId) as $section) {
                $byId[(int) $section['id']] = $section;
            }
        }

        $sections = array_values($byId);
        usort($sections, static fn(array $a, array $b): int
            => [$a['branch_sort_order'], $a['desk_code']] <=> [$b['branch_sort_order'], $b['desk_code']]);

        return $sections;
    }

    /**
     * Whether this account staffs the section a given ANIMÉ member-year
     * belongs to — the write-side boundary §8.33 describes, as one
     * predicate rather than a copy per caller.
     *
     * **Animé, and the word is load-bearing.** It goes through
     * SectionService::getSectionAnimeMemberYearIds(), which excludes
     * chief/admin/intendant functions and inactive rows: a section's own
     * staff are not among "the animés of my section", so this answers
     * false for them. That is right for what it guards — the scout-year
     * offset is « en avance ou en retard sur l'année habituelle de sa
     * section », a fact about an animé's branch year, and a chief has no
     * branch year to shift.
     *
     * It also means **a screen must not offer that control where this
     * answers false**, which is exactly the bug the first version caused:
     * the member page rendered the offset buttons for everybody while the
     * write refused staff and inactive rows, so the control was on screen
     * and guaranteed to fail. Core\Member\Controller\
     * MemberSearchController::show() now asks this the same question the
     * write asks, and hides the card when the answer is no.
     */
    public function staffsAnimeMemberYear(
        string $email,
        string $accountRole,
        int $scoutYearId,
        int $memberYearId
    ): bool {
        foreach ($this->getStaffedSections($email, $accountRole, $scoutYearId) as $section) {
            if (in_array(
                $memberYearId,
                $this->sectionService->getSectionAnimeMemberYearIds((int) $section['id'], $scoutYearId),
                true
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same predicate over several years.
     *
     * Delegates PER YEAR rather than pairing the merged section list with
     * each year's animés in turn, and that is not an implementation
     * detail: a member-year belongs to exactly one scout year, so crossing
     * a section staffed in one year with the animés of the other would
     * grant writes over animés this account staffs in neither. The union
     * belongs on the answer, never inside the question.
     *
     * @param list<int> $scoutYearIds
     */
    public function staffsAnimeMemberYearAcrossYears(
        string $email,
        string $accountRole,
        array $scoutYearIds,
        int $memberYearId
    ): bool {
        foreach ($scoutYearIds as $scoutYearId) {
            if ($this->staffsAnimeMemberYear($email, $accountRole, $scoutYearId, $memberYearId)) {
                return true;
            }
        }

        return false;
    }
}
