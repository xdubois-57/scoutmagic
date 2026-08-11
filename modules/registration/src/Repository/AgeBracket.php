<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * One branch's age bracket: the age reached at year_in_branch 1, and how
 * many years the branch spans. Read-only reflection of Core\Member\
 * MemberYearService::BRANCHES (the same federation age ranges
 * member_stats already uses) for the branch matching this bracket's
 * branchSortOrder — never a second, independently admin-configurable copy
 * of those numbers. See Repository\AgeBracketRepository for where these
 * values actually come from.
 */
final class AgeBracket
{
    public function __construct(
        public readonly int $ageBranchId,
        public readonly string $branchLabel,
        public readonly int $branchSortOrder,
        public readonly int $entryAge,
        public readonly int $durationYears
    ) {
    }

    /**
     * The age reached at a given year within this branch (1-based).
     */
    public function ageForYearInBranch(int $yearInBranch): int
    {
        return $this->entryAge + $yearInBranch - 1;
    }

    public function lastYearInBranch(): int
    {
        return $this->durationYears;
    }
}
