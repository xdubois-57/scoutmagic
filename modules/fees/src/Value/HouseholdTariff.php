<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Core\Member\HouseholdFeeCategory;

/**
 * One line of the barème: which Desk fee category this unit uses for a
 * household tariff, and what one person on it costs.
 *
 * Both halves are optional and mean different things when absent.
 * $feeCategoryId absent means "nobody overrode the heuristic", not "no
 * category" — `Modules\Fees\Service\HouseholdTariffService` falls back to
 * `FeeCategoryClassifier`. $amountCents absent means the discrepancy is
 * shown without a figure, never with a zero.
 */
final class HouseholdTariff
{
    public function __construct(
        public readonly HouseholdFeeCategory $category,
        public readonly ?int $feeCategoryId,
        public readonly ?int $amountCents
    ) {
    }
}
