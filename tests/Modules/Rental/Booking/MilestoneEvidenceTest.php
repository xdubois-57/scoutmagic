<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use Modules\Rental\Stay\Settlement;
use PHPUnit\Framework\TestCase;

/**
 * The evidence behind the manager's checklist (§6.15).
 *
 * The bug these cover: `BookingMilestones::for()` has always accepted an
 * `$extras` map whose ABSENT keys render greyed, and nothing ever built
 * one — so ten of the fourteen lines were permanently "sans objet". A
 * manager could send the contract, cash the deposit and finish both
 * inventories without a single box moving.
 */
class MilestoneEvidenceTest extends TestCase
{
    private function booking(?\DateTimeImmutable $conditionsAcceptedAt = null): RentalBooking
    {
        return new RentalBooking(
            id: 7,
            assetId: 1,
            reference: 'LOC-2027-0042',
            arrivalDate: '2027-07-01',
            departureDate: '2027-07-04',
            units: 1,
            estimatedPersons: 20,
            renterCategoryId: null,
            renterName: 'Jeanne Martin',
            renterEmail: 'jeanne@example.be',
            renterPhone: null,
            renterOrganisation: null,
            purpose: null,
            renterComment: null,
            status: BookingStatus::CONFIRMED,
            receivedAt: new \DateTimeImmutable('2027-01-01 10:00:00'),
            finalAt: null,
            holdUntil: null,
            holdOrigin: null,
            estimatedPrice: null,
            estimatedTotalCents: null,
            agreedPrice: null,
            agreedTotalCents: null,
            conditionsVersion: $conditionsAcceptedAt !== null ? 'v1' : null,
            conditionsHash: null,
            conditionsAcceptedAt: $conditionsAcceptedAt,
            privacyVersion: null,
            privacyHash: null,
            privacyAcknowledgedAt: null
        );
    }

    private function document(
        DocumentType $type,
        ?\DateTimeImmutable $sentAt = null,
        string $createdAt = '2027-02-01 09:00:00',
        int $id = 1
    ): RentalDocument {
        return new RentalDocument(
            id: $id,
            bookingId: 7,
            fileId: 100 + $id,
            type: $type,
            version: 1,
            isForRenter: true,
            originalName: 'contrat.pdf',
            sizeBytes: 1024,
            sentAt: $sentAt,
            createdByMemberId: null,
            createdAt: new \DateTimeImmutable($createdAt)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payment(array $overrides = []): array
    {
        return array_merge([
            'available' => true,
            'enabled' => true,
            'total_cents' => 60000,
            'received_cents' => 0,
            'deposit_cents' => 15000,
            'deposit_received' => false,
            'fully_paid' => false,
            'security_deposit' => ['status' => SecurityDepositStatus::NONE, 'amount_cents' => null],
        ], $overrides);
    }

    /**
     * @param array<int, array{0: InventoryState, 1: InventoryState}> $states
     * @return array<int, array<string, mixed>>
     */
    private function inventory(array $states): array
    {
        $rows = [];
        foreach ($states as $index => $pair) {
            $rows[] = [
                'id' => $index + 1,
                'label' => 'Clés',
                'sort_order' => $index,
                'arrival_state' => $pair[0],
                'departure_state' => $pair[1],
                'arrival_note' => null,
                'departure_note' => null,
            ];
        }

        return $rows;
    }

    private function meter(int $id = 1): RentalMeter
    {
        return new RentalMeter($id, 1, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null, 0, true);
    }

    private function reading(string $value): MeterReading
    {
        return new MeterReading(
            id: 1,
            bookingId: 7,
            meterId: 1,
            phase: ReadingPhase::ARRIVAL,
            valueMilli: (int) ((float) $value * MeterReading::SCALE),
            readAt: new \DateTimeImmutable('2027-07-01 10:00:00'),
            fileId: null,
            comment: null,
            recordedByMemberId: null
        );
    }

    // ── The contract ────────────────────────────────────────────────────

    public function testAContractThatWasSentTicksItsLine(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00'))],
            $this->payment(),
            null,
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_SENT]);
        $this->assertSame('04/03/2027', $evidence->details[BookingMilestones::CONTRACT_SENT]);
    }

