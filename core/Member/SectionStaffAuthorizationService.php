<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Repository\StaffedSectionRepository;
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
     * The secondary-address resolution and its fail-closed semantics moved
     * into {@see StaffedSectionRepository} with the query they belong to
     * (issue #551): this Service no longer holds a `Connection`, an
     * `EncryptionService` or the purpose string `'email'`, so it cannot read
     * a member row at all — it decides, and the repository answers.
     */
    public function __construct(
        private StaffedSectionRepository $staffedSections,
        private SectionService $sectionService
    ) {
    }

    /**
     * The sections this address GENUINELY staffs — the role-filtered list,
     * with no widening for admins, unlike {@see getStaffedSections()}.
     *
     * The difference decides correctness, not convenience.
     * `getStaffedSections()` answers « which sections may this account act
     * on », so it short-circuits to every section for an admin: right for a
     * picker or a page, and vacuous for a caller that needs to know whether
     * a real staffing relationship exists. Raised in review of #664, where
     * a carpool's section was being checked against the widened list and so
     * was not checked at all for a chef d'unité.
     *
     * Use this one to establish a relationship; use the other to decide what
     * somebody may reach.
     *
     * @return int[]
     */
    public function ownStaffedSectionIds(string $email, int $scoutYearId): array
    {
        return $this->staffedSections->staffedSectionIds($email, $scoutYearId);
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

        $sectionIds = $this->staffedSections->staffedSectionIds($email, $scoutYearId);

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
        usort(
            $sections,
            static fn(array $a, array $b): int
                => [$a['branch_sort_order'], $a['desk_code']] <=> [$b['branch_sort_order'], $b['desk_code']]
        );

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
}
