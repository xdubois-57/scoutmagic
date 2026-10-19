<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

/**
 * The badges of one scout year, filed by section — what the "Année scoute"
 * workflow's badges step shows (chantier « Année scoute » §2.6), built by
 * BadgeHolderService::sectionAggregateForYear().
 *
 * Informative only: nothing here says a badge is required anywhere, and
 * nothing compares with the year before.
 *
 * **Where a holding is filed.** A transversal badge (no referent section)
 * goes under the section its holder belongs to THAT year — their main
 * function's section, then the first one declared. A referent badge
 * (« Référent {section} ») goes under the Staff d'U, whose members wear it,
 * and never under the section it names: that section never lacks it.
 */
final class BadgeYearAggregate
{
    /**
     * @param array<int, list<BadgeHolders>> $bySection section id => the badges
     *        worn there, holders included; every section asked for is a key
     *        (possibly empty), in the order asked, and the Staff d'U too
     *        whenever it exists
     * @param list<BadgeHolders> $withoutSection holdings whose holder has no
     *        function in a section that year (or a referent badge on a site
     *        with no Staff d'U section yet)
     * @param list<Badge> $defaultBadgesUnworn active default badges (not
     *        referent ones) that nobody wears in any section that year
     * @param list<TransversalBadgeGap> $transversalGaps transversal badges
     *        worn in some of the sections asked for and not in others
     */
    public function __construct(
        public readonly array $bySection,
        public readonly array $withoutSection,
        public readonly array $defaultBadgesUnworn,
        public readonly array $transversalGaps
    ) {
    }
}
