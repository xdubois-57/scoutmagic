<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * Result of MemberYearService::getEffectiveAge(): a member's effective age and,
 * when that age falls within one of the four Les Scouts "animés" branches, the
 * branch and the 1-based year within that branch.
 *
 * Branch fields are null when the effective age (or the birth year it was
 * computed from) is unknown or falls outside every branch range — e.g. staff
 * members, or a member with no birth date on file.
 */
final class EffectiveAge
{
    public function __construct(
        public readonly ?int $age,
        public readonly ?string $branchKey,
        public readonly ?string $branchName,
        public readonly ?string $branchColor,
        public readonly ?int $yearInBranch,
        public readonly ?int $totalYearsInBranch
    ) {
    }

    public function isInKnownBranch(): bool
    {
        return $this->branchKey !== null;
    }

    /**
     * French display label, e.g. "2e année louveteaux". Empty string when the
     * age isn't in any known branch.
     */
    public function getBranchYearLabel(): string
    {
        if ($this->branchName === null || $this->yearInBranch === null) {
            return '';
        }

        $ordinal = $this->yearInBranch === 1 ? '1ère année' : $this->yearInBranch . 'e année';

        return $ordinal . ' ' . mb_strtolower($this->branchName);
    }
}
