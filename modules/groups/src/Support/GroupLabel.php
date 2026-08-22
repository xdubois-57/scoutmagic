<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

use Modules\Groups\Repository\DiscussionGroup;

/**
 * How a group is NAMED on screen: its own name, plus the scout year in
 * parentheses when it is tied to the year currently in effect.
 *
 *     Louveteaux (2025-2026)
 *
 * A section group is named after its section alone ("Louveteaux") and a
 * unit runs one per year, so the name by itself says nothing about which
 * year's conversation is being read — while the archives tab, the group
 * list and a search result routinely put two of them side by side.
 *
 * Only for the CURRENT year, deliberately. A past-year group is already
 * marked as an archive everywhere it appears (its own badge, its
 * read-only header), and repeating a year there would say twice what the
 * badge already says once. A group tied to no year at all — an invitation
 * group meant to outlive a school year — gets nothing, because there is
 * nothing true to add.
 *
 * One place, called from the Controllers rather than reimplemented in
 * Twig, so the page title, the breadcrumb, the header and the list can
 * never start naming the same group differently.
 */
final class GroupLabel
{
    public static function withYear(DiscussionGroup $group, int $effectiveScoutYearId, string $effectiveScoutYearLabel): string
    {
        if ($group->scoutYearId === null || $group->scoutYearId !== $effectiveScoutYearId) {
            return $group->name;
        }

        $label = trim($effectiveScoutYearLabel);
        if ($label === '' || str_contains($group->name, $label)) {
            // A group a chief already named "Louveteaux 2025-2026" says
            // it once, not twice.
            return $group->name;
        }

        return $group->name . ' (' . $label . ')';
    }
}
