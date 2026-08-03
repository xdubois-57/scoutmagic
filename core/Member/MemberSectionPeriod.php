<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * A single open-or-closed section-membership window — see
 * schema/core.sql's member_section_periods comment.
 */
class MemberSectionPeriod
{
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly int $sectionId,
        public readonly int $scoutYearId,
        public readonly string $startDate,
        public readonly ?string $endDate
    ) {
    }

    public function isOpen(): bool
    {
        return $this->endDate === null;
    }

    /**
     * Whether $date (Y-m-d) falls within this period, inclusive.
     */
    public function covers(string $date): bool
    {
        if ($date < $this->startDate) {
            return false;
        }

        return $this->endDate === null || $date <= $this->endDate;
    }
}
