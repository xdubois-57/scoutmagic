<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\ConditionsVersion;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Stay\InventoryScope;
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
            reference: 'LOC-K7Q2M4',
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

    // ── The contract ────────────────────────────────────────────────────

    public function testAContractThatWasSentTicksItsLine(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00'))],
            $this->payment(),
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_SENT]);
        $this->assertSame('v1 le 04/03/2027', $evidence->details[BookingMilestones::CONTRACT_SENT]);
    }

    /**
     * Generating the contract is its own line (#708, IT-16), and it is not
     * sending it — the next line is about what left for the renter.
     */
    public function testAGeneratedButUnsentContractTicksOnlyTheGeneratedLine(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT)],
            $this->payment(),
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_GENERATED]);
        $this->assertSame('v1 du 01/02/2027', $evidence->details[BookingMilestones::CONTRACT_GENERATED]);
        $this->assertArrayHasKey(BookingMilestones::CONTRACT_SENT, $evidence->done);
        $this->assertFalse($evidence->done[BookingMilestones::CONTRACT_SENT]);
    }

    /**
     * The conditions are accepted with the request, never with the
     * contract (#708, IT-16): a fact « Demande reçue » shows, naming the
     * archived version when it is found.
     */
    public function testTheAcceptedConditionsAreAFactOfTheRequest(): void
    {
        $booking = $this->booking(new \DateTimeImmutable('2027-01-01 10:00:00'));

        $evidence = MilestoneEvidence::collect($booking, [], $this->payment(), null, null);
        $this->assertSame('conditions acceptées le 01/01/2027', $evidence->details['request_received']);

        $version = new ConditionsVersion(3, 'a1b2c3d4e5f6', str_repeat('a', 64), '<p>…</p>', new \DateTimeImmutable('2026-09-12'));
        $evidence = MilestoneEvidence::collect($booking, [], $this->payment(), null, null, true, [], null, $version);
        $this->assertSame('conditions acceptées, version du 12/09/2026', $evidence->details['request_received']);

        $line = BookingMilestones::for($booking, new \DateTimeImmutable('2027-02-01'), $evidence->done, $evidence->details)[0];
        $this->assertSame('request_received', $line->key);
        $this->assertStringEndsWith(' — conditions acceptées, version du 12/09/2026', (string) $line->detail);
    }

    /**
     * A version archived after the renter accepted it — a wording older
     * than the archive, archived on the first read after the upgrade — is
     * dated by that read: naming it would post-date the acceptance, so the
     * acceptance date is said instead.
     */
    public function testAVersionArchivedAfterTheAcceptanceIsNotDatedByItsArchive(): void
    {
        $booking = $this->booking(new \DateTimeImmutable('2027-01-01 10:00:00'));
        $version = new ConditionsVersion(
            3,
            'a1b2c3d4e5f6',
            str_repeat('a', 64),
            '<p>…</p>',
            new \DateTimeImmutable('2027-03-15')
        );

        $evidence = MilestoneEvidence::collect(
            $booking,
            [],
            $this->payment(),
            null,
            null,
            acceptedConditions: $version
        );

        $this->assertSame('conditions acceptées le 01/01/2027', $evidence->details['request_received']);
    }

    /** The renter's copy alone is half the agreement: the unit still signs. */
    public function testTheRentersSignedCopyTicksItsLineAndLeavesTheCountersignature(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [
                $this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00')),
                $this->document(DocumentType::SIGNED_COPY, null, '2027-03-11 12:00:00', 2),
            ],
            $this->payment(),
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::SIGNED_COPY_RECEIVED]);
        $this->assertSame('11/03/2027', $evidence->details[BookingMilestones::SIGNED_COPY_RECEIVED]);
        $this->assertFalse($evidence->done[BookingMilestones::CONTRACT_COUNTERSIGNED]);
    }

    /**
     * A contract signed by both parties filed by hand — countersigned on
     * paper, scanned whole — is the renter's signature too.
     */
    public function testAContractSignedByBothPartiesTicksBothSignatureLines(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [
                $this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00')),
                $this->document(DocumentType::SIGNED_CONTRACT, null, '2027-03-11 12:00:00', 2),
            ],
            $this->payment(),
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::SIGNED_COPY_RECEIVED]);
        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_COUNTERSIGNED]);
        $this->assertSame('11/03/2027', $evidence->details[BookingMilestones::CONTRACT_COUNTERSIGNED]);
    }

    /**
     * Documents unavailable is the one case that still renders greyed —
     * an absent key, not an unticked box.
     */
    public function testWithoutDocumentsTheContractLinesAreNotApplicable(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), null, $this->payment(), null, null);

        foreach ([
            BookingMilestones::CONTRACT_GENERATED,
            BookingMilestones::CONTRACT_SENT,
            BookingMilestones::SIGNED_COPY_RECEIVED,
            BookingMilestones::CONTRACT_COUNTERSIGNED,
        ] as $key) {
            $this->assertArrayNotHasKey($key, $evidence->done, $key);
        }
    }

    // ── Money ───────────────────────────────────────────────────────────

    public function testTheDepositAndBalanceLinesFollowWhatTheAccountReceived(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['deposit_received' => true, 'fully_paid' => false]),
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
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RECEIVED]);
        $this->assertTrue($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RETURNED]);
        $this->assertSame('20/07/2027', $evidence->details[BookingMilestones::SECURITY_DEPOSIT_RETURNED]);
    }

    /**
     * The balance's « attendus » is the single receivable's — rental and
     * security deposit together — so the deposit's own line names no amount:
     * 1 000 € of rent and 500 € of deposit never read as 2 000 € owed.
     */
    public function testTheSecurityDepositOwedIsCountedOnceInTheBalance(): void
    {
        $payment = $this->payment([
            'total_cents' => 150000,
            'balance_due_date' => '2027-06-01',
            'security_deposit' => [
                'status' => SecurityDepositStatus::TO_RECEIVE,
                'amount_cents' => 50000,
                'due_date' => '2027-06-01',
            ],
        ]);

        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $payment,
            null,
            null,
            true,
            [],
            new \DateTimeImmutable('2027-03-05')
        );

        $this->assertSame(
            '1 500,00 € attendus pour le 01/06/2027',
            $evidence->details[BookingMilestones::BALANCE_RECEIVED]
        );
        $this->assertFalse($evidence->done[BookingMilestones::SECURITY_DEPOSIT_RECEIVED]);
        $this->assertArrayNotHasKey(BookingMilestones::SECURITY_DEPOSIT_RECEIVED, $evidence->details);

        // Payments off: no balance line counts it, so its own line does.
        $offline = MilestoneEvidence::collect(
            $this->booking(),
            [],
            array_merge($payment, ['enabled' => false]),
            null,
            null
        );
        $this->assertSame('500,00 € attendus', $offline->details[BookingMilestones::SECURITY_DEPOSIT_RECEIVED]);
    }

    public function testNoSecurityDepositMeansNoSecurityDepositLines(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), null, null);

        $this->assertArrayNotHasKey(BookingMilestones::SECURITY_DEPOSIT_RECEIVED, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::SECURITY_DEPOSIT_RETURNED, $evidence->done);
    }

    // ── The stay ────────────────────────────────────────────────────────

    /**
     * An inventory is done when it is VALIDATED (#708, IT-17) — its PDF
     * sent to the renter — never because its lines were filled in.
     */
    public function testAnInventoryTicksOnceValidated(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            ['arrival' => [
                'validated_at' => new \DateTimeImmutable('2027-07-01 18:30'),
                'validated_by_member_id' => null,
                'document_id' => 4,
            ]],
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
        $this->assertSame('validé le 01/07/2027', $evidence->details[BookingMilestones::ARRIVAL_INVENTORY]);
        $this->assertFalse($evidence->done[BookingMilestones::DEPARTURE_INVENTORY]);
        $this->assertSame([], $evidence->minimal);
    }

    /** No « Relevés de compteurs » line any more: the readings are part of each inventory. */
    public function testThereIsNoMeterLine(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], null);

        $this->assertArrayNotHasKey('meter_readings', $evidence->done);
    }

    public function testTheSettlementLineTicksOnlyOnceValidated(): void
    {
        $draft = $this->settlement(false);
        $validated = $this->settlement(true);

        $withDraft = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], $draft);
        $withValidated = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], $validated);
        $withNone = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], null);

        $this->assertFalse($withDraft->done[BookingMilestones::FINAL_SETTLEMENT]);
        $this->assertSame('v2', $withDraft->details[BookingMilestones::FINAL_SETTLEMENT]);
        $this->assertTrue($withValidated->done[BookingMilestones::FINAL_SETTLEMENT]);
        // Applicable with no settlement yet: every stay ends with a
        // reckoning, even one with nothing metered.
        $this->assertFalse($withNone->done[BookingMilestones::FINAL_SETTLEMENT]);
    }

    /**
     * Without the stay module there is no inventory screen at all: what
     * only that module records does not apply — the settlement, and the
     * walk-throughs, which are signed there and never ticked (#989, lot G).
     */
    public function testWithoutTheStayModuleTheWalkThroughsDoNotApply(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), null, null);

        $this->assertArrayNotHasKey(BookingMilestones::FINAL_SETTLEMENT, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::ARRIVAL_INVENTORY, $evidence->done);
        $this->assertArrayNotHasKey(BookingMilestones::DEPARTURE_INVENTORY, $evidence->done);
        $this->assertSame([], $evidence->minimal);
    }

    /**
     * An asset with neither items nor meters has nothing an inventory
     * could walk: each walk-through is a minimal inventory (#989, lot G),
     * still to do until it is signed, its step leading to the signing.
     */
    public function testAnAssetWithNoInventoryHasMinimalInventories(): void
    {
        $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], null, false);

        $this->assertSame(
            [BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::DEPARTURE_INVENTORY],
            $evidence->minimal
        );
        $this->assertFalse($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
        $this->assertFalse($evidence->done[BookingMilestones::DEPARTURE_INVENTORY]);
        // The settlement still derives from the stay module.
        $this->assertArrayHasKey(BookingMilestones::FINAL_SETTLEMENT, $evidence->done);
    }

    /**
     * A minimal inventory signed is done like a full one, and says it was
     * minimal; what the step reads is the validation's own scope, so a
     * meter added to the asset afterwards changes nothing of it.
     */
    public function testASignedMinimalInventoryIsDone(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            ['arrival' => [
                'validated_at' => new \DateTimeImmutable('2027-07-01 18:30'),
                'scope' => InventoryScope::MINIMAL,
                'remark' => null,
            ]],
            null,
            true
        );

        $this->assertTrue($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
        $this->assertSame('minimal, validé le 01/07/2027', $evidence->details[BookingMilestones::ARRIVAL_INVENTORY]);
        $this->assertSame([BookingMilestones::ARRIVAL_INVENTORY], $evidence->minimal);
        $this->assertSame([], $evidence->manual);
    }

    /**
     * **The bare tick is gone for the inventories** (#989, lot G, Q4): a
     * mark stored before counts for nothing — on an asset that keeps an
     * inventory or one with nothing to walk — so the step is neither done
     * nor reopenable by hand.
     */
    public function testAnInventoryMarkCountsForNothing(): void
    {
        $marks = [
            BookingMilestones::ARRIVAL_INVENTORY => ['at' => new \DateTimeImmutable('2027-03-01'), 'by' => 'Jeanne'],
            BookingMilestones::DEPARTURE_INVENTORY => ['at' => new \DateTimeImmutable('2027-03-02'), 'by' => 'Jeanne'],
        ];

        foreach ([true, false] as $keeps) {
            $evidence = MilestoneEvidence::collect($this->booking(), [], $this->payment(), [], null, $keeps, $marks);

            $this->assertFalse($evidence->done[BookingMilestones::ARRIVAL_INVENTORY]);
            $this->assertFalse($evidence->done[BookingMilestones::DEPARTURE_INVENTORY]);
            $this->assertArrayNotHasKey(BookingMilestones::ARRIVAL_INVENTORY, $evidence->details);
            $this->assertSame([], $evidence->manual);
        }
    }

    /**
     * **The site's answer wins.** A deposit ticked as paid in cash, then
     * reconciled in Finances, is the site's again: done, not a hand tick,
     * and it no longer reopens.
     */
    public function testAStepTheSiteVerifiesIsNoLongerAHandTick(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT, new \DateTimeImmutable('2027-03-04 08:00:00'))],
            $this->payment(),
            null,
            null,
            true,
            [BookingMilestones::CONTRACT_SENT => ['at' => new \DateTimeImmutable('2027-03-01'), 'by' => 'Jeanne']]
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_SENT]);
        $this->assertSame([], $evidence->manual);
        $this->assertStringNotContainsString('à la main', $evidence->details[BookingMilestones::CONTRACT_SENT]);
    }

    /**
     * « Conditions et contrat acceptés » ticked by hand before the contract
     * had steps of its own stood for the whole agreement: the mark carries
     * over to the renter's signed copy and the countersignature, so a
     * booking that was ready to confirm still is.
     */
    public function testAHandTickOnTheRetiredAgreementStepCarriesOverToBothSignatures(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            null,
            null,
            true,
            ['contract_accepted' => ['at' => new \DateTimeImmutable('2027-03-01'), 'by' => 'Jeanne']]
        );

        $this->assertTrue($evidence->done[BookingMilestones::SIGNED_COPY_RECEIVED]);
        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_COUNTERSIGNED]);
        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_GENERATED], 'the earlier steps follow');
        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_SENT], 'the earlier steps follow');
        $this->assertSame(
            [BookingMilestones::CONTRACT_COUNTERSIGNED],
            $evidence->manual,
            'the copy does not reopen while the countersignature stands'
        );
        $this->assertArrayNotHasKey('contract_accepted', $evidence->done);
    }

    /**
     * The contract's steps follow one another: a contract signed on paper
     * and filed by hand was drawn up and sent, even with no trace of either
     * on the site — the journey never asks to generate a signed contract.
     * An earlier step done says nothing of the later ones.
     */
    public function testALaterContractStepDoneSaysTheEarlierOnesWere(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::SIGNED_CONTRACT)],
            $this->payment(),
            null,
            null
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_GENERATED]);
        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_SENT]);
        $this->assertSame([], $evidence->manual, 'implied, never ticked: nothing to reopen');

        $generatedOnly = MilestoneEvidence::collect(
            $this->booking(),
            [$this->document(DocumentType::CONTRACT)],
            $this->payment(),
            null,
            null
        );

        $this->assertTrue($generatedOnly->done[BookingMilestones::CONTRACT_GENERATED]);
        $this->assertFalse($generatedOnly->done[BookingMilestones::CONTRACT_SENT]);
        $this->assertFalse($generatedOnly->done[BookingMilestones::SIGNED_COPY_RECEIVED]);
    }

    /**
     * Two contract steps ticked by hand: only the later one reopens. The
     * earlier one, reopened while the later stands, would be done again on
     * the next read — the page would show it done and the booking would
     * have gone back to « Demande reçue » underneath.
     */
    public function testAnEarlierContractStepDoesNotReopenWhileALaterOneStands(): void
    {
        $at = ['at' => new \DateTimeImmutable('2027-03-01'), 'by' => 'Jeanne'];
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(),
            null,
            null,
            true,
            [BookingMilestones::CONTRACT_SENT => $at, BookingMilestones::SIGNED_COPY_RECEIVED => $at]
        );

        $this->assertTrue($evidence->done[BookingMilestones::CONTRACT_SENT]);
        $this->assertSame([BookingMilestones::SIGNED_COPY_RECEIVED], $evidence->manual);
    }

    /** A step that does not apply here cannot be ticked into being. */
    public function testAHandTickCannotMakeAStepApplicable(): void
    {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            null,
            $this->payment(),
            null,
            null,
            true,
            [BookingMilestones::CONTRACT_SENT => ['at' => new \DateTimeImmutable('2027-03-01'), 'by' => 'Jeanne']]
        );

        $this->assertArrayNotHasKey(BookingMilestones::CONTRACT_SENT, $evidence->done);
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

        $evidence = MilestoneEvidence::collect($this->booking(), [], $payment, null, null, true, [], $today);

        $this->assertSame(
            '100,00 € attendus — échéance dépassée de 4 jours',
            $evidence->details[BookingMilestones::DEPOSIT_RECEIVED]
        );
        $this->assertSame(
            '550,00 € attendus pour le 01/06/2027',
            $evidence->details[BookingMilestones::BALANCE_RECEIVED]
        );

        // Without a date to measure against, the amount alone.
        $undated = MilestoneEvidence::collect($this->booking(), [], $payment, null, null);
        $this->assertSame('100,00 € attendus', $undated->details[BookingMilestones::DEPOSIT_RECEIVED]);

        // And nothing at all once it is paid.
        $paid = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['received_cents' => 60000, 'deposit_received' => true, 'fully_paid' => true]),
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
            [],
            $this->settlement(false),
            true,
            [BookingMilestones::FINAL_SETTLEMENT => ['at' => new \DateTimeImmutable('2027-07-20 18:00'), 'by' => 'Jeanne Martin']]
        );

        $this->assertTrue($evidence->done[BookingMilestones::FINAL_SETTLEMENT]);
        $this->assertSame('Coché à la main par Jeanne Martin le 20/07/2027', $evidence->details[BookingMilestones::FINAL_SETTLEMENT]);
        $this->assertSame([BookingMilestones::FINAL_SETTLEMENT], $evidence->manual);
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
        $this->assertSame('v1 le 04/03/2027', $byKey[BookingMilestones::CONTRACT_SENT]->detail);
        // Untouched by this evidence, and still greyed rather than unticked.
        $this->assertFalse($byKey[BookingMilestones::SECURITY_DEPOSIT_RECEIVED]->isApplicable);
    }

    // ── A damage under assessment (#980, lot C) ─────────────────────────

    /**
     * @return array<string, \Modules\Rental\Booking\BookingMilestone>
     */
    private function heldBack(
        bool $damageUnderAssessment,
        SecurityDepositStatus $deposit = SecurityDepositStatus::RECEIVED,
        ?Settlement $settlement = null
    ): array {
        $evidence = MilestoneEvidence::collect(
            $this->booking(),
            [],
            $this->payment(['security_deposit' => ['status' => $deposit, 'amount_cents' => 50000]]),
            [],
            $settlement,
            true,
            [],
            null,
            null,
            $damageUnderAssessment
        );

        $byKey = [];
        foreach (BookingMilestones::for(
            $this->booking(),
            new \DateTimeImmutable('2027-07-10 09:00:00'),
            $evidence->done,
            $evidence->details,
            $evidence->minimal,
            $evidence->manual,
            $evidence->awaitingAssessment
        ) as $milestone) {
            $byKey[$milestone->key] = $milestone;
        }

        return $byKey;
    }

    public function testADamageUnderAssessmentHoldsTheSettlementAndTheDepositBack(): void
    {
        $byKey = $this->heldBack(true);

        $deposit = $byKey[BookingMilestones::SECURITY_DEPOSIT_RETURNED];
        $this->assertFalse($deposit->isDone);
        $this->assertTrue($deposit->awaitsAssessment);
        $this->assertStringContainsString("dégât est en cours d'évaluation", (string) $deposit->explanation);
        $this->assertStringContainsString(
            "la caution reste retenue jusqu'à son règlement",
            (string) $deposit->explanation
        );
        // It leads to the incident, never to the refund.
        $this->assertSame("Trancher l'incident", $deposit->action?->label);
        $this->assertSame(\Modules\Rental\Booking\BookingBox::INVENTORY, $deposit->action->box);

        $settlement = $byKey[BookingMilestones::FINAL_SETTLEMENT];
        $this->assertTrue($settlement->awaitsAssessment);
        $this->assertStringContainsString(
            "ne se valide qu'une fois l'incident tranché",
            (string) $settlement->explanation
        );
        $this->assertSame('Établir le décompte', $settlement->action?->label);
    }

    public function testWithoutADamageUnderAssessmentTheStepsAskWhatTheyAlwaysAsked(): void
    {
        $byKey = $this->heldBack(false);

        $deposit = $byKey[BookingMilestones::SECURITY_DEPOSIT_RETURNED];
        $this->assertFalse($deposit->awaitsAssessment);
        $this->assertSame('Rendre la caution', $deposit->action?->label);
        $this->assertFalse($byKey[BookingMilestones::FINAL_SETTLEMENT]->awaitsAssessment);
    }

    public function testAStepAlreadyDoneIsNotHeldBack(): void
    {
        // A damage sent back to its assessment after the settlement was
        // validated and the deposit given back: what is done stays done.
        $byKey = $this->heldBack(true, SecurityDepositStatus::RETURNED, $this->settlement(true));

        $this->assertTrue($byKey[BookingMilestones::SECURITY_DEPOSIT_RETURNED]->isDone);
        $this->assertFalse($byKey[BookingMilestones::SECURITY_DEPOSIT_RETURNED]->awaitsAssessment);
        $this->assertTrue($byKey[BookingMilestones::FINAL_SETTLEMENT]->isDone);
        $this->assertFalse($byKey[BookingMilestones::FINAL_SETTLEMENT]->awaitsAssessment);
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
