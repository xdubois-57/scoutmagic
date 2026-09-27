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
 * **Three methods, and the differences between them are permissions.**
 *
 * - {@see resolveMainSectionCode()} is the rule alone: among the members
 *   linked to an address, the highest-role one, then that member's main
 *   function's section, as a Desk code. It consults no list, so it cannot
 *   be narrowed by one. **A caller deciding ACCESS uses this**, and
 *   resolves the code itself through `SectionService::findByDeskCode()`,
 *   which filters on neither `is_active` nor `is_visible`.
 * - {@see resolveMainSection()} resolves that code against the sections a
 *   screen offers, and returns null when it is not among them.
 * - {@see resolveDefault()} does the same and then falls back to the first
 *   available section — right for "which tab opens", since an empty screen
 *   helps nobody.
 *
 * The fallback picks a section the reader has nothing to do with, so
 * granting rights from it would hand a random section's staff the data of
 * children they do not follow: the trap issue #650 named before reusing
 * this class for the carpools' section.
 *
 * And the offered list is a second trap, subtler, found in review of that
 * same issue: `getAllWithBranches()` — what every picker passes here —
 * drops inactive AND hidden sections, while
 * `SectionStaffAuthorizationService::getStaffedSections()` does NOT, going
 * through `getSection()`. A chief therefore staffs a hidden section that
 * this list never mentions, so resolving access against the list would
 * silently deny that section's staff what the rule grants them. Hence the
 * first method, which hands back a code and lets the caller choose the
 * lookup its purpose deserves.
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
     * The Desk code of the reader's OWN section: among the members linked to
     * their address, the one with the highest role, then the section of that
     * member's main function. Null when the address is linked to no member,
     * or when that member has no main function naming a section.
     *
     * **This is the form a caller deciding access should ask for**, because
     * it consults no list of sections and therefore cannot be narrowed by
     * one that hides some (see the class docblock). Resolve it with
     * `SectionService::findByDeskCode()`.
     *
     * Null is an answer, never a reason to substitute another section
     * (issue #650).
     *
     * @param MemberProfile[] $linkedMembers
     */
    public static function resolveMainSectionCode(array $linkedMembers): ?string
    {
        if ($linkedMembers === []) {
            return null;
        }

        $bestMember = MemberService::getHighestRoleMember($linkedMembers);

        return $bestMember?->getMainFunction()?->sectionCode;
    }

    /**
     * The reader's own section as an id, among those a screen offers — null
     * when they have none, or when theirs is not one of them.
     *
     * The « not among them » case is why this must not decide access: the
     * list a picker passes has already dropped the inactive and hidden
     * sections. {@see resolveMainSectionCode()} is the one for that.
     *
     * @param MemberProfile[] $linkedMembers
     * @param array<int, array{id: int, desk_code: string}> $availableSections
     */
    public static function resolveMainSection(array $linkedMembers, array $availableSections): ?int
    {
        $code = self::resolveMainSectionCode($linkedMembers);
        if ($code === null) {
            return null;
        }

        foreach ($availableSections as $section) {
            if ($section['desk_code'] === $code) {
                return $section['id'];
            }
        }

        return null;
    }
}
