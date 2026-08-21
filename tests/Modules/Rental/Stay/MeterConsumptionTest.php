<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use PHPUnit\Framework\TestCase;

/**
 * Turning two meter readings into a consumption and a cost (§6.22).
 *
 * The arithmetic is where a rental bill goes quietly wrong, so it is pinned
 * exhaustively — including the cases where the honest answer is "a human
 * has to look at this".
 */
class MeterConsumptionTest extends TestCase
{
    private function meter(?int $feeId = 7, string $unit = 'kWh'): RentalMeter
    {
        return new RentalMeter(1, 1, 'Électricité', MeterKind::ELECTRICITY, $unit, $feeId);
    }

    private function reading(ReadingPhase $phase, int $valueMilli): MeterReading
    {
        return new MeterReading(
            1,
            1,
            1,
            $phase,
            $valueMilli,
            new \DateTimeImmutable('2027-07-01 10:00:00'),
            null,
            null,
            null
        );
    }

    /** 0,25 € per kWh. */
    private function fee(int $cents = 25): RentalFee
    {
        return new RentalFee(7, 'Électricité', RentalFee::NATURE_METER, $cents, 'kWh');
    }

    // ── Parsing what an operator types ──────────────────────────────────

    public function testTheFrenchDecimalCommaIsAccepted(): void
    {
        // `(float) "1234,567"` is 1234.0 — a reading silently entered short
        // by more than half a unit, with nothing to notice it by.
        $this->assertSame(1234567, MeterReading::parse('1234,567'));
        $this->assertSame(1234567, MeterReading::parse('1234.567'));
    }

    public function testAWholeNumberScalesUp(): void
    {
        $this->assertSame(1234000, MeterReading::parse('1234'));
    }

    public function testSpacesAndThinSpacesAreStrippedBecausePeoplePasteThem(): void
    {
        $this->assertSame(1234567, MeterReading::parse(" 1 234,567\u{202f}"));
    }

    public function testAFourthDecimalIsRoundedRatherThanTruncated(): void
    {
        // Truncation would bias every reading downwards.
        $this->assertSame(1234568, MeterReading::parse('1234,5675'));
        $this->assertSame(1234567, MeterReading::parse('1234,5674'));
    }

    public function testAnEmptyOrNonNumericValueIsNullNotZero(): void
    {
        // "Empty field" and "zero" are different facts about a meter.
        $this->assertNull(MeterReading::parse(null));
        $this->assertNull(MeterReading::parse('   '));
        $this->assertNull(MeterReading::parse('abc'));
        $this->assertNull(MeterReading::parse('12,34,56'));
        $this->assertSame(0, MeterReading::parse('0'));
    }

    public function testAValueRoundTripsThroughFormatting(): void
    {
        $this->assertSame('1 234,567', MeterReading::format(1234567));
        $this->assertSame(1234567, MeterReading::parse(MeterReading::format(1234567)));
    }

    // ── Consumption ─────────────────────────────────────────────────────

    public function testConsumptionIsTheDifferenceBetweenTheTwoReadings(): void
    {
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            $this->reading(ReadingPhase::DEPARTURE, 1342500),
            $this->fee()
        );

