<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use Modules\Rental\Stay\SettlementCalculator;
use Modules\Rental\Stay\SettlementLine;
use PHPUnit\Framework\TestCase;

/**
 * What a stay comes to once it has happened (§6.21).
 *
 * Two rules dominate this file. The settlement **never modifies the agreed
 * price** — it takes it as an input and returns new lines, so there is no
 * path by which it could write back. And **nothing decides anything about a
 * damage**: an incident enters the total only if a human already decided it
 * should.
 */
class SettlementCalculatorTest extends TestCase
{
    private SettlementCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SettlementCalculator();
    }

    /**
     * A 3-night stay for 40 people: 360,00 € for the hall plus a 1,00 €
     * per-person tourist tax.
     */
    private function agreedQuote(int $persons = 40): PriceQuote
    {
        return new PriceQuote(
            lines: [
                new PriceLine('3 nuits', 3, 12000, 36000, PriceLine::RULE_BASE),
                new PriceLine('Taxe de séjour', $persons, 100, $persons * 100, PriceLine::RULE_FEE_PER_PERSON),
            ],
            totalCents: 36000 + $persons * 100,
            nights: 3,
            persons: $persons,
            quantity: 3,
            billingUnit: BillingUnit::PER_NIGHT
        );
    }

    private function consumption(int $amountCents = 8563): MeterConsumption
    {
        return MeterConsumption::of(
            new RentalMeter(1, 1, 'Électricité', MeterKind::ELECTRICITY, 'kWh', 7),
            new MeterReading(1, 1, 1, ReadingPhase::ARRIVAL, 1000000, new \DateTimeImmutable(), null, null, null),
            new MeterReading(2, 1, 1, ReadingPhase::DEPARTURE, 1342500, new \DateTimeImmutable(), null, null, null),
            new RentalFee(7, 'Électricité', RentalFee::NATURE_METER, (int) round($amountCents / 342.5), 'kWh')
        );
    }

    private function incident(
        IncidentDecision $decision,
        int $proposed = 5000,
        ?int $decided = null
    ): Incident {
        return new Incident(
            1,
            1,
            'Vitre cassée dans le réfectoire',
            $proposed,
            $decision,
            $decided,
            $decision === IncidentDecision::PENDING ? null : new \DateTimeImmutable(),
            null,
            null,
            null,
            new \DateTimeImmutable()
        );
    }

    // ── The agreed price is carried, never rewritten ────────────────────

    public function testWithNothingToAddTheSettlementIsTheAgreedPrice(): void
    {
        $result = $this->calculator->compute($this->agreedQuote(), null, [], [], [], 0, null);

        $this->assertSame(40000, $result['total_cents']);
        $this->assertCount(2, $result['lines']);
    }

    public function testTheAgreedQuoteObjectIsNotMutated(): void
    {
        // Structural, not incidental: the calculator returns new lines and
        // has no reference through which it could write back.
        $agreed = $this->agreedQuote();
        $before = $agreed->totalCents;

        $this->calculator->compute($agreed, 28, [$this->consumption()], [$this->incident(IncidentDecision::CHARGE)], [], 0, null);

        $this->assertSame($before, $agreed->totalCents);
        $this->assertSame(40, $agreed->persons);
    }

    // ── The final head count (§6.21) ────────────────────────────────────

    public function testAPerPersonLineIsRescaledToWhoActuallyTurnedUp(): void
    {
        // Announced 40, came 28: the tax follows, the hall does not.
        $result = $this->calculator->compute($this->agreedQuote(40), 28, [], [], [], 0, null);

        $lines = $result['lines'];
        $this->assertSame(36000, $lines[0]->amountCents);
        $this->assertSame(2800, $lines[1]->amountCents);
        $this->assertSame(SettlementLine::ORIGIN_PER_PERSON, $lines[1]->origin);
        $this->assertStringContainsString('28 participants', (string) $lines[1]->detail);
        $this->assertSame(38800, $result['total_cents']);
    }

    public function testMorePeopleThanAnnouncedRaisesThePerPersonLine(): void
    {
        $result = $this->calculator->compute($this->agreedQuote(40), 45, [], [], [], 0, null);

        $this->assertSame(4500, $result['lines'][1]->amountCents);
    }

    public function testWithNoFinalCountTheAgreedLinesAreCarriedAsTheyAre(): void
    {
        $result = $this->calculator->compute($this->agreedQuote(40), null, [], [], [], 0, null);

        $this->assertSame(4000, $result['lines'][1]->amountCents);
        $this->assertSame(SettlementLine::ORIGIN_AGREED, $result['lines'][1]->origin);
    }

    public function testAHandEditedLineIsNeverRescaledBehindTheManagersBack(): void
    {
        // Same rule as §6.12's re-quoting: a manager who typed an amount has
        // already decided what it is.
        $agreed = new PriceQuote(
            lines: [new PriceLine('Taxe négociée', 40, null, 3000, PriceLine::RULE_FEE_PER_PERSON, isManual: true)],
            totalCents: 3000,
            nights: 3,
            persons: 40,
            quantity: 3,
            billingUnit: BillingUnit::PER_NIGHT
        );

        $result = $this->calculator->compute($agreed, 10, [], [], [], 0, null);

        $this->assertSame(3000, $result['lines'][0]->amountCents);
    }

    public function testAQuoteWithNoHeadCountCannotBeRescaledAndIsCarriedAcross(): void
    {
        $agreed = new PriceQuote(
            lines: [new PriceLine('Taxe', 0, 100, 0, PriceLine::RULE_FEE_PER_PERSON)],
            totalCents: 0,
            nights: 3,
            persons: 0,
            quantity: 3,
            billingUnit: BillingUnit::PER_NIGHT
        );

        $result = $this->calculator->compute($agreed, 28, [], [], [], 0, null);

        $this->assertSame(0, $result['lines'][0]->amountCents);
    }

    // ── Meters (§6.22) ──────────────────────────────────────────────────

    public function testARealReadingBecomesItsOwnLine(): void
    {
        $result = $this->calculator->compute($this->agreedQuote(), null, [$this->consumption()], [], [], 0, null);

        $meterLines = array_values(array_filter(
            $result['lines'],
            static fn(SettlementLine $l) => $l->origin === SettlementLine::ORIGIN_METER
        ));
        $this->assertCount(1, $meterLines);
        $this->assertStringContainsString('342,500 kWh', (string) $meterLines[0]->detail);
    }

    public function testAnInformationalQuoteLineIsNotCarriedAcross(): void
    {
        // A meter fee was never in the quote's total; carrying its zero
        // across would put the same thing on the settlement twice.
        $agreed = new PriceQuote(
            lines: [
                new PriceLine('3 nuits', 3, 12000, 36000, PriceLine::RULE_BASE),
                new PriceLine('Électricité', 1, 25, 0, PriceLine::RULE_FEE_METER, isInformational: true),
            ],
            totalCents: 36000,
            nights: 3,
            persons: 40,
            quantity: 3,
            billingUnit: BillingUnit::PER_NIGHT
        );

        $result = $this->calculator->compute($agreed, null, [$this->consumption()], [], [], 0, null);

        $labels = array_map(static fn(SettlementLine $l) => $l->label, $result['lines']);
        $this->assertSame(['3 nuits', 'Électricité'], $labels);
        $this->assertSame(SettlementLine::ORIGIN_METER, $result['lines'][1]->origin);
    }

    public function testAnUnusableReadingContributesNothing(): void
    {
        // Billing a guess is worse than billing nothing.
        $backwards = MeterConsumption::of(
            new RentalMeter(1, 1, 'Électricité', MeterKind::ELECTRICITY, 'kWh', 7),
            new MeterReading(1, 1, 1, ReadingPhase::ARRIVAL, 1342500, new \DateTimeImmutable(), null, null, null),
            new MeterReading(2, 1, 1, ReadingPhase::DEPARTURE, 1000000, new \DateTimeImmutable(), null, null, null),
            new RentalFee(7, 'Électricité', RentalFee::NATURE_METER, 25, 'kWh')
        );

        $result = $this->calculator->compute($this->agreedQuote(), null, [$backwards], [], [], 0, null);

        $this->assertSame(40000, $result['total_cents']);
    }

    // ── Incidents: the decision stays human (§6.23) ─────────────────────

    public function testAPendingIncidentIsNeverCharged(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::PENDING)],
            [],
            0,
            50000
        );

        $this->assertSame(40000, $result['total_cents']);
        $this->assertSame(0, $result['security_deposit_withheld_cents']);
    }

    public function testAWaivedIncidentIsNeverCharged(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::WAIVE)],
            [],
            0,
            50000
        );

        $this->assertSame(40000, $result['total_cents']);
        $this->assertSame(50000, $result['security_deposit_return_cents']);
    }

    public function testAChargedIncidentEntersTheTotalAndNotTheDeposit(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::CHARGE)],
            [],
            0,
            50000
        );

        $this->assertSame(45000, $result['total_cents']);
        $this->assertSame(0, $result['security_deposit_withheld_cents']);
        $this->assertSame(50000, $result['security_deposit_return_cents']);
    }

    public function testAWithheldIncidentReducesTheDepositAndNotTheTotal(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::WITHHOLD)],
            [],
            0,
            50000
        );

        $this->assertSame(40000, $result['total_cents']);
        $this->assertSame(5000, $result['security_deposit_withheld_cents']);
        $this->assertSame(45000, $result['security_deposit_return_cents']);
    }

    public function testADecidedAmountOverridesTheProposedOne(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::CHARGE, 5000, 3000)],
            [],
            0,
            null
        );

        $this->assertSame(43000, $result['total_cents']);
    }

    public function testPickingADecisionWithoutRetypingTheFigureMeansTheProposedOne(): void
    {
        // Making a manager type it twice is how the two end up different.
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::CHARGE, 5000, null)],
            [],
            0,
            null
        );

        $this->assertSame(45000, $result['total_cents']);
    }

    public function testWithholdingNeverExceedsWhatWasActuallyHeld(): void
    {
        // A manager who wants the difference invoices it as a charge.
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::WITHHOLD, 80000)],
            [],
            0,
            50000
        );

        $this->assertSame(50000, $result['security_deposit_withheld_cents']);
        $this->assertSame(0, $result['security_deposit_return_cents']);
    }

    public function testWithNoSecurityDepositNothingCanBeWithheld(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [$this->incident(IncidentDecision::WITHHOLD)],
            [],
            0,
            null
        );

        $this->assertSame(0, $result['security_deposit_withheld_cents']);
        $this->assertSame(0, $result['security_deposit_return_cents']);
    }

    public function testNoDamageAtAllReturnsTheWholeDeposit(): void
    {
        $result = $this->calculator->compute($this->agreedQuote(), null, [], [], [], 0, 50000);

        $this->assertSame(0, $result['security_deposit_withheld_cents']);
        $this->assertSame(50000, $result['security_deposit_return_cents']);
    }

    // ── The balance ─────────────────────────────────────────────────────

    public function testTheBalanceIsWhatIsStillOwed(): void
    {
        $result = $this->calculator->compute($this->agreedQuote(), null, [], [], [], 10000, null);

        $this->assertSame(30000, $result['balance_cents']);
    }

    public function testAnOverpaymentComesOutNegativeRatherThanFlooredAtZero(): void
    {
        // Flooring it is how a refund nobody knows about happens.
        $result = $this->calculator->compute($this->agreedQuote(), 10, [], [], [], 50000, null);

        $this->assertSame(37000, $result['total_cents']);
        $this->assertSame(-13000, $result['balance_cents']);
    }

    // ── Manual lines ────────────────────────────────────────────────────

    public function testAManualLineIsAddedAsTyped(): void
    {
        $result = $this->calculator->compute(
            $this->agreedQuote(),
            null,
            [],
            [],
            [new SettlementLine('Geste commercial', -2000, SettlementLine::ORIGIN_MANUAL)],
            0,
            null
        );

        $this->assertSame(38000, $result['total_cents']);
    }

    public function testEverythingAtOnce(): void
    {
        // 360,00 hall + 28 × 1,00 tax + 85,63 electricity + 50,00 charged
        // damage, minus 200,00 already paid; 30,00 withheld from a 500,00
        // deposit.
        $result = $this->calculator->compute(
            $this->agreedQuote(40),
            28,
            [$this->consumption()],
            [
                $this->incident(IncidentDecision::CHARGE, 5000),
                $this->incident(IncidentDecision::WITHHOLD, 3000),
                $this->incident(IncidentDecision::PENDING, 99999),
            ],
            [new SettlementLine('Geste commercial', -1000, SettlementLine::ORIGIN_MANUAL)],
            20000,
            50000
        );

        $this->assertSame(36000 + 2800 + 8563 + 5000 - 1000, $result['total_cents']);
        $this->assertSame($result['total_cents'] - 20000, $result['balance_cents']);
        $this->assertSame(3000, $result['security_deposit_withheld_cents']);
        $this->assertSame(47000, $result['security_deposit_return_cents']);
    }

    // ── Line snapshots ──────────────────────────────────────────────────

    public function testALineSurvivesASnapshotRoundTrip(): void
    {
        $line = new SettlementLine('Électricité', 8563, SettlementLine::ORIGIN_METER, '342,500 kWh');
        $restored = SettlementLine::fromArray($line->toArray());

        $this->assertSame('Électricité', $restored->label);
        $this->assertSame(8563, $restored->amountCents);
        $this->assertSame(SettlementLine::ORIGIN_METER, $restored->origin);
        $this->assertSame('342,500 kWh', $restored->detail);
    }
}
