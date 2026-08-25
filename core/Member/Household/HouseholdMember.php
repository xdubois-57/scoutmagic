<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Household;

/**
 * One member_year sharing a household's normalized address blind index
 * (ARCHITECTURE.md §8.34). Ids and one flag, nothing else: a household is
 * a counting question, and a caller that needs a name reads it from
 * member_years through its own repository.
 *
 * $leaving is the member_years.leaving flag as it stands, NOT a filter:
 * Desk still bills this person, and the projected count is what stops
 * doing so. Keeping the flag rather than applying it is what lets one
 * enumeration answer both questions.
 */
final class HouseholdMember
{
    public function __construct(
        public readonly int $memberYearId,
        public readonly int $memberId,
        public readonly bool $leaving
    ) {
    }
}
