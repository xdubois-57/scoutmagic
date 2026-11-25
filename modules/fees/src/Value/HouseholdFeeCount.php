<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Core\Member\HouseholdFeeCategory;

/** A range, because an unproven guest contributes either zero or one. */
final readonly class HouseholdFeeCount
{
    public function __construct(public int $minimum, public int $maximum)
    {
    }

    public function category(): ?HouseholdFeeCategory
    {
        if ($this->minimum === 0) {
            return null;
        }
        $lowest = HouseholdFeeCategory::fromHouseholdSize($this->minimum);

        return $lowest === HouseholdFeeCategory::fromHouseholdSize($this->maximum) ? $lowest : null;
    }
}
