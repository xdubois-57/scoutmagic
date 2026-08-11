<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;

/**
 * Section-scoped chief access: a chief may only manage albums for a
 * section where at least one of their linked members holds a chief+
 * function — unlike Core\Http\Controller\StaffsController's own section
 * filtering (any chief sees every section there), this module's spec
 * calls for real per-section scoping. Admin/superadmin always bypass it.
 */
class GalleryAccessService
{
    public function __construct(
        private MemberService $memberService,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService
    ) {
    }

    public function canManageAlbum(Role $role, ?int $albumSectionId, string $email): bool
    {
        if ($role->hasAccess(Role::ADMIN)) {
            return true;
        }
        if (!$role->hasAccess(Role::CHIEF)) {
            return false;
        }
        if ($albumSectionId === null) {
            // Unit-wide album — any chief/admin may manage it.
            return true;
        }

        return in_array($albumSectionId, $this->getManagedSectionIds($email), true);
    }

    /**
     * Section ids the given email holds a chief+ function for, across the
     * current and previous scout year (module spec) — MemberFunctionInfo
     * only carries a section desk_code, so it's resolved against
     * SectionService::getAllWithBranches() the same way
     * StaffsController::getLinkedSectionCodes() does.
     *
     * @return int[]
     */
    public function getManagedSectionIds(string $email): array
    {
        $sectionCodes = [];
        foreach ($this->relevantScoutYearIds() as $scoutYearId) {
            foreach ($this->memberService->getLinkedMembers($email, $scoutYearId) as $member) {
                foreach ($member->functions as $function) {
                    if ($function->sectionCode !== null && Role::fromString($function->functionRole)->hasAccess(Role::CHIEF)) {
                        $sectionCodes[] = $function->sectionCode;
                    }
                }
            }
        }
        $sectionCodes = array_unique($sectionCodes);
        if ($sectionCodes === []) {
            return [];
        }

        $ids = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if (in_array($section['desk_code'], $sectionCodes, true)) {
                $ids[] = (int) $section['id'];
            }
        }
        return $ids;
    }

    /**
     * @return int[] current scout year id, and the previous one if it exists
     */
    private function relevantScoutYearIds(): array
    {
        $current = $this->scoutYearService->getCurrentYear();
        $years = $this->scoutYearService->getAll();

        $ids = [$current['id']];
        foreach ($years as $index => $year) {
            if ($year['id'] === $current['id']) {
                if ($index > 0) {
                    $ids[] = $years[$index - 1]['id'];
                }
                break;
            }
        }
        return $ids;
    }
}
