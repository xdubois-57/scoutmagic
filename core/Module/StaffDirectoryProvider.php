<?php

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to expose "every currently
 * eligible staff member, across every section" — without core depending
 * on the module directly. Same precedent as Core\Module\
 * SectionResponsableProvider / HomeBannerProvider / HomeNewsProvider
 * (ARCHITECTURE.md §7.4). The trombinoscope module implements this using
 * its existing chief/admin-role eligibility query.
 *
 * Backs the offline photo pre-download (Lot 3,
 * Core\Http\Controller\OfflineController) — the one, narrow reason core
 * needs to know "who is staff" at all: it's the authorization boundary
 * for the photo-thumbnail route, not just a listing convenience.
 */
interface StaffDirectoryProvider
{
    /**
     * Persistent members.id values of every member currently eligible as
     * staff (any section) for the given scout year.
     *
     * @return int[]
     */
    public function getAllEligibleStaffMemberIds(int $scoutYearId): array;
}
