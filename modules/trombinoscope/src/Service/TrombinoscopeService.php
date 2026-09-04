<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Service;

use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\Module\SectionResponsableProvider;
use Core\Module\StaffDirectoryProvider;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;

class TrombinoscopeService implements SectionResponsableProvider, StaffDirectoryProvider
{
    public function __construct(
        private TrombinoscopeRepository $repository,
        private SectionService $sectionService
    ) {
    }

    /**
     * The section's highlighted "responsable" (or null when none is
     * configured) and the rest of its staff, sorted by display name.
     *
     * @return array{lead: ?MemberProfile, staff: MemberProfile[]}
     */
    public function getSectionStaff(int $sectionId, int $scoutYearId): array
    {
        $eligible = $this->repository->getEligibleStaffForSection($sectionId, $scoutYearId);
        $profiles = $this->sectionService->hydrateMemberProfiles(
            array_map(fn(array $entry) => (int) $entry['member_year_id'], $eligible)
        );

        return $this->splitLeadAndStaff($eligible, $profiles);
    }

    /**
     * getSectionStaff() for every section of the wall, the PDF or the
     * public sections page in one pass: one eligibility query and one
     * profile hydration for all of them, instead of five queries per
     * section (fourteen sections used to cost seventy).
     *
     * @param array<int, int> $sectionIds
     * @return array<int, array{lead: ?MemberProfile, staff: MemberProfile[]}> keyed by section id
     */
    public function getSectionStaffForSections(array $sectionIds, int $scoutYearId): array
    {
        $eligibleBySection = $this->repository->getEligibleStaffForSections($sectionIds, $scoutYearId);

        $memberYearIds = [];
        foreach ($eligibleBySection as $eligible) {
            foreach ($eligible as $entry) {
                $memberYearIds[] = (int) $entry['member_year_id'];
            }
        }
        $profiles = $this->sectionService->hydrateMemberProfiles($memberYearIds);

        $result = [];
        foreach ($eligibleBySection as $sectionId => $eligible) {
            $result[$sectionId] = $this->splitLeadAndStaff($eligible, $profiles);
        }

        return $result;
    }

    /**
     * @param array<int, array{member_year_id: int, is_lead: bool}> $eligible
     * @param array<int, MemberProfile> $profiles keyed by member_year_id
     * @return array{lead: ?MemberProfile, staff: MemberProfile[]}
     */
    private function splitLeadAndStaff(array $eligible, array $profiles): array
    {
        $lead = null;
        $staff = [];
        foreach ($eligible as $entry) {
            $profile = $profiles[(int) $entry['member_year_id']] ?? null;
            if ($profile === null) {
                continue;
            }
            if ($entry['is_lead'] && $lead === null) {
                $lead = $profile;
            } else {
                $staff[] = $profile;
            }
        }

        usort($staff, fn(MemberProfile $a, MemberProfile $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        return ['lead' => $lead, 'staff' => $staff];
    }

    /**
     * The responsable of every requested section, in one pass.
     *
     * @param array<int, int> $sectionIds
     * @return array<int, ?MemberProfile> keyed by section id
     */
    public function getResponsables(array $sectionIds, int $scoutYearId): array
    {
        return array_map(
            static fn(array $data): ?MemberProfile => $data['lead'],
            $this->getSectionStaffForSections($sectionIds, $scoutYearId)
        );
    }

    /**
     * Core\Module\SectionResponsableProvider implementation — reuses the
     * same "lead" resolution as getSectionStaff() above.
     */
    public function getResponsable(int $sectionId, int $scoutYearId): ?MemberProfile
    {
        return $this->getSectionStaff($sectionId, $scoutYearId)['lead'];
    }

    /**
     * Core\Module\StaffDirectoryProvider implementation.
     *
     * @return int[]
     */
    public function getAllEligibleStaffMemberIds(int $scoutYearId): array
    {
        return $this->repository->getAllEligibleStaffMemberIds($scoutYearId);
    }
}
