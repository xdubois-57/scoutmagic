<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * What is true of a section's staff beyond what Desk exports: who is its
 * responsable, and who carries which badge.
 *
 * **The responsable is not a column, it is a flag on a FONCTION.** The
 * trombinoscope promotes whoever holds a function marked `is_lead` in
 * `trombinoscope_function_flags` (Modules\Trombinoscope\Repository\
 * FunctionFlagsRepository) to the top of the section's staff. The dataset
 * therefore does two things that only make sense together: the generator
 * gives exactly one cadre per section per year the `Chef de section`
 * function (PopulationBuilder::designateSectionLeads()), and StaffSeeder
 * flags that function. Either half alone is invisible — an unflagged
 * function is an ordinary animateur, and a flag on a function nobody holds
 * leaves every section without a responsable.
 *
 * **The badges are a rule, not a list.** "Every section has a treasurer and a
 * first-aider" is a sentence about a unit, and writing it out is one row per
 * section per year — twenty-one of them, in which nothing says what the rule
 * was. So SECTION_BADGES names the two badges and StaffSeeder hands them
 * out; PINNED_BADGES keeps the handful that are about *named people* and
 * therefore genuinely belong in a table.
 */
final class StaffBlueprint
{
    /**
     * Badges given to one cadre of every section, every year, by
     * StaffSeeder::assignSectionBadges(). Both are seeded by
     * Core\Badge\BadgeService::ensureDefaults(), so neither is created here.
     *
     * The order matters: the first goes to the first cadre of the section
     * (by member id), the second to the next one. A section with a single
     * cadre gets both on the same person, which is what happens in a small
     * section and is worth having in the data.
     *
     * @var list<string>
     */
    public const SECTION_BADGES = ['Trésorier', 'Infirmier'];

    /**
     * Badges pinned on named members, on top of the per-section rule.
     *
     * `T0017` carries Trésorier across all three years — the continuity case,
     * and the one the badge filter on the member list is worth trying on.
     *
     * @var list<array{tiers: string, year: string, badge: string}>
     */
    public const PINNED_BADGES = [
        ['tiers' => 'T0017', 'year' => '2024-2025', 'badge' => 'Trésorier'],
        ['tiers' => 'T0017', 'year' => '2025-2026', 'badge' => 'Trésorier'],
        ['tiers' => 'T0017', 'year' => '2026-2027', 'badge' => 'Trésorier'],
        ['tiers' => 'T0014', 'year' => '2025-2026', 'badge' => 'Infirmier'],
        ['tiers' => 'T0018', 'year' => '2026-2027', 'badge' => 'Infirmier'],
    ];
}
