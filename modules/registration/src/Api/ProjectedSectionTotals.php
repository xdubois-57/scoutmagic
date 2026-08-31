<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * What one section is projected to hold, and how that number breaks down.
 *
 * The counts are derived from exactly the `ProjectedPerson` list the same
 * provider returns, so a consumer that adds the people up itself gets the
 * same figures. That is why this exists at all rather than being left to
 * each consumer: two modules counting the same list two ways is two
 * answers to one question.
 */
final class ProjectedSectionTotals
{
    /**
     * @param array<int, int> $byYearInBranch rank (1-based) => headcount;
     *        ranks nobody occupies are absent, not zero
     * @param array{male: int, female: int, other: int, total: int} $gender
     */
    public function __construct(
        public readonly int $sectionId,
        public readonly string $label,
        public readonly int $total,
        /** Real Desk rows in the target year. */
        public readonly int $certainTotal,
        /** Everything still only planned: passages, acceptances, continuations. */
        public readonly int $hypothesisTotal,
        public readonly array $byYearInBranch,
        public readonly array $gender
    ) {
    }
}
