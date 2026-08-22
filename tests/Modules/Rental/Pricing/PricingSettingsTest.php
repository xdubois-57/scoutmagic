<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Pricing;

use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PricingSettings;
use PHPUnit\Framework\TestCase;

/**
 * hasAnyRate() is what separates "tarif sur demande" from a real estimate on
 * the public page, and what raises the "tarif manquant" warning in the
 * managed and admin spaces — a predicate three surfaces rely on agreeing.
 */
class PricingSettingsTest extends TestCase
{
    public function testNoRateAtAllMeansNoRate(): void
    {
        $settings = new PricingSettings(BillingUnit::PER_PERSON_NIGHT);

        $this->assertFalse($settings->hasAnyRate());
    }

    public function testADefaultRateAloneIsARate(): void
    {
        $settings = new PricingSettings(BillingUnit::PER_PERSON_NIGHT, defaultUnitPriceCents: 550);

        $this->assertTrue($settings->hasAnyRate());
    }

    public function testASingleGridCellAloneIsARate(): void
    {
        $settings = new PricingSettings(
            BillingUnit::PER_NIGHT,
            priceGridCents: [PricingSettings::gridKey(null, null) => 8000]
        );

        $this->assertTrue($settings->hasAnyRate());
    }

    public function testAMinimumOrFeesWithoutARateAreStillNoRate(): void
    {
        // A cleaning fee or a billable minimum prices nothing by itself: the
        // base line needs a unit price, and without one every quote warns.
        $settings = new PricingSettings(
            BillingUnit::PER_PERSON_NIGHT,
            minimumPersons: 25,
            fees: []
        );

        $this->assertFalse($settings->hasAnyRate());
    }
}
