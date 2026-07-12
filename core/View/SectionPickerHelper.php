<?php

declare(strict_types=1);

namespace Core\View;

use Core\Member\MemberProfile;
use Core\Security\Role;

class SectionPickerHelper
{
    /**
     * Determine the default section to display.
     *
     * Logic:
     * 1. If a section ID is provided in the query string → use it (if it exists in available sections).
     * 2. If the user has linked members:
     *    a. Find the member with the highest role.
     *    b. Get their main function's section.
     *    c. Use that section.
     * 3. If no linked members or no section → use the first section.
     *
     * @param MemberProfile[] $linkedMembers
     * @param array<int, array{id: int, desk_code: string}> $availableSections
     */
    public static function resolveDefault(
        ?int $requestedSectionId,
        array $linkedMembers,
        array $availableSections
    ): ?int {
        if (count($availableSections) === 0) {
            return null;
        }

        $availableIds = array_map(fn(array $s) => $s['id'], $availableSections);

        // 1. Use requested section if valid
        if ($requestedSectionId !== null && in_array($requestedSectionId, $availableIds, true)) {
            return $requestedSectionId;
        }

        // 2. Find section of the highest-role linked member
        if (count($linkedMembers) > 0) {
            $bestMember = null;
            $bestLevel = -1;
            foreach ($linkedMembers as $member) {
                $mainFn = $member->getMainFunction();
                if ($mainFn !== null) {
                    $level = Role::fromString($mainFn->functionRole)->level();
                    if ($level > $bestLevel) {
                        $bestLevel = $level;
                        $bestMember = $member;
                    }
                }
            }

            if ($bestMember !== null) {
                $mainFn = $bestMember->getMainFunction();
                if ($mainFn !== null && $mainFn->sectionCode !== null) {
                    // Find the section ID by desk_code
                    foreach ($availableSections as $section) {
                        if ($section['desk_code'] === $mainFn->sectionCode
                            && in_array($section['id'], $availableIds, true)) {
                            return $section['id'];
                        }
                    }
                }
            }
        }

        // 3. Fallback to first available section
        return $availableSections[0]['id'];
    }
}
