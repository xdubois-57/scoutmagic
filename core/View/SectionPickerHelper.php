<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Member\MemberProfile;
use Core\Member\MemberService;

/**
 * Which section a screen starts on, for a reader who has not chosen one.
 *
 * **Two methods, and the difference between them is a permission.**
 * {@see resolveDefault()} always names a section when the site has one,
 * falling back to the first available — right for "which tab opens", since
 * an empty screen helps nobody. {@see resolveMainSection()} answers the
 * narrower question "which section is THIS reader's own" and returns null
 * rather than guess.
 *
 * Only the second may decide access. The fallback picks a section the
 * reader has nothing to do with, so granting rights from it would hand a
 * random section's staff the data of people they do not follow — the trap
 * issue #650 named before reusing this class for the carpools' section.
 */
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
     * Step 3 is a DISPLAY convenience and nothing more: see the class
     * docblock before reusing this to decide what somebody may see.
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

        // 1. Use requested section if valid
        if ($requestedSectionId !== null) {
            foreach ($availableSections as $section) {
                if ($section['id'] === $requestedSectionId) {
                    return $requestedSectionId;
                }
            }
        }

        // 2. The reader's own section, when they have one
        $own = self::resolveMainSection($linkedMembers, $availableSections);
        if ($own !== null) {
            return $own;
        }

        // 3. Fallback to first available section
        return $availableSections[0]['id'];
    }

    /**
     * The reader's OWN section: among the members linked to their address,
     * the one with the highest role, then the section of that member's main
     * function. Null when the address is linked to no member, when that
     * member has no main function, or when the function names a section the
     * caller did not offer.
     *
     * Null is an answer, never a reason to substitute another section: this
     * method exists so that a caller deciding access has one it can trust
     * (issue #650).
     *
     * @param MemberProfile[] $linkedMembers
     * @param array<int, array{id: int, desk_code: string}> $availableSections
     */
    public static function resolveMainSection(array $linkedMembers, array $availableSections): ?int
    {
        if ($linkedMembers === [] || $availableSections === []) {
            return null;
        }

        $bestMember = MemberService::getHighestRoleMember($linkedMembers);
        if ($bestMember === null) {
            return null;
        }

        $mainFn = $bestMember->getMainFunction();
        if ($mainFn === null || $mainFn->sectionCode === null) {
            return null;
        }

        foreach ($availableSections as $section) {
            if ($section['desk_code'] === $mainFn->sectionCode) {
                return $section['id'];
            }
        }

        return null;
    }
}
