<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Security\Role;

/**
 * Whose contact details a page of the chiefs' area may show this viewer —
 * the one rule « Staffs » (StaffsController) and « Animés de la section »
 * (SectionRosterController, on screen and in its export) answer with
 * (issue #1116, SECURITY.md §3).
 *
 * - `chief` and above: every section, as before — a chief reads any
 *   member's record (MemberService::canAccess()).
 * - below (`intendant`): only the sections in which one of the viewer's
 *   linked members — themselves, their children — holds a function this
 *   year. An intendant linked to nobody in a section sees its people
 *   listed, never how to reach them.
 *
 * Names, functions and sections stay visible either way: they are what the
 * roll-call sheet prints too. What is withheld is how to reach a person —
 * e-mail addresses, telephone numbers, the postal address — and the birth
 * date.
 *
 * Built per request, from the linked members of the year the page shows.
 */
final class SectionContactAccess
{
    /**
     * @param array<string, true>|null $sectionCodes null: every section
     */
    private function __construct(private readonly ?array $sectionCodes)
    {
    }

    /**
     * @param MemberProfile[] $linkedMembers MemberService::getLinkedMembers()
     *        for the viewer, in the year the page shows
     */
    public static function forViewer(Role $role, array $linkedMembers): self
    {
        if ($role->hasAccess(Role::CHIEF)) {
            return new self(null);
        }

        return new self(array_fill_keys(self::linkedSectionCodes($linkedMembers), true));
    }

    /** Whether this viewer is shown the contact details of $sectionDeskCode's members. */
    public function seesContactsOf(?string $sectionDeskCode): bool
    {
        if ($this->sectionCodes === null) {
            return true;
        }

        return $sectionDeskCode !== null && isset($this->sectionCodes[$sectionDeskCode]);
    }

    /** Whether this viewer is shown every section's contact details. */
    public function seesEverySection(): bool
    {
        return $this->sectionCodes === null;
    }

    /**
     * The desk codes of the sections the linked members hold a function in.
     *
     * @param MemberProfile[] $linkedMembers
     * @return list<string>
     */
    public static function linkedSectionCodes(array $linkedMembers): array
    {
        // A list, never array keys: a numeric desk code would come back
        // as an int key and miss a strict comparison.
        $codes = [];
        foreach ($linkedMembers as $member) {
            foreach ($member->functions as $function) {
                if ($function->sectionCode !== null && !in_array($function->sectionCode, $codes, true)) {
                    $codes[] = $function->sectionCode;
                }
            }
        }

        return $codes;
    }
}
