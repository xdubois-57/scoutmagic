<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Pdf;

/**
 * One section, as the printed sheet draws it: its name, its colour, and
 * the two groups that are called at a passage — animateurs, then animés.
 *
 * **No intendants.** They do not take part in the ceremony, and adding
 * them would lengthen a sheet somebody holds in one hand. This is an
 * exclusion belonging to the document only: they stay on the screen and
 * in the spreadsheet export, and nothing here decides anything about the
 * page.
 *
 * A member holding two functions appears in one group and never in both.
 * The page already resolves that (Core\Member\SectionRosterEntry's own
 * bucketing, one bucket per (section, member_year)), so the document
 * inherits the split instead of arbitrating it a second time and possibly
 * differently.
 */
final class RosterSectionView
{
    /**
     * @param string $color always resolved through Core\Member\
     *        SectionService::colorForSection() — never re-derived from the
     *        branch here. A colour set by hand in Configuration > Config
     *        Desk wins over the branch's, the Staff d'Unité has its own,
     *        and the value is validated as `#RRGGBB` at write time so
     *        every consumer can rely on it. Recomputing would print a
     *        different colour from the one on screen for any unit that has
     *        customised a section.
     * @param RosterMemberView[] $leaders the section's animateurs
     * @param RosterMemberView[] $youthMembers the section's animés
     *
     * The two groups are named in English like every other identifier in
     * this codebase (AGENTS.md § Language), even though the sheet prints
     * their headings as « Animateurs » and « Animés » and the roster's own
     * array keys — pre-existing data shape, not identifiers introduced
     * here — still spell them in French.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $branchName,
        public readonly string $color,
        public readonly array $leaders,
        public readonly array $youthMembers
    ) {
    }

    /**
     * How many of the people on this sheet are not simply continuing —
     * the figure a staff checks before calling the first name, and the
     * reason the header carries a count at all.
     */
    public function notableCount(): int
    {
        $count = 0;
        foreach (array_merge($this->leaders, $this->youthMembers) as $member) {
            if ($member->movement->isNotable()) {
                $count++;
            }
        }

        return $count;
    }

    /** The longest of the two groups — what decides how tightly the sheet packs. */
    public function largestGroupSize(): int
    {
        return max(count($this->leaders), count($this->youthMembers));
    }
}
