<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Service;

/**
 * One PERSON in a search result, not one annual membership row.
 *
 * A child present in the unit for five years has five `member_years`
 * rows, and a search widened to past years would otherwise return five
 * near-identical lines for them. Grouping is on `members.id` — the
 * persistent identity — and never on a name: two children can share one,
 * and the same person's name is re-imported from Desk every year.
 *
 * `$latest` is the most recent annual row found, and it is what the row
 * displays: someone looking up a former member wants their latest known
 * section, function and status, not whichever year the query happened to
 * match on.
 */
final class GroupedMemberSearchResult
{
    /**
     * @param string[] $scoutYearLabels every year this person was found in, most recent first
     */
    public function __construct(
        public readonly int $memberId,
        public readonly MemberSearchResult $latest,
        public readonly array $scoutYearLabels,
        // True when none of this person's matched years is the effective
        // one — a former member, surfaced only because the search was
        // widened. The row says so rather than looking like a current
        // member whose section happens to be blank.
        public readonly bool $isFormerMember
    ) {
    }

    /**
     * The years, as one line: a single year on its own, a span otherwise.
     * A person found in 2019, 2020 and 2021 reads "2019-2020 → 2021-2022",
     * not as three labels a phone cannot fit.
     */
    public function yearsSummary(): string
    {
        if ($this->scoutYearLabels === []) {
            return '';
        }
        if (count($this->scoutYearLabels) === 1) {
            return $this->scoutYearLabels[0];
        }

        $oldest = $this->scoutYearLabels[count($this->scoutYearLabels) - 1];

        return $oldest . ' → ' . $this->scoutYearLabels[0];
    }
}
