<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Core\Member\SectionStaffAuthorizationService;

/**
 * The module's one answer to « may this account read and write the
 * presences of this section ».
 *
 * **It derives nothing of its own.** The rule — who staffs which section,
 * with admin and superadmin reaching every section unconditionally —
 * already lives in `Core\Member\SectionStaffAuthorizationService` and
 * already backs the section documents, the calendar and the Départs page.
 * A second implementation would be a second answer waiting to disagree
 * with the first, and the direction it would disagree in is the one that
 * hands an animateur another section's animés, comments included.
 *
 * Two properties this class exists to guarantee, both tested:
 *
 * - **Rights are recomputed on every call, never memorised.** An
 *   animateur who leaves a section stops reaching it on their next
 *   request, with nothing to revoke — the same posture the personal ICS
 *   feed takes (Modules\Calendar\Service\PersonalFeedService).
 * - **Refusal is the default.** Every question is asked as « is this
 *   section in the set I staff », so an unknown role, an empty address or
 *   a section that no longer exists all answer no.
 */
class PresenceAuthorizationService
{
    public function __construct(
        private SectionStaffAuthorizationService $sectionStaffAuthorization
    ) {
    }

    /**
     * The sections this account may take attendance for, in the site's
     * usual branch-then-code order — the section picker's list, and the
     * set every other question here is asked against.
     *
     * @return array<int, array{
     *     id: int, desk_code: string, name: ?string, email: ?string,
     *     age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string
     * }>
     */
    public function staffedSections(string $email, string $role, int $scoutYearId): array
    {
        if (trim($email) === '') {
            return [];
        }

        return $this->sectionStaffAuthorization->getStaffedSections($email, $role, $scoutYearId);
    }

    /**
     * @return list<int>
     */
    public function staffedSectionIds(string $email, string $role, int $scoutYearId): array
    {
        return array_values(array_map(
            static fn(array $section): int => (int) $section['id'],
            $this->staffedSections($email, $role, $scoutYearId)
        ));
    }

    public function maySeeSection(string $email, string $role, int $scoutYearId, int $sectionId): bool
    {
        return in_array($sectionId, $this->staffedSectionIds($email, $role, $scoutYearId), true);
    }
}
