<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Config\ScoutYearService;
use Core\Import\AgeBranchRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Module\StaffDirectoryProvider;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoService;
use Core\Security\Role;
use Core\View\EditableContentService;

/**
 * GET /api/offline/manifest (Core\Http\Controller\OfflineController) —
 * everything the caller's role entitles them to hold offline: every
 * whitelisted page URL (Core\Offline\OfflineWhitelist), with `/members/`
 * expanded to the caller's own linked members, and every image URL those
 * pages actually render — as the EXACT variant URL the page itself uses
 * (`/files/{id}/thumb` for member/staff photos, `/files/{id}/md` for
 * section photos, the home hero, branch logos). Same URL as the page, or
 * the cache misses — this is the one thing this whole feature exists to
 * get right (see Core\Photo\ImageVariantService, ARCHITECTURE §8.39).
 *
 * Supersedes the old, narrower `/api/offline/photo-manifest` (staff
 * photos only) — folded in here as the `/trombinoscope` case below, still
 * gated the same way on Core\Module\StaffDirectoryProvider.
 *
 * Deliberately hardcodes which CORE whitelisted page needs which image —
 * `/`, `/contact`, `/sections`, `/chefs/staffs`, `/members/` are core
 * routes, never a module's, so this is not "naming a module path in
 * Core\Offline" (that rule is about OfflineWhitelist's own declarations).
 * The one MODULE page with images, `/trombinoscope`, is handled the same
 * way Core\Http\Controller\OfflineController always resolved staff
 * photos — through the StaffDirectoryProvider hook, never by naming the
 * module. `/calendar`, `/notifications`, `/account`, `/rgpd`,
 * `/chiefs/stats`, `/previsions` render no images at all, so nothing is
 * added for them.
 */
class OfflineManifestService
{
    public function __construct(
        private OfflineWhitelist $offlineWhitelist,
        private MemberService $memberService,
        private MemberPhotoService $memberPhotoService,
        private SectionPhotoService $sectionPhotoService,
        private SectionService $sectionService,
        private UnitStaffSectionService $unitStaffSectionService,
        private ScoutYearService $scoutYearService,
        private EditableContentService $editableContentService,
        private AgeBranchRepository $ageBranchRepository,
        private ?StaffDirectoryProvider $staffDirectoryProvider = null
    ) {
    }

    /**
     * @return array{pages: array<int, string>, images: array<int, string>}
     */
    public function buildManifest(Role $role, ?string $email): array
    {
        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $entries = $this->offlineWhitelist->getEntriesForRole($role);

        $pages = [];
        $hasHome = false;
        $hasContact = false;
        $hasSectionsOrStaffs = false;
        $hasTrombinoscope = false;
        $hasMembers = false;

        foreach ($entries as $entry) {
            if ($entry['match'] === 'child' && $entry['path'] === '/members/') {
                // Never added literally — expanded to the caller's own
                // linked members below, each as its own page URL.
                $hasMembers = true;
                continue;
            }

            $pages[] = $entry['path'];

            match ($entry['path']) {
                '/' => $hasHome = true,
                '/contact' => $hasContact = true,
                '/sections', '/chefs/staffs' => $hasSectionsOrStaffs = true,
                '/trombinoscope' => $hasTrombinoscope = true,
                default => null,
            };
        }

        $linkedMembers = [];
        if ($hasMembers && $email !== null) {
            $linkedMembers = $this->memberService->getLinkedMembers($email, $scoutYearId);
            foreach ($linkedMembers as $member) {
                $pages[] = '/members/' . $member->memberId;
            }
        }

        $images = [];

        if ($hasHome) {
            $this->addImage($images, $this->editableContentService->get('home.hero'), 'md');
        }

        if ($hasContact) {
            $staffduSectionId = $this->unitStaffSectionService->ensureSection();
            $this->addImage($images, $this->sectionPhotoService->resolveFileId($staffduSectionId, $scoutYearId), 'md');
        }

        if ($hasSectionsOrStaffs) {
            foreach ($this->sectionService->getAllWithBranches() as $section) {
                $this->addImage($images, $this->sectionPhotoService->resolveFileId($section['id'], $scoutYearId), 'md');
            }
        }

        if ($hasTrombinoscope && $this->staffDirectoryProvider !== null) {
            foreach ($this->staffDirectoryProvider->getAllEligibleStaffMemberIds($scoutYearId) as $memberId) {
                $this->addImage($images, $this->memberPhotoService->resolveFileId($memberId, $scoutYearId), 'thumb');
            }
        }

        foreach ($linkedMembers as $member) {
            $this->addImage($images, $this->memberPhotoService->resolveFileId($member->memberId, $scoutYearId), 'thumb');
            $this->addImage($images, $this->resolveBranchLogoFileId($member), 'md');
        }

        return [
            'pages' => array_values(array_unique($pages)),
            'images' => array_values(array_unique($images)),
        ];
    }

    /**
     * The member's own age-branch federation logo (member page's branch
     * card, ARCHITECTURE §8.22) — resolved the same way
     * Core\Member\MemberPageService::buildBranchCard() does: main
     * function's Desk section code → section → age branch. Null whenever
     * any step doesn't resolve, or no logo was ever uploaded (the branch
     * card's shipped-default fallback is a static asset, already part of
     * the app-shell precache — nothing to add here for that case).
     */
    private function resolveBranchLogoFileId(\Core\Member\MemberProfile $member): ?int
    {
        $sectionCode = $member->getMainFunction()?->sectionCode;
        if ($sectionCode === null) {
            return null;
        }

        $section = $this->sectionService->findByDeskCode($sectionCode);
        if ($section === null) {
            return null;
        }

        $branch = $this->ageBranchRepository->findById($section['age_branch_id']);

        return $branch['logo_file_id'] ?? null;
    }

    /**
     * @param array<int, string> $images
     */
    private function addImage(array &$images, int|string|null $fileId, string $variant): void
    {
        if ($fileId === null || $fileId === '' || (int) $fileId <= 0) {
            return;
        }

        $images[] = '/files/' . (int) $fileId . '/' . $variant;
    }
}
