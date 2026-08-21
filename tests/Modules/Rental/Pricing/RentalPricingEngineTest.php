<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Pricing;

use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PricePeriod;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Pricing\RenterCategory;
use PHPUnit\Framework\TestCase;

/**
 * The pricing engine is pure, so these tests are the specification: every
 * billing unit, the minimum in both of its forms triggered and untriggered,
 * every fee nature, the categories, stock quantity, date edge cases, leap
 * years, and strict agreement between the nights and full-days models.
 *
 * Money is in cents throughout, as integers. A price is never a float.
 */
class RentalPricingEngineTest extends TestCase
{
    private RentalPricingEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RentalPricingEngine();
    }

    private function settings(
        BillingUnit $unit,
        ?int $unitPriceCents = 250,
        ?int $minimumAmountCents = null,
        ?int $minimumPersons = null,
        array $fees = [],
        array $periods = [],
        array $categories = [],
        array $grid = []
    ): PricingSettings {
        return new PricingSettings(
            billingUnit: $unit,
            periods: $periods,
            categories: $categories,
            priceGridCents: $grid,
            defaultUnitPriceCents: $unitPriceCents,
            minimumAmountCents: $minimumAmountCents,
            minimumPersons: $minimumPersons,
            fees: $fees
        );
    }

    private function baseLine(\Modules\Rental\Pricing\PriceQuote $quote): ?PriceLine
    {
        foreach ($quote->lines as $line) {
            if ($line->rule === PriceLine::RULE_BASE) {
                return $line;
            }
        }

        return null;
    }

    // ── Scenario E from the module spec §9 ──────────────────────────────

    public function testSpecScenarioEProducesTheDocumentedBreakdown(): void
    {
        // 3 nights, 38 people, youth movement, 2,50 €/person/night,
        // minimum 25 people, cleaning 50 €, tax 1 €/person.
        $settings = $this->settings(
            BillingUnit::PER_PERSON_NIGHT,
            unitPriceCents: 250,
            minimumPersons: 25,
            fees: [
                new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000),
                new RentalFee(2, 'Taxe de séjour', RentalFee::NATURE_PER_PERSON, 100),
            ]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 38));

        $this->assertSame(3, $quote->nights);
        $this->assertSame(114, $quote->quantity, '38 people × 3 nights');
        $this->assertFalse($quote->minimumApplied, '38 is above the 25-person floor.');

        $base = $this->baseLine($quote);
        $this->assertNotNull($base);
        $this->assertSame('38 pers. × 3 nuits', $base->label);
        $this->assertSame(28500, $base->amountCents);

        // 285,00 + 50,00 + 38,00 = 373,00
        $this->assertSame(37300, $quote->totalCents);
    }

    public function testSpecScenarioEStatesTheMinimumOutLoudBelowTheFloor(): void
    {
        // The same asset with 18 people: the line must SAY "25 pers.
        // (minimum)", never quietly correct the total upward.
        $settings = $this->settings(
            BillingUnit::PER_PERSON_NIGHT,
            unitPriceCents: 250,
            minimumPersons: 25,
            fees: [
                new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000),
                new RentalFee(2, 'Taxe de séjour', RentalFee::NATURE_PER_PERSON, 100),
            ]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 18));

        $this->assertTrue($quote->minimumApplied);
        $base = $this->baseLine($quote);
        $this->assertSame('25 pers. (minimum) × 3 nuits', $base?->label);
        $this->assertSame(18750, $base?->amountCents, '25 × 3 × 2,50 €');

        // The per-person tax stays on the REAL 18 people: a municipal
        // tourist tax is owed on who actually slept there, not on a
        // commercial floor.
        $taxLine = null;
        foreach ($quote->lines as $line) {
            if ($line->rule === PriceLine::RULE_FEE_PER_PERSON) {
                $taxLine = $line;
            }
        }
        $this->assertSame(18, $taxLine?->quantity);
        $this->assertSame(1800, $taxLine?->amountCents);

        // 187,50 + 50,00 + 18,00 = 255,50
        $this->assertSame(25550, $quote->totalCents);
    }

    // ── Every billing unit ──────────────────────────────────────────────

    public function testFlatStayChargesOnceHoweverLongTheStay(): void
    {
        $settings = $this->settings(BillingUnit::FLAT_STAY, unitPriceCents: 20000);

        $short = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-17', persons: 10));
        $long = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-30', persons: 10));

        $this->assertSame(20000, $short->totalCents);
        $this->assertSame(20000, $long->totalCents);
        $this->assertSame('Forfait par séjour', $this->baseLine($short)?->label);
    }

    public function testPerNightChargesTheNightsBetweenTheDates(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 8000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-17', '2027-07-20'));

        $this->assertSame(3, $quote->quantity, '17→20 is three nights: 17, 18, 19.');
        $this->assertSame(24000, $quote->totalCents);
        $this->assertSame('3 nuits', $this->baseLine($quote)?->label);
    }

    public function testPerDayChargesOneMoreUnitThanPerNightForTheSameDates(): void
    {
        // The closed interval: the return day is billed, because the asset
        // is only handed back at the end of it.
        $perNight = $this->engine->quote(
            $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000),
            new PricingRequest('2027-07-17', '2027-07-24')
        );
        $perDay = $this->engine->quote(
            $this->settings(BillingUnit::PER_DAY, unitPriceCents: 1000),
            new PricingRequest('2027-07-17', '2027-07-24')
        );

        $this->assertSame(7, $perNight->quantity);
        $this->assertSame(8, $perDay->quantity);
        $this->assertSame($perNight->quantity + 1, $perDay->quantity);
        $this->assertSame('8 jours', $this->baseLine($perDay)?->label);
    }

    public function testPerPersonNightMultipliesPeopleByNights(): void
    {
        $quote = $this->engine->quote(
            $this->settings(BillingUnit::PER_PERSON_NIGHT, unitPriceCents: 250),
            new PricingRequest('2027-07-16', '2027-07-19', persons: 20)
        );

        $this->assertSame(60, $quote->quantity);
        $this->assertSame(15000, $quote->totalCents);
    }

    public function testPerPersonDayMultipliesPeopleByDays(): void
    {
        $quote = $this->engine->quote(
            $this->settings(BillingUnit::PER_PERSON_DAY, unitPriceCents: 250),
            new PricingRequest('2027-07-16', '2027-07-19', persons: 20)
        );

        $this->assertSame(80, $quote->quantity, '20 people × 4 days');
        $this->assertSame(20000, $quote->totalCents);
    }

    public function testPerRoomDayMultipliesRoomsByDays(): void
    {
        $quote = $this->engine->quote(
            $this->settings(BillingUnit::PER_ROOM_DAY, unitPriceCents: 1500),
            new PricingRequest('2027-07-16', '2027-07-18', persons: 30, rooms: 4)
        );

        $this->assertSame(12, $quote->quantity, '4 rooms × 3 days');
        $this->assertSame(18000, $quote->totalCents);
        $this->assertSame('4 pièces × 3 jours', $this->baseLine($quote)?->label);
    }

    public function testEveryBillingUnitProducesAQuoteWithoutBlowingUp(): void
    {
        foreach (BillingUnit::all() as $unit) {
            $quote = $this->engine->quote(
                $this->settings($unit, unitPriceCents: 1000),
                new PricingRequest('2027-07-16', '2027-07-19', persons: 10, units: 2, rooms: 3)
            );

            $this->assertGreaterThan(0, $quote->totalCents, $unit->value . ' should price above zero.');
            $this->assertSame($unit, $quote->billingUnit);
        }
    }

    // ── Nights vs full days: derived, never configured ───────────────────

    public function testTheAvailabilityModelIsDerivedFromTheBillingUnit(): void
    {
        // §6.8: this is not a separate setting. Letting the two drift apart
        // is how a calendar ends up contradicting the invoice.
        $this->assertTrue(BillingUnit::FLAT_STAY->isNightBased());
        $this->assertTrue(BillingUnit::PER_NIGHT->isNightBased());
        $this->assertTrue(BillingUnit::PER_PERSON_NIGHT->isNightBased());
        $this->assertFalse(BillingUnit::PER_DAY->isNightBased());
        $this->assertFalse(BillingUnit::PER_PERSON_DAY->isNightBased());
        $this->assertFalse(BillingUnit::PER_ROOM_DAY->isNightBased());
    }

    public function testEveryBillingUnitExplainsItsCalendarConsequence(): void
    {
        // §6.8 requires the explanation to describe the CONSEQUENCE, not
        // restate the label — a manager picking a billing unit is choosing
        // calendar behaviour without realising it.
        foreach (BillingUnit::all() as $unit) {
            $explanation = $unit->availabilityExplanation();

            $this->assertStringContainsString($unit->label(), $explanation);
            $this->assertNotSame($unit->label(), $explanation);
            if ($unit->isNightBased()) {
                $this->assertStringContainsString('jour de départ reste disponible', $explanation);
            } else {
                $this->assertStringContainsString('jour de retour est occupé', $explanation);
            }
        }
    }

    public function testTheTwoModelsAgreeOnTheirTimeUnitCount(): void
    {
        // Strict coherence: for the same dates, a day-based unit must always
        // bill exactly one more unit than a night-based one. A drift here is
        // an off-by-one that would show up as a double-booked departure day.
        foreach ([0, 1, 2, 3, 7, 14, 30] as $nights) {
            $this->assertSame($nights, BillingUnit::PER_NIGHT->timeUnitsFor($nights));
            $this->assertSame($nights + 1, BillingUnit::PER_DAY->timeUnitsFor($nights));
            $this->assertSame(1, BillingUnit::FLAT_STAY->timeUnitsFor($nights), 'A flat stay is always one unit.');
        }
    }

    // ── Minimums, both forms, triggered and not ─────────────────────────

    public function testAMinimumPersonCountRaisesTheQuantityWhenBelow(): void
    {
        $settings = $this->settings(BillingUnit::PER_PERSON_NIGHT, unitPriceCents: 250, minimumPersons: 25);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-18', persons: 10));

        $this->assertTrue($quote->minimumApplied);
        $this->assertSame(25, $quote->persons);
        $this->assertSame(50, $quote->quantity);
        $this->assertSame(12500, $quote->totalCents);
    }

    public function testAMinimumPersonCountDoesNothingWhenAlreadyAbove(): void
    {
        $settings = $this->settings(BillingUnit::PER_PERSON_NIGHT, unitPriceCents: 250, minimumPersons: 25);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-18', persons: 40));

        $this->assertFalse($quote->minimumApplied);
        $this->assertSame(40, $quote->persons);
        $this->assertStringNotContainsString('minimum', (string) $this->baseLine($quote)?->label);
    }

    public function testAMinimumPersonCountIsIgnoredByANonPersonBasedUnit(): void
    {
        // "Minimum 25 people" is meaningless when the price does not depend
        // on people. Applying it anyway would multiply a per-night rate by a
        // head count out of nowhere.
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 8000, minimumPersons: 25);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-18', persons: 2));

        $this->assertFalse($quote->minimumApplied);
        $this->assertSame(16000, $quote->totalCents);
    }

    public function testAMinimumAmountTopsUpAsItsOwnVisibleLine(): void
    {
        // Rewriting the base line instead would leave a renter looking at a
        // line whose quantity × unit price does not equal its amount.
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 5000, minimumAmountCents: 20000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-18'));

        $this->assertTrue($quote->minimumApplied);
        $this->assertSame(20000, $quote->totalCents);

        $base = $this->baseLine($quote);
        $this->assertSame(10000, $base?->amountCents, 'The base line still reads 2 × 50,00 €.');
        $this->assertSame($base->quantity * (int) $base->unitPriceCents, $base->amountCents);

        $topUp = $quote->lines[1];
        $this->assertSame(10000, $topUp->amountCents);
        $this->assertStringContainsString('minimum', $topUp->label);
        $this->assertStringContainsString('200,00 €', $topUp->label);
    }

    public function testAMinimumAmountDoesNothingWhenTheBaseAlreadyExceedsIt(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 5000, minimumAmountCents: 5000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-20'));

        $this->assertFalse($quote->minimumApplied);
        $this->assertSame(20000, $quote->totalCents);
        $this->assertCount(1, $quote->lines);
    }

    public function testAMinimumAmountIsMeasuredBeforeFeesNotAfter(): void
    {
        // The floor is on what renting the asset is worth, not on the
        // invoice total — otherwise adding a cleaning fee would silently
        // reduce the amount the minimum tops up.
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 5000,
            minimumAmountCents: 20000,
            fees: [new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 9000)]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-18'));

        $this->assertSame(29000, $quote->totalCents, '200,00 € floor + 90,00 € cleaning.');
    }

    // ── Fees, each of the three natures ─────────────────────────────────

    public function testAFixedFeeIsAddedOnce(): void
    {
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            fees: [new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000)]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 30));

        $this->assertSame(8000, $quote->totalCents, '3 nights × 10,00 € + 50,00 €.');
    }

    public function testAPerPersonFeeMultipliesByTheRealHeadCount(): void
    {
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            fees: [new RentalFee(1, 'Taxe', RentalFee::NATURE_PER_PERSON, 100)]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 30));

        $this->assertSame(6000, $quote->totalCents, '30,00 € + 30 × 1,00 €.');
    }

    public function testAMeterFeeIsListedButNeverTotalled(): void
    {
        // §6.10: meter fees do not enter the quote. They are settled from
        // real readings after the stay.
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            fees: [new RentalFee(1, 'Électricité', RentalFee::NATURE_METER, 35, 'kWh')]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19'));

        $this->assertSame(3000, $quote->totalCents, 'Only the three nights.');
        $this->assertCount(1, $quote->informationalLines());
        $this->assertCount(1, $quote->billableLines());

        $meter = $quote->informationalLines()[0];
        $this->assertSame('Électricité', $meter->label);
        $this->assertSame(0, $meter->amountCents);
        $this->assertSame(35, $meter->unitPriceCents, 'The unit rate is shown so the renter knows what they signed up to.');
        $this->assertSame('kWh', $meter->meta['meter_unit']);
        $this->assertTrue($meter->isInformational);
    }

    public function testFeesOfAllThreeNaturesCoexistWithoutInteracting(): void
    {
        // No fee ever modifies, caps or cancels another (§6.10).
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            fees: [
                new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null, 1),
                new RentalFee(2, 'Taxe', RentalFee::NATURE_PER_PERSON, 100, null, 2),
                new RentalFee(3, 'Eau', RentalFee::NATURE_METER, 500, 'm³', 3),
            ]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 20));

        $this->assertSame(3000 + 5000 + 2000, $quote->totalCents);
        $this->assertCount(4, $quote->lines);
        $this->assertCount(3, $quote->billableLines());
    }

    public function testFeesAreEmittedInTheirConfiguredOrder(): void
    {
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            fees: [
                new RentalFee(9, 'Dernier', RentalFee::NATURE_FIXED, 100, null, 30),
                new RentalFee(3, 'Premier', RentalFee::NATURE_FIXED, 100, null, 10),
                new RentalFee(7, 'Milieu', RentalFee::NATURE_FIXED, 100, null, 20),
            ]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-17'));

        $labels = array_map(fn(PriceLine $l) => $l->label, array_slice($quote->lines, 1));
        $this->assertSame(['Premier', 'Milieu', 'Dernier'], $labels);
    }

    public function testAPerPersonFeeWithNoPeopleContributesNothing(): void
    {
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            fees: [new RentalFee(1, 'Taxe', RentalFee::NATURE_PER_PERSON, 100)]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-17', persons: 0));

        $this->assertSame(1000, $quote->totalCents);
    }

    // ── The grid: period × category, and nothing else ───────────────────

    public function testThePriceComesFromTheGridCellForThePeriodAndCategory(): void
    {
        $low = new PricePeriod(1, 'Basse saison', '2027-01-01', '2027-06-30');
        $high = new PricePeriod(2, 'Haute saison', '2027-07-01', '2027-08-31');
        $youth = new RenterCategory(1, 'Mouvement de jeunesse');
        $company = new RenterCategory(2, 'Entreprise');

        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: null,
            periods: [$low, $high],
            categories: [$youth, $company],
            grid: [
                PricingSettings::gridKey(1, 1) => 5000,
                PricingSettings::gridKey(1, 2) => 9000,
                PricingSettings::gridKey(2, 1) => 7000,
                PricingSettings::gridKey(2, 2) => 12000,
            ]
        );

        $lowYouth = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-06', renterCategoryId: 1));
        $highCompany = $this->engine->quote($settings, new PricingRequest('2027-07-05', '2027-07-06', renterCategoryId: 2));

        $this->assertSame(5000, $lowYouth->totalCents);
        $this->assertSame('Basse saison', $lowYouth->period?->label);
        $this->assertSame('Mouvement de jeunesse', $lowYouth->category?->label);

        $this->assertSame(12000, $highCompany->totalCents);
        $this->assertSame('Haute saison', $highCompany->period?->label);
        $this->assertSame('Entreprise', $highCompany->category?->label);
    }

    public function testAMissingGridCellFallsBackToTheDefaultRate(): void
    {
        $period = new PricePeriod(1, 'Basse', '2027-01-01', '2027-12-31');
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 4000,
            periods: [$period],
            categories: [new RenterCategory(1, 'MJ')],
            grid: []
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-06', renterCategoryId: 1));

        $this->assertSame(4000, $quote->totalCents);
        $this->assertTrue($quote->isComplete());
    }

    public function testNoRateAtAllWarnsRatherThanPricingAtZero(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: null);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-06'));

        $this->assertSame(0, $quote->totalCents);
        $this->assertFalse($quote->isComplete());
        $this->assertStringContainsString('Aucun tarif', implode(' ', $quote->warnings));
        $this->assertSame([], $quote->lines, 'A zero-priced base line would read as "free".');
    }

    public function testAnUnknownCategoryWarnsInsteadOfSilentlyUsingTheDefault(): void
    {
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 4000,
            categories: [new RenterCategory(1, 'MJ')]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-06', renterCategoryId: 999));

        $this->assertFalse($quote->isComplete());
        $this->assertStringContainsString("n'existe plus", implode(' ', $quote->warnings));
    }

    public function testAnUnstatedCategoryWarnsSoThePublicPageCanSayFromX(): void
    {
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 4000,
            categories: [new RenterCategory(1, 'MJ')]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-06'));

        $this->assertFalse($quote->isComplete(), 'Drives the "dès X €" presentation of §6.7.');
        $this->assertSame(4000, $quote->totalCents);
    }

    public function testAnAssetWithNoPeriodsOrCategoriesPricesCleanly(): void
    {
        // The simplest possible configuration: one rate, all year, everyone.
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 4000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-08'));

        $this->assertTrue($quote->isComplete());
        $this->assertNull($quote->period);
        $this->assertNull($quote->category);
        $this->assertSame(12000, $quote->totalCents);
    }

    // ── Periods: season change mid-stay, recurrence, gaps, overlaps ─────

    public function testAStaySpanningTwoSeasonsUsesTheArrivalRateAndSaysSo(): void
    {
        $low = new PricePeriod(1, 'Basse saison', '2027-01-01', '2027-06-30');
        $high = new PricePeriod(2, 'Haute saison', '2027-07-01', '2027-08-31');
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: null,
            periods: [$low, $high],
            grid: [PricingSettings::gridKey(1, 0) => 5000, PricingSettings::gridKey(2, 0) => 9000]
        );

        // 28 June → 3 July: arrives in low season, leaves in high season.
        $quote = $this->engine->quote($settings, new PricingRequest('2027-06-28', '2027-07-03'));

        $this->assertSame('Basse saison', $quote->period?->label);
        $this->assertSame(25000, $quote->totalCents, '5 nights at the arrival rate.');
        $this->assertStringContainsString('dépasse la période', implode(' ', $quote->warnings));
    }

    public function testAStayWhollyInsideOneSeasonWarnsAboutNothing(): void
    {
        $high = new PricePeriod(2, 'Haute saison', '2027-07-01', '2027-08-31');
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: null,
            periods: [$high],
            grid: [PricingSettings::gridKey(2, 0) => 9000]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-10', '2027-07-15'));

        $this->assertTrue($quote->isComplete());
        $this->assertSame(45000, $quote->totalCents);
    }

    public function testANightsModelStayEndingOnTheSeasonBoundaryDoesNotWarn(): void
    {
        // The departure day is not occupied in a nights model, so a stay
        // whose LAST NIGHT is still in season is entirely in season — warning
        // about it would train managers to ignore the warning.
        $high = new PricePeriod(2, 'Haute saison', '2027-07-01', '2027-08-31');
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: null,
            periods: [$high],
            grid: [PricingSettings::gridKey(2, 0) => 9000]
        );

        // Nights of 30 and 31 August; leaves on 1 September.
        $quote = $this->engine->quote($settings, new PricingRequest('2027-08-30', '2027-09-01'));

        $this->assertTrue($quote->isComplete(), implode(' | ', $quote->warnings));
        $this->assertSame(18000, $quote->totalCents);
    }

    public function testADaysModelStayEndingOnTheSeasonBoundaryDoesWarn(): void
    {
        // The mirror case: in a full-days model the return day IS occupied,
        // so the same dates do leave the season.
        $high = new PricePeriod(2, 'Haute saison', '2027-07-01', '2027-08-31');
        $settings = $this->settings(
            BillingUnit::PER_DAY,
            unitPriceCents: null,
            periods: [$high],
            grid: [PricingSettings::gridKey(2, 0) => 9000]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-08-30', '2027-09-01'));

        $this->assertFalse($quote->isComplete());
        $this->assertStringContainsString('dépasse la période', implode(' ', $quote->warnings));
    }

    public function testADateCoveredByNoPeriodWarns(): void
    {
        $high = new PricePeriod(2, 'Haute saison', '2027-07-01', '2027-08-31');
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 4000, periods: [$high]);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-03-05', '2027-03-06'));

        $this->assertNull($quote->period);
        $this->assertStringContainsString('Aucune période tarifaire', implode(' ', $quote->warnings));
    }

    public function testOverlappingPeriodsWarnRatherThanBeingResolvedByPrecedence(): void
    {
        // This engine has no rule precedence. Two seasons covering one day is
        // a configuration mistake to report, not a tie to break.
        $a = new PricePeriod(1, 'A', '2027-07-01', '2027-07-31');
        $b = new PricePeriod(2, 'B', '2027-07-15', '2027-08-15');
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: null,
            periods: [$a, $b],
            grid: [PricingSettings::gridKey(1, 0) => 5000, PricingSettings::gridKey(2, 0) => 9000]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-20', '2027-07-21'));

        $this->assertSame('A', $quote->period?->label, 'First configured wins, deterministically.');
        $this->assertStringContainsString('se chevauchent', implode(' ', $quote->warnings));
    }

    public function testAYearlyRecurringPeriodMatchesInEveryYear(): void
    {
        $summer = new PricePeriod(1, 'Été', '2000-07-01', '2000-08-31', recursYearly: true);
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: null,
            periods: [$summer],
            grid: [PricingSettings::gridKey(1, 0) => 9000]
        );

        foreach (['2027-07-15', '2028-07-15', '2031-08-30'] as $date) {
            $quote = $this->engine->quote($settings, new PricingRequest($date, (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')));
            $this->assertSame('Été', $quote->period?->label, $date);
            $this->assertSame(9000, $quote->totalCents);
        }

        $winter = $this->engine->quote($settings, new PricingRequest('2027-02-15', '2027-02-16'));
        $this->assertNull($winter->period);
    }

    public function testARecurringPeriodWrappingTheNewYearCoversBothSides(): void
    {
        // 20 December → 5 January. Getting the wrap backwards prices the
        // Christmas holidays as low season — exactly when a hall is busiest.
        $christmas = new PricePeriod(1, 'Vacances de Noël', '2000-12-20', '2000-01-05', recursYearly: true);

        $this->assertTrue($christmas->covers(new \DateTimeImmutable('2027-12-24')));
        $this->assertTrue($christmas->covers(new \DateTimeImmutable('2028-01-02')));
        $this->assertTrue($christmas->covers(new \DateTimeImmutable('2027-12-20')), 'Inclusive start.');
        $this->assertTrue($christmas->covers(new \DateTimeImmutable('2028-01-05')), 'Inclusive end.');
        $this->assertFalse($christmas->covers(new \DateTimeImmutable('2028-01-06')));
        $this->assertFalse($christmas->covers(new \DateTimeImmutable('2027-06-15')));
    }

    public function testAFixedPeriodIsInclusiveAtBothEnds(): void
    {
        $period = new PricePeriod(1, 'P', '2027-07-01', '2027-07-31');

        $this->assertTrue($period->covers(new \DateTimeImmutable('2027-07-01')));
        $this->assertTrue($period->covers(new \DateTimeImmutable('2027-07-31')));
        $this->assertFalse($period->covers(new \DateTimeImmutable('2027-06-30')));
        $this->assertFalse($period->covers(new \DateTimeImmutable('2027-08-01')));
    }

    // ── Stock quantity ──────────────────────────────────────────────────

    public function testBookingSeveralStockUnitsMultipliesATimeBasedRate(): void
    {
        // Three tents for five nights is three times one tent for five nights.
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-21', units: 3));

        $this->assertSame(15, $quote->quantity);
        $this->assertSame(15000, $quote->totalCents);
        $this->assertSame('3 × 5 nuits', $this->baseLine($quote)?->label);
    }

    public function testBookingSeveralStockUnitsDoesNotMultiplyAPerPersonRate(): void
    {
        // Charging per person AND per tent would bill the same people once
        // per tent they sleep in.
        $settings = $this->settings(BillingUnit::PER_PERSON_NIGHT, unitPriceCents: 250);

        $one = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 20, units: 1));
        $three = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 20, units: 3));

        $this->assertSame($one->totalCents, $three->totalCents);
    }

    public function testAFlatStayMultipliesByStockUnits(): void
    {
        $settings = $this->settings(BillingUnit::FLAT_STAY, unitPriceCents: 5000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-21', units: 4));

        $this->assertSame(20000, $quote->totalCents);
        $this->assertSame('Forfait par séjour × 4', $this->baseLine($quote)?->label);
    }

    // ── Date edge cases ─────────────────────────────────────────────────

    public function testSameDayArrivalAndDepartureIsZeroNightsAndWarns(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-16'));

        $this->assertSame(0, $quote->nights);
        $this->assertSame(0, $quote->totalCents);
        $this->assertStringContainsString('aucune nuit', implode(' ', $quote->warnings));
    }

    public function testSameDayStillBillsOneDayInAFullDaysModel(): void
    {
        // A trailer taken and returned the same day is one day's rental,
        // which is exactly the difference between the two models.
        $settings = $this->settings(BillingUnit::PER_DAY, unitPriceCents: 3000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-16'));

        $this->assertSame(1, $quote->quantity);
        $this->assertSame(3000, $quote->totalCents);
    }

    public function testAReversedDateRangeIsClampedToZeroRatherThanNegative(): void
    {
        // A negative quantity would produce a negative price — a refund
        // invented out of a form validation gap.
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-20', '2027-07-16'));

        $this->assertSame(0, $quote->nights);
        $this->assertSame(0, $quote->totalCents);
        $this->assertGreaterThanOrEqual(0, $quote->totalCents);
    }

    public function testAStayAcrossAMonthBoundaryCountsCorrectly(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-30', '2027-08-02'));

        $this->assertSame(3, $quote->nights);
    }

    public function testAStayAcrossAYearBoundaryCountsCorrectly(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-12-30', '2028-01-02'));

        $this->assertSame(3, $quote->nights);
    }

    public function testAStayAcrossTheLeapDayCountsIt(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $leap = $this->engine->quote($settings, new PricingRequest('2028-02-27', '2028-03-01'));
        $nonLeap = $this->engine->quote($settings, new PricingRequest('2027-02-27', '2027-03-01'));

        $this->assertSame(3, $leap->nights, '27, 28 and 29 February 2028.');
        $this->assertSame(2, $nonLeap->nights, '27 and 28 February 2027.');
    }

    public function testAStayAcrossADaylightSavingChangeCountsWholeNights(): void
    {
        // Belgium changes clocks in late March and late October, both in
        // camp season. A seconds-based night count loses or gains a night.
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $spring = $this->engine->quote($settings, new PricingRequest('2027-03-27', '2027-03-29'));
        $autumn = $this->engine->quote($settings, new PricingRequest('2027-10-30', '2027-11-01'));

        $this->assertSame(2, $spring->nights);
        $this->assertSame(2, $autumn->nights);
    }

    public function testALongStayCountsEveryNight(): void
    {
        $settings = $this->settings(BillingUnit::PER_NIGHT, unitPriceCents: 1000);

        $quote = $this->engine->quote($settings, new PricingRequest('2027-01-01', '2027-12-31'));

        $this->assertSame(364, $quote->nights);
    }

    // ── The result is snapshotable ──────────────────────────────────────

    public function testAQuoteSurvivesARoundTripThroughItsSnapshot(): void
    {
        // The agreed price is this object serialised and never recomputed
        // (§6.11), so the round trip has to be lossless for everything the
        // contract and the invoice read back.
        $settings = $this->settings(
            BillingUnit::PER_PERSON_NIGHT,
            unitPriceCents: 250,
            minimumPersons: 25,
            fees: [
                new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000),
                new RentalFee(2, 'Électricité', RentalFee::NATURE_METER, 35, 'kWh'),
            ],
            periods: [new PricePeriod(1, 'Haute saison', '2027-07-01', '2027-08-31')],
            categories: [new RenterCategory(1, 'Mouvement de jeunesse')],
            grid: [PricingSettings::gridKey(1, 1) => 250]
        );

        $original = $this->engine->quote(
            $settings,
            new PricingRequest('2027-07-16', '2027-07-19', persons: 18, renterCategoryId: 1)
        );

        $restored = \Modules\Rental\Pricing\PriceQuote::fromArray(
            json_decode((string) json_encode($original->toArray()), true)
        );

        $this->assertSame($original->totalCents, $restored->totalCents);
        $this->assertSame($original->nights, $restored->nights);
        $this->assertSame($original->persons, $restored->persons);
        $this->assertSame($original->quantity, $restored->quantity);
        $this->assertSame($original->billingUnit, $restored->billingUnit);
        $this->assertSame($original->minimumApplied, $restored->minimumApplied);
        $this->assertSame($original->period?->label, $restored->period?->label);
        $this->assertSame($original->category?->label, $restored->category?->label);
        $this->assertCount(count($original->lines), $restored->lines);

        foreach ($original->lines as $i => $line) {
            $this->assertSame($line->label, $restored->lines[$i]->label);
            $this->assertSame($line->quantity, $restored->lines[$i]->quantity);
            $this->assertSame($line->unitPriceCents, $restored->lines[$i]->unitPriceCents);
            $this->assertSame($line->amountCents, $restored->lines[$i]->amountCents);
            $this->assertSame($line->rule, $restored->lines[$i]->rule);
            $this->assertSame($line->isInformational, $restored->lines[$i]->isInformational);
        }
    }

    public function testASnapshotKeepsItsOwnPeriodLabelAfterTheSeasonIsRenamed(): void
    {
        // A snapshot has to keep reading the way it did when it was taken.
        $snapshot = [
            'lines' => [],
            'total_cents' => 12345,
            'nights' => 3,
            'persons' => 20,
            'quantity' => 60,
            'billing_unit' => 'per_person_night',
            'period' => ['id' => 1, 'label' => 'Haute saison 2027'],
            'category' => ['id' => 2, 'label' => 'ASBL'],
            'minimum_applied' => false,
            'warnings' => [],
        ];

        $restored = \Modules\Rental\Pricing\PriceQuote::fromArray($snapshot);

        $this->assertSame('Haute saison 2027', $restored->period?->label);
        $this->assertSame('ASBL', $restored->category?->label);
        $this->assertSame(12345, $restored->totalCents);
    }

    public function testEveryLineCarriesTheRuleThatProducedIt(): void
    {
        // What makes a quote auditable rather than a number to be trusted.
        $settings = $this->settings(
            BillingUnit::PER_NIGHT,
            unitPriceCents: 1000,
            minimumAmountCents: 50000,
            fees: [
                new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null, 1),
                new RentalFee(2, 'Taxe', RentalFee::NATURE_PER_PERSON, 100, null, 2),
                new RentalFee(3, 'Eau', RentalFee::NATURE_METER, 500, 'm³', 3),
            ]
        );

        $quote = $this->engine->quote($settings, new PricingRequest('2027-07-16', '2027-07-19', persons: 20));

        $rules = array_map(fn(PriceLine $l) => $l->rule, $quote->lines);
        $this->assertSame([
            PriceLine::RULE_BASE,
            PriceLine::RULE_BASE,
            PriceLine::RULE_FEE_FIXED,
            PriceLine::RULE_FEE_PER_PERSON,
            PriceLine::RULE_FEE_METER,
        ], $rules);

        foreach ($quote->lines as $line) {
            $this->assertNotSame('', $line->rule);
            $this->assertNotSame('', $line->label);
        }
    }

    public function testTheTotalAlwaysEqualsTheSumOfTheBillableLines(): void
    {
        // The invariant a renter checks by hand, and the one a rounding or
        // informational-line mistake breaks first.
        $settings = $this->settings(
            BillingUnit::PER_PERSON_NIGHT,
            unitPriceCents: 250,
            minimumPersons: 25,
            minimumAmountCents: null,
            fees: [
                new RentalFee(1, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null, 1),
                new RentalFee(2, 'Taxe', RentalFee::NATURE_PER_PERSON, 100, null, 2),
                new RentalFee(3, 'Eau', RentalFee::NATURE_METER, 500, 'm³', 3),
            ]
        );

        foreach ([0, 5, 18, 25, 40, 120] as $persons) {
            foreach ([['2027-07-16', '2027-07-16'], ['2027-07-16', '2027-07-17'], ['2027-07-16', '2027-07-30']] as [$from, $to]) {
                $quote = $this->engine->quote($settings, new PricingRequest($from, $to, persons: $persons));

                $sum = array_sum(array_map(fn(PriceLine $l) => $l->amountCents, $quote->billableLines()));
                $this->assertSame($quote->totalCents, $sum, "persons={$persons} {$from}→{$to}");
                $this->assertGreaterThanOrEqual(0, $quote->totalCents);
            }
        }
    }

    public function testFormatCentsRendersFrenchAmounts(): void
    {
        $this->assertSame('187,50 €', RentalPricingEngine::formatCents(18750));
        $this->assertSame('0,00 €', RentalPricingEngine::formatCents(0));
        $this->assertSame('1 234,56 €', RentalPricingEngine::formatCents(123456));
    }
}
