<?php

declare(strict_types=1);

namespace Modules\Trombinoscope\Service;

use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;

class TrombinoscopeService
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

        $lead = null;
        $staff = [];
        foreach ($eligible as $entry) {
            $profile = $this->sectionService->hydrateMemberProfile($entry['member_year_id']);
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
}
