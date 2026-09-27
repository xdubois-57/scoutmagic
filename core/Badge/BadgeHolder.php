<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

/**
 * One person wearing a badge in one scout year, as that year knew them —
 * name, section and function of THAT year, never of today's (issue #621).
 *
 * `memberYearId` is the member_year of the year read, and it is what
 * /admin/members/{id} takes: that route reads a member_year id and moves
 * onto the person's most recent year itself (MemberSearchController::show()),
 * so the link opens the person's own sheet — today's, even for somebody
 * who has left since. A `members.id` in that place would open whichever
 * member_year happens to carry the same number: somebody else.
 */
final class BadgeHolder
{
    public function __construct(
        public readonly int $memberYearId,
        public readonly string $name,
        public readonly ?string $sectionName,
        public readonly ?string $functionLabel
    ) {
    }
}
