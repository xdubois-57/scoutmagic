<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * One member of a scout year, reduced to what a picker needs to show and to
 * match on — and to nothing else.
 *
 * Deliberately **not** a MemberProfile. A profile carries the addresses, the
 * phone numbers, the email, the handicap field and the badges, resolved
 * through several queries per member; a search-as-you-type box over the whole
 * roster needs a name, a totem and a section, and handing it the rest would
 * be decrypting a unit's entire personal-data table on every keystroke.
 *
 * **No birth date, on purpose.** The age filter belongs to
 * MemberService::findDirectoryForYear(), which applies it while it still has
 * the decrypted value and drops it before returning — so a caller can ask for
 * "sixteen and over" without ever being handed a date of birth it has no use
 * for (ARCHITECTURE.md §7.6's "build only what the viewer may see", applied
 * one layer lower).
 */
class MemberDirectoryEntry
{
    public function __construct(
        /** The PERSISTENT members.id, never member_years.id — a grant made against it outlives the scout year. */
        public readonly int $memberId,
        /** `totem ?? first_name`, §4's convention, resolved once here. */
        public readonly string $displayName,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        /** The member's main function's section, when they have one. */
        public readonly ?string $sectionName,
        /** The main function's own label ("Animateur Louveteaux", "Chef d'Unité"). */
        public readonly ?string $functionLabel
    ) {
    }

    /**
     * Family name first, because that is how somebody looks a person up in a
     * roster, with the totem appended when there is one so two people who
     * share a first name are told apart.
     */
    public function label(): string
    {
        $name = trim($this->lastName . ' ' . $this->firstName);
        if ($name === '') {
            $name = $this->displayName;
        }

        return $this->totem !== null && $this->totem !== ''
            ? $name . ' (' . $this->totem . ')'
            : $name;
    }

    /**
     * The section and function, for the second line of a search result —
     * never an email address. Empty when the member has neither.
     */
    public function sublabel(): string
    {
        $parts = array_values(array_filter([
            $this->sectionName,
            $this->functionLabel,
        ], static fn(?string $part) => $part !== null && $part !== ''));

        return implode(' — ', $parts);
    }

    /**
     * Whether this entry matches a typed query, on the same accent-sensitive
     * case-insensitive substring rule Modules\Groups' own invite search uses:
     * simple enough to be predictable, and matching the family name, the
     * first name and the totem alike so a search works whichever one the
     * person typing happens to remember.
     */
    public function matches(string $query): bool
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return false;
        }

        foreach ([$this->firstName, $this->lastName, $this->totem] as $haystack) {
            if ($haystack !== null && $haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