        $this->assertSame(342500, $consumption->consumptionMilli);
        $this->assertSame('342,500 kWh', $consumption->formattedConsumption());
        $this->assertTrue($consumption->isBillable());
    }

    public function testTheCostIsTheConsumptionTimesTheUnitPrice(): void
    {
        // 342,5 kWh at 0,25 € = 85,625 € → 85,63 €.
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            $this->reading(ReadingPhase::DEPARTURE, 1342500),
            $this->fee()
        );

        $this->assertSame(8563, $consumption->amountCents);
    }

    public function testTheOnlyRoundingHappensAtTheEnd(): void
    {
        // The subtraction is exact because both values are integers; a
        // float pipeline would drift here.
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1234567),
            $this->reading(ReadingPhase::DEPARTURE, 1234568),
            $this->fee()
        );

        $this->assertSame(1, $consumption->consumptionMilli);
        $this->assertSame(0, $consumption->amountCents);
    }

    public function testZeroConsumptionCostsNothingAndIsStillComplete(): void
    {
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            $this->reading(ReadingPhase::DEPARTURE, 1000000),
            $this->fee()
        );

        $this->assertSame(0, $consumption->consumptionMilli);
        $this->assertSame(0, $consumption->amountCents);
        $this->assertTrue($consumption->isComplete());
        $this->assertNull($consumption->warning);
    }

    // ── The cases that need a human ─────────────────────────────────────

    public function testAMeterThatWentBackwardsIsReportedNeverGuessedAt(): void
    {
        // A replaced meter, a transposed digit, or a reading taken at the
        // wrong end. Taking an absolute value would bill a renter for a typo.
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1342500),
            $this->reading(ReadingPhase::DEPARTURE, 1000000),
            $this->fee()
        );

        $this->assertSame(-342500, $consumption->consumptionMilli);
        $this->assertSame(0, $consumption->amountCents);
        $this->assertNotNull($consumption->warning);
        $this->assertFalse($consumption->isBillable());
    }

    public function testAMissingDepartureReadingIsReported(): void
    {
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            null,
            $this->fee()
        );

        $this->assertNull($consumption->consumptionMilli);
        $this->assertSame(0, $consumption->amountCents);
        $this->assertStringContainsString('sortie', (string) $consumption->warning);
    }

    public function testAMissingArrivalReadingIsReported(): void
    {
        $consumption = MeterConsumption::of(
            $this->meter(),
            null,
            $this->reading(ReadingPhase::DEPARTURE, 1000000),
            $this->fee()
        );

        $this->assertStringContainsString('entrée', (string) $consumption->warning);
    }

    public function testAMeterWithNoReadingsAtAllWarnsAboutNothing(): void
    {
        // Nobody has started; that is not a problem to flag.
        $consumption = MeterConsumption::of($this->meter(), null, null, $this->fee());

        $this->assertNull($consumption->warning);
        $this->assertFalse($consumption->isComplete());
    }

    // ── Meters that are read but not billed ─────────────────────────────

    public function testAMeterWithNoFeeIsReadButNeverBilled(): void
    {
        $consumption = MeterConsumption::of(
            $this->meter(feeId: null),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            $this->reading(ReadingPhase::DEPARTURE, 1342500),
            null
        );

        $this->assertSame(342500, $consumption->consumptionMilli);
        $this->assertSame(0, $consumption->amountCents);
        $this->assertTrue($consumption->isComplete());
        $this->assertFalse($consumption->isBillable());
    }

    public function testAFeeOfTheWrongNatureIsIgnored(): void
    {
        // A fixed fee is not a unit price, and treating it as one would
        // multiply a flat 25,00 € by every kWh consumed.
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            $this->reading(ReadingPhase::DEPARTURE, 1342500),
            new RentalFee(7, 'Nettoyage', RentalFee::NATURE_FIXED, 2500)
        );

        $this->assertNull($consumption->unitPriceCents);
        $this->assertSame(0, $consumption->amountCents);
    }

    public function testTheUnitFallsBackToTheKindsDefault(): void
    {
        $meter = new RentalMeter(1, 1, 'Eau', MeterKind::WATER, '', null);

        $this->assertSame('m³', $meter->displayUnit());
    }

    public function testASettlementLabelCarriesTheConsumption(): void
    {
        $consumption = MeterConsumption::of(
            $this->meter(),
            $this->reading(ReadingPhase::ARRIVAL, 1000000),
            $this->reading(ReadingPhase::DEPARTURE, 1342500),
            $this->fee()
        );

        $this->assertStringContainsString('Électricité', $consumption->settlementLabel());
        $this->assertStringContainsString('342,500 kWh', $consumption->settlementLabel());
    }
}
