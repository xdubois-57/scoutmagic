<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;

/**
 * Who the Desk ids stored in a « Membres » answer designate today
 * (MemberAnswer), for every screen that reads one back: the responses
 * list, the export, the mail merge, the confirmation page and e-mail, and
 * the edit form's pre-selection.
 *
 * **By identity, never by name.** A code is resolved the way the Desk
 * import resolves it — `Core\Import\MemberRepository::findByDeskIds()`,
 * the grouped `findByDeskId()`: the member it is, or, once that record
 * was merged into another, the member the merge kept, through
 * `member_desk_id_aliases`. Nothing is ever inferred from a name.
 *
 * **The member's current identity**: the label is read from their newest
 * annual record, so a totem added in Desk shows on an answer given
 * before it, and a member who has since left the unit is still named by
 * the last name the unit knew. A code that designates nobody is absent
 * from the result, and each caller shows MemberAnswer::UNAVAILABLE_LABEL
 * in its place — never somebody else's name.
 *
 * Two queries for the codes, one for the years and the roster
 * hydration's handful, whatever the number of codes: a page of fifty
 * responses costs what one does.
 */
class MemberAnswerResolver
{
    public function __construct(
        private MemberRepository $members,
        private MemberYearRepository $memberYears,
        private SectionService $sectionService
    ) {
    }

    /**
     * @param string[] $deskIds codes as stored
     * @return array<string, MemberOption> code as given => the member it designates now
     */
    public function resolve(array $deskIds): array
    {
        $found = $this->members->findByDeskIds($deskIds);
        if ($found === []) {
            return [];
        }

        $memberYearIds = $this->memberYears->findMostRecentIdsForMembers(
            array_map(static fn(array $member): int => $member['id'], $found)
        );
        $profiles = $this->sectionService->hydrateMemberProfiles(array_values($memberYearIds));

        $resolved = [];
        foreach ($found as $deskId => $member) {
            $memberYearId = $memberYearIds[$member['id']] ?? null;
            $profile = $memberYearId !== null ? ($profiles[$memberYearId] ?? null) : null;
            if ($profile === null) {
                continue;
            }
            $resolved[$deskId] = new MemberOption($profile->memberYearId, $profile->deskId, $profile->getDisplayNameFull());
        }

        return $resolved;
    }
}
