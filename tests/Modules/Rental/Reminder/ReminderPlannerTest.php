<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Reminder;

use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Calendar\PublishFrom;
use Modules\Rental\Compliance\ComplianceItem;
use Modules\Rental\Reminder\DueReminder;
use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Reminder\ReminderPlanner;
use Modules\Rental\Repository\RentalAsset;
use PHPUnit\Framework\TestCase;

/**
 * What is due today, and — more importantly — what is not (§6.29).
 *
 * `ReminderPlanner` is pure, which is the whole reason these rules are
 * testable at all: no database, no clock, no scheduler. That matters
 * because a reminder that fires on the wrong day fails silently — nobody
 * notices a message they never expected — so the boundaries are asserted
 * from both sides throughout.
 */
class ReminderPlannerTest extends TestCase
{
    private ReminderPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ReminderPlanner();
    }

    private function asset(): RentalAsset
    {
        return new RentalAsset(
            id: 1,
            assetType: 'Local',
            name: 'Local Saint-Georges',
            slug: 'local-saint-georges',
            capacity: 60,
            quantity: 1,
            arrivalTime: '18:00',
            departureTime: '11:00',
            emergencyPhone: null,
            isArchived: false,
            isPublic: true,
            vatExemptionNote: null,
            calendarPublicationEnabled: false,
            calendarId: null,
            calendarPublishFrom: PublishFrom::CONFIRMATION
        );
    }

    private function booking(
        BookingStatus $status = BookingStatus::CONFIRMED,
        string $arrival = '2027-07-01',
        string $departure = '2027-07-04',
        string $receivedAt = '2027-01-01 10:00:00',
        ?\DateTimeImmutable $holdUntil = null
    ): RentalBooking {
        return new RentalBooking(
            id: 7,
            assetId: 1,
            reference: 'LOC-2027-0042',
            arrivalDate: $arrival,
            departureDate: $departure,
            units: 1,
            estimatedPersons: 20,
            renterCategoryId: null,
            renterName: 'Jeanne Martin',
            renterEmail: 'jeanne@example.be',
            renterPhone: null,
            renterOrganisation: null,
            purpose: null,
            renterComment: null,
            status: $status,
            receivedAt: new \DateTimeImmutable($receivedAt),
            finalAt: null,
            holdUntil: $holdUntil,
            holdOrigin: $holdUntil !== null ? HoldOrigin::AUTOMATIC : null,
            estimatedPrice: null,
            estimatedTotalCents: null,
            agreedPrice: null,
            agreedTotalCents: null,
            conditionsVersion: null,
            conditionsHash: null,
            conditionsAcceptedAt: null,
            privacyVersion: null,
            privacyHash: null,
            privacyAcknowledgedAt: null
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payment(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'deposit_received' => false,
            'fully_paid' => false,
            'deposit_due_date' => null,
            'balance_due_date' => null,
            'security_deposit' => [
                'amount_cents' => null,
                'received_cents' => 0,
                'due_date' => null,
                'returned_at' => null,
            ],
        ], $overrides);
    }

    /**
     * @param DueReminder[] $due
     * @return string[]
     */
    private static function kinds(array $due): array
    {
        return array_map(static fn(DueReminder $reminder) => $reminder->kind->value, $due);
    }

    /**
     * @param array<string, mixed> $payment
     * @return DueReminder[]
     */
    private function plan(
        RentalBooking $booking,
        string $today,
        array $payment = [],
        bool $arrivalInventory = true,
        bool $departureInventory = true,
        bool $hasContract = true,
        bool $hasSettlement = true
    ): array {
        return $this->planner->forBooking(
            $booking,
            $this->asset(),
            $payment === [] ? ['enabled' => false] : $payment,
            ['arrival' => $arrivalInventory, 'departure' => $departureInventory],
            $hasContract,
            $hasSettlement,
            new \DateTimeImmutable($today)
        );
    }

    // ── Handling the request ────────────────────────────────────────────

    public function testAFreshRequestIsNotChasedYet(): void
    {
        $booking = $this->booking(BookingStatus::RECEIVED, receivedAt: '2027-01-01 10:00:00');

        $this->assertNotContains(
            ReminderKind::UNANSWERED_REQUEST->value,
            self::kinds($this->plan($booking, '2027-01-02'))
        );
    }

    public function testARequestNobodyAnsweredIsChased(): void
    {
        $booking = $this->booking(BookingStatus::RECEIVED, receivedAt: '2027-01-01 10:00:00');

        $this->assertContains(
            ReminderKind::UNANSWERED_REQUEST->value,
            self::kinds($this->plan($booking, '2027-01-05'))
        );
    }

    public function testAnAnsweredRequestIsNeverChased(): void
    {
        // The chase is about silence, not about age: a request that reached
        // any other status has been looked at.
        $booking = $this->booking(BookingStatus::REVIEWING, receivedAt: '2027-01-01 10:00:00');

        $this->assertNotContains(
            ReminderKind::UNANSWERED_REQUEST->value,
            self::kinds($this->plan($booking, '2027-02-01'))
        );
    }

    public function testAHoldAboutToLapseIsMentioned(): void
    {
        // The dates are about to become free for everybody again, which is
        // a decision the unit should make rather than discover.
        $booking = $this->booking(
            BookingStatus::RECEIVED,
            receivedAt: '2027-01-01 10:00:00',
            holdUntil: new \DateTimeImmutable('2027-01-02 12:00:00')
        );

        $this->assertContains(
            ReminderKind::HOLD_EXPIRING->value,
            self::kinds($this->plan($booking, '2027-01-02 00:00:00'))
        );
    }

    public function testAHoldWithDaysLeftIsNotMentioned(): void
    {
        $booking = $this->booking(
            BookingStatus::RECEIVED,
            receivedAt: '2027-01-01 10:00:00',
            holdUntil: new \DateTimeImmutable('2027-01-20 12:00:00')
        );

        $this->assertNotContains(
            ReminderKind::HOLD_EXPIRING->value,
            self::kinds($this->plan($booking, '2027-01-02 00:00:00'))
        );
    }

    public function testARefusedBookingProducesNothingAtAll(): void
    {
        // Nothing about money, paperwork or a stay applies to a booking
        // that is not going ahead.
        $booking = $this->booking(BookingStatus::REFUSED, arrival: '2027-07-01');

        $this->assertSame([], $this->plan(
            $booking,
            '2027-06-28',
            $this->payment(['deposit_due_date' => '2027-06-01']),
            arrivalInventory: false,
            hasContract: false,
            hasSettlement: false
        ));
    }

    // ── Money ───────────────────────────────────────────────────────────

    public function testAnOverdueDepositIsChased(): void
    {
        $due = $this->plan($this->booking(), '2027-06-10', $this->payment([
            'deposit_due_date' => '2027-06-01',
            'deposit_received' => false,
        ]));

        $this->assertContains(ReminderKind::DEPOSIT_MISSING->value, self::kinds($due));
    }

    public function testADepositThatWasPaidIsNotChased(): void
    {
        $due = $this->plan($this->booking(), '2027-06-10', $this->payment([
            'deposit_due_date' => '2027-06-01',
            'deposit_received' => true,
        ]));

        $this->assertNotContains(ReminderKind::DEPOSIT_MISSING->value, self::kinds($due));
    }

    public function testADepositNotYetDueIsNotChased(): void
    {
        $due = $this->plan($this->booking(), '2027-05-20', $this->payment([
            'deposit_due_date' => '2027-06-01',
        ]));

        $this->assertNotContains(ReminderKind::DEPOSIT_MISSING->value, self::kinds($due));
    }

    public function testNothingIsSaidAboutMoneyWhenPaymentsAreOffForTheAsset(): void
    {
        // There is nothing truthful to say: no due date, no received
        // amount, no receivable.
        $due = $this->plan($this->booking(), '2027-06-10', ['enabled' => false]);

        $this->assertNotContains(ReminderKind::DEPOSIT_MISSING->value, self::kinds($due));
        $this->assertNotContains(ReminderKind::BALANCE_MISSING->value, self::kinds($due));
    }

    public function testAnOverdueBalanceIsChased(): void
    {
        $due = $this->plan($this->booking(), '2027-06-25', $this->payment([
            'balance_due_date' => '2027-06-20',
            'fully_paid' => false,
        ]));

        $this->assertContains(ReminderKind::BALANCE_MISSING->value, self::kinds($due));
    }

    public function testAnOverdueSecurityDepositIsChased(): void
    {
        $due = $this->plan($this->booking(), '2027-06-25', $this->payment([
            'security_deposit' => [
                'amount_cents' => 30000,
                'received_cents' => 0,
                'due_date' => '2027-06-20',
                'returned_at' => null,
            ],
        ]));

        $this->assertContains(ReminderKind::SECURITY_DEPOSIT_MISSING->value, self::kinds($due));
    }

    public function testASecurityDepositStillHeldAfterTheStayIsChased(): void
    {
        // The renter is owed their money back and nobody but the unit knows
        // it is sitting there.
        $due = $this->plan($this->booking(), '2027-07-20', $this->payment([
            'security_deposit' => [
                'amount_cents' => 30000,
                'received_cents' => 30000,
                'due_date' => '2027-06-20',
                'returned_at' => null,
            ],
        ]));

        $this->assertContains(ReminderKind::SECURITY_DEPOSIT_TO_RETURN->value, self::kinds($due));
    }

    public function testASecurityDepositAlreadyReturnedIsNotChased(): void
    {
        $due = $this->plan($this->booking(), '2027-08-20', $this->payment([
            'security_deposit' => [
                'amount_cents' => 30000,
                'received_cents' => 30000,
                'due_date' => '2027-06-20',
                'returned_at' => '2027-07-15 10:00:00',
            ],
        ]));

        $this->assertNotContains(ReminderKind::SECURITY_DEPOSIT_TO_RETURN->value, self::kinds($due));
    }

    public function testADepositIsNotChasedForReturnTheDayAfterTheStay(): void
    {
        // A unit gets a fortnight to check the hall and hand the money
        // back before anybody is nudged about it.
        $due = $this->plan($this->booking(), '2027-07-05', $this->payment([
            'security_deposit' => [
                'amount_cents' => 30000,
                'received_cents' => 30000,
                'due_date' => '2027-06-20',
                'returned_at' => null,
            ],
        ]));

        $this->assertNotContains(ReminderKind::SECURITY_DEPOSIT_TO_RETURN->value, self::kinds($due));
    }

    // ── Paperwork and the stay ──────────────────────────────────────────

    public function testAMissingContractIsChasedAsTheStayApproaches(): void
    {
        $due = $this->plan($this->booking(), '2027-06-25', hasContract: false);

        $this->assertContains(ReminderKind::CONTRACT_MISSING->value, self::kinds($due));
    }

    public function testAMissingContractIsNotChasedMonthsAhead(): void
    {
        $due = $this->plan($this->booking(), '2027-03-01', hasContract: false);

        $this->assertNotContains(ReminderKind::CONTRACT_MISSING->value, self::kinds($due));
    }

    public function testAContractThatExistsIsNeverChased(): void
    {
        $due = $this->plan($this->booking(), '2027-06-25', hasContract: true);

        $this->assertNotContains(ReminderKind::CONTRACT_MISSING->value, self::kinds($due));
    }

    public function testThePracticalInfoEmailComesDueAWeekBefore(): void
    {
        $this->assertContains(
            ReminderKind::PRACTICAL_INFO->value,
            self::kinds($this->plan($this->booking(), '2027-06-27'))
        );
    }

    public function testThePracticalInfoEmailIsNotDueAMonthBefore(): void
    {
        $this->assertNotContains(
            ReminderKind::PRACTICAL_INFO->value,
            self::kinds($this->plan($this->booking(), '2027-06-01'))
        );
    }

    public function testThePracticalInfoEmailIsNotSentAfterTheStayHasStarted(): void
    {
        $this->assertNotContains(
            ReminderKind::PRACTICAL_INFO->value,
            self::kinds($this->plan($this->booking(), '2027-07-02'))
        );
    }

    public function testAMissedDayStillSendsThePracticalInfoTheNextDay(): void
    {
        // Nothing fires "on" a date — every rule is "is this true today" —
        // so a shared host whose cron did not run yesterday sends today
        // rather than never.
        foreach (['2027-06-27', '2027-06-28', '2027-06-30'] as $day) {
            $this->assertContains(
                ReminderKind::PRACTICAL_INFO->value,
                self::kinds($this->plan($this->booking(), $day)),
                'Nothing should depend on the run happening on one exact day: ' . $day
            );
        }
    }

    public function testTheArrivalInventoryIsChasedDuringTheStay(): void
    {
        $due = $this->plan($this->booking(), '2027-07-02', arrivalInventory: false);

        $this->assertContains(ReminderKind::ARRIVAL_INVENTORY->value, self::kinds($due));
    }

    public function testTheArrivalInventoryIsNotChasedBeforeAnybodyArrives(): void
    {
        $due = $this->plan($this->booking(), '2027-06-29', arrivalInventory: false);

        $this->assertNotContains(ReminderKind::ARRIVAL_INVENTORY->value, self::kinds($due));
    }

    public function testTheDepartureInventoryIsChasedOnceTheyHaveLeft(): void
    {
        $due = $this->plan($this->booking(), '2027-07-05', departureInventory: false);

        $this->assertContains(ReminderKind::DEPARTURE_INVENTORY->value, self::kinds($due));
    }

    public function testTheDepartureInventoryIsNotChasedWhileTheyAreStillThere(): void
    {
        $due = $this->plan($this->booking(), '2027-07-03', departureInventory: false);

        $this->assertNotContains(ReminderKind::DEPARTURE_INVENTORY->value, self::kinds($due));
    }

    public function testTheSettlementIsChasedAWeekAfterTheStay(): void
    {
        $due = $this->plan($this->booking(), '2027-07-12', hasSettlement: false);

        $this->assertContains(ReminderKind::SETTLEMENT_DUE->value, self::kinds($due));
    }

    public function testTheSettlementIsNotChasedTheDayAfterTheStay(): void
    {
        $due = $this->plan($this->booking(), '2027-07-05', hasSettlement: false);

        $this->assertNotContains(ReminderKind::SETTLEMENT_DUE->value, self::kinds($due));
    }

    // ── No personal data anywhere in a reminder (§6.29) ──────────────────

    public function testNoReminderEverNamesTheRenter(): void
    {
        // A reminder lands in a notification centre, a push payload and
        // possibly an email subject. None of those is a place for somebody's
        // name (SECURITY.md §5).
        $due = $this->plan(
            $this->booking(),
            '2027-07-12',
            $this->payment([
                'deposit_due_date' => '2027-06-01',
                'balance_due_date' => '2027-06-20',
                'security_deposit' => [
                    'amount_cents' => 30000,
                    'received_cents' => 30000,
                    'due_date' => '2027-06-20',
                    'returned_at' => null,
                ],
            ]),
            arrivalInventory: false,
            departureInventory: false,
            hasContract: false,
            hasSettlement: false
        );

        $this->assertNotSame([], $due);

        foreach ($due as $reminder) {
            $text = $reminder->title . ' ' . $reminder->body . ' ' . ($reminder->url ?? '');
            $this->assertStringNotContainsString('Jeanne', $text);
            $this->assertStringNotContainsString('jeanne@example.be', $text);
        }
    }

    public function testEveryReminderPointsSomewhereAManagerCanAct(): void
    {
        $due = $this->plan($this->booking(), '2027-06-25', hasContract: false);

        foreach ($due as $reminder) {
            $this->assertNotNull($reminder->url);
            $this->assertSame(1, $reminder->assetId, 'A reminder must name the asset it concerns.');
        }
    }

    // ── The compliance register (§6.33) ─────────────────────────────────

    private function item(?string $expiresOn, int $id = 3): ComplianceItem
    {
        return new ComplianceItem(
            id: $id,
            assetId: 1,
            label: 'Attestation incendie',
            fileId: null,
            expiresOn: $expiresOn,
            remark: null,
            remindedOn: null,
            createdAt: new \DateTimeImmutable('2027-01-01'),
            updatedAt: new \DateTimeImmutable('2027-01-01')
        );
    }

    public function testAnEntryWithNoExpiryNeverProducesAReminder(): void
    {
        $this->assertNull(
            $this->planner->forComplianceItem($this->item(null), $this->asset(), new \DateTimeImmutable('2027-07-01'))
        );
    }

    public function testAnExpiringEntryIsReportedAsAFactAboutADate(): void
    {
        $reminder = $this->planner->forComplianceItem(
            $this->item('2027-08-01'),
            $this->asset(),
            new \DateTimeImmutable('2027-07-01')
        );

        $this->assertNotNull($reminder);
        $this->assertStringContainsString('expire le 01/08/2027', $reminder->body);
    }

    public function testAnExpiredEntryIsReportedInThePastTense(): void
    {
        $reminder = $this->planner->forComplianceItem(
            $this->item('2027-06-01'),
            $this->asset(),
            new \DateTimeImmutable('2027-07-01')
        );

        $this->assertNotNull($reminder);
        $this->assertStringContainsString('a expiré le 01/06/2027', $reminder->body);
    }

    public function testAComplianceReminderNeverPassesJudgement(): void
    {
        // §6.33: the module knows no regulation. It says a date passed, not
        // that the hall may not be let — that would be a legal opinion.
        $reminder = $this->planner->forComplianceItem(
            $this->item('2027-06-01'),
            $this->asset(),
            new \DateTimeImmutable('2027-07-01')
        );

        $this->assertNotNull($reminder);
        foreach (['non conforme', 'interdit', 'illégal', 'obligatoire'] as $verdict) {
            $this->assertStringNotContainsStringIgnoringCase($verdict, $reminder->body);
        }
    }
}
