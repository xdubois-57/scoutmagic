<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * One visible section with active members in a given scout year, and how
 * many of them staff it and how many are animés —
 * SectionService::getSectionHeadcountsForYear().
 *
 * The two counts follow the site's one definition of each: animateur =
 * a function of role `chief` or `admin` in the section (the same rule as
 * getSectionStaff() and the trombinoscope), animé = any role but those and
 * `intendant` (getSectionAnimes()). An intendant alone makes a section
 * present with both counts at zero.
 */
final class SectionYearHeadcount
{
    public function __construct(
        public readonly int $sectionId,
        public readonly string $deskCode,
        /** The section's own name, or its Desk code when it has none. */
        public readonly string $label,
        public readonly int $branchSortOrder,
        /** The « Staff d'U » section (UnitStaffSectionService::DESK_CODE). */
        public readonly bool $isUnitStaff,
        public readonly int $chiefCount,
        public readonly int $animeCount
    ) {
    }
}
