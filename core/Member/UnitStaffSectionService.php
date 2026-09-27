<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Repository\UnitStaffSectionRepository;

/**
 * "Staff d'U" (chef d'unité staff) is a real, generic section like any
 * other — not a virtual/derived query — so it appears everywhere a section
 * does (section picker, trombinoscope, calendar) with no special-casing.
 *
 * A member's role is only known once an admin confirms the function's role
 * on Correspondances Desk (never at raw Desk CSV import time — new functions always
 * import as role 'identified'), so membership must be (re)synced both at
 * the end of a Desk import and whenever a function's role changes.
 */
class UnitStaffSectionService
{
    public const DESK_CODE = 'STAFFDU';
    private const BRANCH_LABEL = "Staff d'U";

    /**
     * The canonical « Staff d'U » slot, between Pionniers (40) and Route
     * (60) — see `AgeBranchRepository::canonicalSortOrder()`.
     */
    private const BRANCH_SORT_ORDER = 50;

    public function __construct(private UnitStaffSectionRepository $repository)
    {
    }

    /**
     * Idempotently ensure the "Staff d'U" age branch and section exist, and
     * force the section active. It must survive
     * ImportSectionRepository::deactivateAll() even though nothing in a
     * Desk CSV row ever references it directly — membership comes only
     * from syncMembership(), never from a "Section" column value.
     */
    public function ensureSection(): int
    {
        $branchId = $this->ensureBranch();

        $existingId = $this->repository->sectionIdByDeskCode(self::DESK_CODE);
        if ($existingId === null) {
            return $this->repository->insertSection(self::DESK_CODE, $branchId, self::BRANCH_LABEL);
        }

        $this->repository->activateSection($existingId);

        return $existingId;
    }

    private function ensureBranch(): int
    {
        return $this->repository->ageBranchIdByDeskCode(self::DESK_CODE)
            ?? $this->repository->insertAgeBranch(
                self::DESK_CODE,
                self::BRANCH_LABEL,
                self::BRANCH_SORT_ORDER
            );
    }

    /**
     * Assign every member holding a chef d'unité (role = 'admin') function
     * with no section yet, in the given scout year, to the "Staff d'U"
     * section — and clear the assignment for anyone previously synced in
     * who no longer qualifies (role changed away from 'admin'). Portable
     * two-step select-then-update (no multi-table UPDATE...JOIN) so this
     * also runs against the SQLite test database.
     */
    public function syncMembership(int $scoutYearId): void
    {
        $staffduId = $this->ensureSection();

        // Both directions, and the order does not matter: the two sets are
        // disjoint by construction — one is « role admin, no section », the
        // other « in this section, role no longer admin ».
        $this->repository->assignSection(
            $this->repository->functionIdsToAssign($scoutYearId),
            $staffduId
        );
        $this->repository->assignSection(
            $this->repository->functionIdsToClear($staffduId, $scoutYearId),
            null
        );
    }
}
