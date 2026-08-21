<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * One renter category on the price grid's second axis — youth movement,
 * non-profit, private individual, company, internal… Configurable per
 * installation rather than hardcoded: the list varies between units, and a
 * unit that invents a category must not need a release to use it.
 *
 * Carries no price of its own. A category is one axis of the grid; the price
 * lives in the cell (PricingSettings::$priceGridCents).
 */
final class RenterCategory
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        /**
         * Offered as the pre-selected choice on the public request form.
         * A convenience, never a permission: the price a visitor is shown is
         * always recomputed server-side from the category actually recorded.
         */
        public readonly bool $isDefault = false
    ) {
    }
}
