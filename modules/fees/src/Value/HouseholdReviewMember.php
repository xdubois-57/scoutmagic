<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Core\Member\HouseholdFeeCategory;

/**
 * One line of a household card: who, what Desk has them on, and what the
 * household's size implies they should be on.
 *
 * $encodedCategory is null in two different situations the card keeps
 * apart: Desk holds no fee category at all, or it holds one that is not a
 * household tariff ("Tarif animateur", "Tarif réduit", an iAM membership).
 * $comparable says which — a member outside the three tariffs is not
 * compared, and above all is never reported as wrong.
 */
final class HouseholdReviewMember
{
    public function __construct(
        public readonly int $memberId,
        public readonly int $memberYearId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly ?int $encodedFeeCategoryId,
        public readonly ?string $encodedFeeCategoryLabel,
        public readonly ?HouseholdFeeCategory $encodedCategory,
        public readonly bool $comparable,
        public readonly bool $leaving,
        public readonly ?string $leavingMarkedAt
    ) {
    }

    public function matches(HouseholdFeeCategory $expected): bool
    {
        return $this->encodedCategory === $expected;
    }

    /**
     * What `|display_name_full` needs, without this module inventing a name
     * rule of its own (AGENTS.md § Display name convention).
     *
     * @return array{first_name: string, last_name: string, totem: ?string}
     */
    public function nameParts(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'totem' => $this->totem,
        ];
    }
}