    /**
     * Generating the contract is not sending it — the line is about what
     * left for the renter.
     */
    public function testAGeneratedButUnsentContractLeavesTheLineOutstanding(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT)],
            $this->payment(),
            null,
            null,
            null
        );

        $this->assertArrayHasKey(BookingMilestones::CONTRACT_SENT, $evidence->done);
        $this->assertFalse($evidence->done[BookingMilestones::CONTRACT_SENT]);
    }

    public function testTheAcceptedLineWaitsForTheSignedContractAndMeanwhileShowsTheConditions(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(new \DateTimeImmutable('2027-01-01 10:00:00')),
            [$this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00'))],
            $this->payment(),
            null,
            null,
            null
        );

        $this->assertFalse($evidence->done[BookingMilestones::CONTRACT_ACCEPTED]);
        $this->assertSame(
            'conditions acceptées le 01/01/2027',
            $evidence->details[BookingMilestones::CONTRACT_ACCEPTED]
        );
    }

    public function testASignedContractOnFileTicksTheAcceptedLine(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [
                $this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00')),
                $this->document(DocumentType::SIGNED_CONTRACT, null, '2027-03-11 12:00:00', 2),
            ],
            $this->payment(),
            null,
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_ACCEPTED]);
        $this->assertSame('11/03/2027', $evidence->details[BookingMilestones::CONTRACT_ACCEPTED]);
    }

    /**
     * Documents unavailable is the one case that still renders greyed —
     * an absent key, not an unticked box.
     */
    public function testWithoutDocumentsTheContractLinesAreNotApplicable(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), null, $this->payment(), null, null, null);

        $this->assertArrayNotHasKey(BookingMilestones::CONTRACT_SENT, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::CONTRACT_ACCEPTED, $evidence->done);
    }

    // ── Money ───────────────────────────────────────────────────────────

    public function testTheDepositAndBalanceLinesFollowWhatTheAccountReceived(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['deposit_received' => true, 'fully_paid' => false]),
            null,
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::DEPOSIT_RECEIVED]);
        $this->assertFalse($evidence->done[BookingMilestones::BALANCE_RECEIVED]);
    }

    public function testABookingWithNoDepositAskedHasNoDepositLine(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['deposit_cents' => null]),
            null,
            null,
            null
        );

        $this->assertArrayNotHasKey(BookingMilestones::DEPOSIT_RECEIVED, $evidence->done);
        $this->assertArrayHasKey(BookingMilestones::BALANCE_RECEIVED, $evidence->done);
    }

    public function testPaymentsDisabledLeavesBothMoneyLinesOut(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['enabled' => false]),
            null,
            null,
            null
        );

        $this->assertArrayNotHasKey(BookingMilestones::DEPOSIT_RECEIVED, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::BALANCE_RECEIVED, $evidence->done);
    }

    public function testAHeldSecurityDepositIsReceivedButNotYetReturned(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['security_deposit' => [
                'status' => SecurityDepositStatus::RECEIVED,
                'amount_cents' => 50000,
            ]]),
            null,
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RECEIVED]);
        $this->assertFalse($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RETURNED]);
    }

    public function testAWithheldSecurityDepositCountsAsSettled(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['security_deposit' => [
                'status' => SecurityDepositStatus::PARTIALLY_WITHHELD,
                'amount_cents' => 50000,
                'returned_at' => '2027-07-20',
            ]]),
            null,
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RECEIVED]);
        $this->assertTrue($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RETURNED]);
        $this->assertSame('20/07/2027', $evidence->details[BookingMilestones::SECURITY_DEPOSIT_RETURNED]);
    }

    public function testNoSecurityDepositMeansNoSecurityDepositLines(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), null, null, null);

        $this->assertArrayNotHasKey(BookingMilestones::SECURITY_DEPOSIT_RECEIVED, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::SECURITY_DEPOSIT_RETURNED, $evidence->done);
    }

    // ── The stay ────────────────────────────────────────────────────────

    public function testAnInventoryTicksOnlyOncePhaseIsFullyObserved(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            $this->inventory([
                [InventoryState::OK, InventoryState::ISSUE],
                [InventoryState::MISSING, InventoryState::NOT_CHECKED],
            ]),
            [],
            null
        );

        // Every arrival line was looked at — "manquant" IS an observation.
        $this->assertTrue($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
        // One departure line was never checked, so the phase is unfinished.
        $this->assertFalse($evidence->done[BookingMilestones::DEPARTURE_INVENTORY]);
    }

    public function testAnAssetWithNoInventoryTemplateHasNoInventoryLines(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [], null);

        $this->assertArrayNotHasKey(BookingMilestones::ARRIVAL_INVENTORY, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::DEPARTURE_INVENTORY, $evidence->done);
    }

    public function testMeterReadingsNeedBothEnds(): void
    {
        $fee = new RentalFee(1, 'Électricité', RentalFee::NATURE_METER, 30, 'kWh');
        $onlyArrival = MeterConsumption::of($this->meter(), $this->reading('100'), null, $fee);
        $both = MeterConsumption::of($this->meter(2), $this->reading('100'), $this->reading('180'), $fee);

        $partial = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [$onlyArrival, $both], null);
        $complete = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [$both], null);

        $this->assertFalse($partial->done[BookingMilestones::METER_READINGS]);
        $this->assertTrue($complete->done[BookingMilestones::METER_READINGS]);
    }

    public function testAnAssetWithNoMeterHasNoMeterLine(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [], null);

        $this->assertArrayNotHasKey(BookingMilestones::METER_READINGS, $evidence->done);
    }

    public function testTheSettlementLineTicksOnlyOnceValidated(): void
    {
        $draft = $this->settlement(false);
        $validated = $this->settlement(true);

        $withDraft = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [], $draft);
        $withValidated = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [], $validated);
        $withNone = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [], null);

        $this->assertFalse($withDraft->done[BookingMilestones::FINAL_SETTLEMENT]);
        $this->assertSame('v2', $withDraft->details[BookingMilestones::FINAL_SETTLEMENT]);
        $this->assertTrue($withValidated->done[BookingMilestones::FINAL_SETTLEMENT]);
        // Applicable with no settlement yet: every stay ends with a
        // reckoning, even one with nothing metered.
        $this->assertFalse($withNone->done[BookingMilestones::FINAL_SETTLEMENT]);
    }

    /**
     * Without the stay module, what only that module records does not
     * apply — the meters, the settlement — but the walk-throughs still
     * happen, on paper: nothing here can derive them, so they are the lines
     * a manager ticks by hand (issue #462, D5).
     */
    public function testWithoutTheStayModuleTheWalkThroughsAreTickedByHand(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), null, null, null);

        $this->assertArrayNotHasKey(BookingMilestones::FINAL_SETTLEMENT, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::METER_READINGS, $evidence->done);
        $this->assertSame(
            [BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::DEPARTURE_INVENTORY],
            $evidence->offsite
        );
        $this->assertFalse($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
    }

    /**
     * An asset with no inventory template has nothing the stay page could
     * walk: same answer, with the stay module on.
     */
    public function testAnAssetWithNoInventoryHasItsWalkThroughsTickedByHand(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], [], null, false);

        $this->assertSame(
            [BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::DEPARTURE_INVENTORY],
            $evidence->offsite
        );
        // The settlement still derives from the stay module.
        $this->assertArrayHasKey(BookingMilestones::FINAL_SETTLEMENT, $evidence->done);
    }

    /**
     * **Where the stay page records the inventory, no line is ticked by
     * hand**, even with a stale mark lying around: the recorded lines are
     * the truth, and a hand tick beside them would be a second one.
     */
    public function testAnInventoryTheSiteKeepsIsNeverTickedByHand(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            [1 => ['arrival_state' => InventoryState::NOT_CHECKED, 'departure_state' => InventoryState::NOT_CHECKED]],
            [],
            null,
            true,
            [BookingMilestones::ARRIVAL_INVENTORY => ['at' => new \DateTimeImmutable('2027-03-01'), 'by' => 'Jeanne']]
        );

        $this->assertSame([], $evidence->offsite);
        $this->assertFalse($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
    }

    /**
     * A payment still owed says how much and against which date — the
     * sentence the journey's heading repeats when that line holds the
     * booking up (« échéance dépassée de 4 jours »).
     */
    public function testAnOwedPaymentSaysHowMuchAndWhen(): void
    {
        $payment = $this->payment([
            'received_cents' => 5000,
            'deposit_due_date' => '2027-03-01',
            'balance_due_date' => '2027-06-01',
        ]);
        $today = new \DateTimeImmutable('2027-03-05 14:00');

        $evidence = MilestoneEvidence::collect($this->booking(), [], $payment, null, null, null, true, [], $today);

        $this->assertSame(
            '100,00 € attendus — échéance dépassée de 4 jours',
            $evidence->details[BookingMilestones::DEPOSIT_RECEIVED]
        );
        $this->assertSame(
            '550,00 € attendus pour le 01/06/2027',
            $evidence->details[BookingMilestones::BALANCE_RECEIVED]
        );

        // Without a date to measure against, the amount alone.
        $undated = MilestoneEvidence::collect($this->booking(), [], $payment, null, null, null);
        $this->assertSame('100,00 € attendus', $undated->details[BookingMilestones::DEPOSIT_RECEIVED]);

        // And nothing at all once it is paid.
        $paid = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['received_cents' => 60000, 'deposit_received' => true, 'fully_paid' => true]),
            null,
            null,
            null,
            true,
            [],
            $today
        );
        $this->assertArrayNotHasKey(BookingMilestones::DEPOSIT_RECEIVED, $paid->details);
        $this->assertArrayNotHasKey(BookingMilestones::BALANCE_RECEIVED, $paid->details);
    }

    /**
     * A tick records who and when, and the line says so.
     */
    public function testAHandTickSaysWhoAndWhen(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            null,
            null,
            null,
            true,
            [BookingMilestones::DEPARTURE_INVENTORY => ['at' => new \DateTimeImmutable('2027-07-20 18:00'), 'by' => 'Jeanne Martin']]
        );

        $this->assertTrue($evidence->done[BookingMilestones::DEPARTURE_INVENTORY]);
        $this->assertSame('fait le 20/07/2027 par Jeanne Martin', $evidence->details[BookingMilestones::DEPARTURE_INVENTORY]);
        $this->assertFalse($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
    }

    /**
     * The end-to-end shape the page renders: the evidence feeds
     * BookingMilestones, and a line whose key is present is no longer
     * greyed.
     */
    public function testTheEvidenceFeedsTheRenderedChecklist(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00'))],
            $this->payment(),
            null,
            null,
            null
        );

        $milestones = BookingMilestones::for(
            $this->booking(),
            new \DateTimeImmutable('2027-03-05 09:00:00'),
            $evidence->done,
            $evidence->details
        );

        $byKey = [];
        foreach ($milestones as $milestone) {
            $byKey[$milestone->key] = $milestone;
        }

        $this->assertTrue($byKey[BookingMilestones::CONTRACT_SENT]->isApplicable);
        $this->assertTrue($byKey[BookingMilestones::CONTRACT_SENT]->isDone);
        $this->assertSame('04/03/2027', $byKey[BookingMilestones::CONTRACT_SENT]->detail);
        // Untouched by this evidence, and still greyed rather than unticked.
        $this->assertFalse($byKey[BookingMilestones::METER_READINGS]->isApplicable);
    }

    private function settlement(bool $validated): Settlement
    {
        return new Settlement(
            id: 3,
            bookingId: 7,
            version: 2,
            finalPersons: 18,
            lines: [],
            totalCents: 60000,
            alreadyPaidCents: 60000,
            balanceCents: 0,
            securityDepositWithheldCents: null,
            securityDepositReturnCents: null,
            isValidated: $validated,
            validatedAt: $validated ? new \DateTimeImmutable('2027-07-10 09:00:00') : null,
            validatedByMemberId: $validated ? 1 : null,
            createdByMemberId: 1,
            createdAt: new \DateTimeImmutable('2027-07-09 09:00:00')
        );
    }
}
