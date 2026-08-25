<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees;

use Core\Member\HouseholdFeeCategory;

/**
 * The French word for each household tariff, in one place.
 *
 * Three screens, a clipboard block and a spreadsheet export all name these
 * three categories; three copies of a ternary is how "Famille" becomes
 * "famille" on one of them.
 */
final class HouseholdCategoryLabel
{
    private const LABELS = [
        'normal' => 'Normal',
        'couple' => 'Couple',
        'family' => 'Famille',
    ];

    public static function for(HouseholdFeeCategory $category): string
    {
        return self::LABELS[$category->value];
    }

    /** @return array<string, string> for a template that needs the three at once */
    public static function all(): array
    {
        return self::LABELS;
    }
}
