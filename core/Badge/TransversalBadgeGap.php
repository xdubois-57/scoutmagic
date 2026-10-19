<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

/**
 * A badge with no referent section, worn in some of the sections a caller
 * looked at and not in the others — one entry of
 * BadgeYearAggregate::$transversalGaps. A remark, never a verdict: a
 * section may have no use for « Infirmier » this year.
 */
final class TransversalBadgeGap
{
    /**
     * @param list<int> $sectionIdsWith the sections where somebody wears it, in the order asked
     * @param list<int> $sectionIdsWithout the sections where nobody does, in the order asked
     */
    public function __construct(
        public readonly Badge $badge,
        public readonly array $sectionIdsWith,
        public readonly array $sectionIdsWithout
    ) {
    }
}
