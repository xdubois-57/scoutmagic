<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * One branch's configured age bracket: the age reached at year_in_branch 1,
 * and how many years the branch spans. Module-owned settings — see
 * schema.sql's registration_age_brackets comment for why this is never
 * Core\Member\MemberYearService::BRANCHES reused directly.
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
