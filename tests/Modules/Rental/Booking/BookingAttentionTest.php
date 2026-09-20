<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\AttentionReason;
use Modules\Rental\Booking\BookingAttention;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\ChangeRequest;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\ChangeRequestStatus;
use Modules\Rental\Booking\RentalBooking;
use PHPUnit\Framework\TestCase;

/**
 * « À traiter », for the whole module (§22.5).
 *
 * The defect this closes: the list was a filter on the STATUS alone, so a
 * confirmed booking carrying a change request the renter sent yesterday
 * appeared on no list at all — and that is exactly a thing to deal with.
 */
class BookingAttentionTest extends TestCase
{
    private function booking(BookingStatus $status, int $id = 1): RentalBooking
    {
        return new RentalBooking(
            id: $id,
            assetId: 7,
            reference: 'LOC-2027-000' . $id,
            arrivalDate: '2027-07-17',
            departureDate: '2027-07-20',
            units: 1,
            estimatedPersons: 30,
            renterCategoryId: null,
            renterName: 'Marie Dupont',
            renterEmail: 'marie@example.org',
            renterPhone: '0470 12 34 56',
            renterOrganisation: null,
            purpose: 'Week-end de section',
            renterComment: null,
            status: $status,
            receivedAt: new \DateTimeImmutable('2027-01-04 10:00:00'),
            finalAt: null,
            holdUntil: null,
            holdOrigin: null,
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

    private function request(
        ChangeRequestOrigin $origin,
        ChangeRequestStatus $status = ChangeRequestStatus::PENDING,
        int $bookingId = 1
    ): ChangeRequest {
        return new ChangeRequest(
            id: 100,
            bookingId: $bookingId,
            origin: $origin,
            kind: ChangeRequestKind::DATES,
            status: $status,
            proposedArrivalDate: '2027-07-18',
            proposedDepartureDate: '2027-07-21',
            proposedUnits: null,
            proposedPersons: null,
            proposedPrice: null,
            proposedTotalCents: null,
            message: null,
            createdAt: new \DateTimeImmutable('2027-01-05 09:00:00'),
            decidedAt: null,
            decidedByMemberId: null
        );
    }

    // ── What the status alone used to decide ────────────────────────────

    public function testAStatusThatAsksForADecisionPutsABookingOnTheList(): void
    {
        $attention = BookingAttention::of($this->booking(BookingStatus::RECEIVED), []);

        $this->assertNotNull($attention);
        $this->assertSame([AttentionReason::STATUS], $attention->reasons);
    }

    public function testAConfirmedBookingWithNothingPendingIsNotOnTheList(): void
    {
        $this->assertNull(BookingAttention::of($this->booking(BookingStatus::CONFIRMED), []));
    }

    // ── The two cases the status could not see ──────────────────────────

    /**
     * The whole point of the iteration: this booking appeared nowhere.
     */
    public function testAConfirmedBookingCarryingARenterRequestIsOnTheList(): void
    {
        $attention = BookingAttention::of(
            $this->booking(BookingStatus::CONFIRMED),
            [$this->request(ChangeRequestOrigin::RENTER)]
        );

        $this->assertNotNull($attention);
        $this->assertSame([AttentionReason::RENTER_REQUEST], $attention->reasons);
    }

    /**
     * A proposal waits on somebody too, and the unit is the one who has to
     * know it is still waiting.
     */
    public function testAConfirmedBookingCarryingAnUnansweredProposalIsOnTheList(): void
    {
        $attention = BookingAttention::of(
            $this->booking(BookingStatus::CONFIRMED),
            [$this->request(ChangeRequestOrigin::MANAGER)]
        );

        $this->assertNotNull($attention);
        $this->assertSame([AttentionReason::UNIT_PROPOSAL], $attention->reasons);
    }

    public function testEveryReasonThatAppliesIsGivenAndNeverTwice(): void
    {
        $attention = BookingAttention::of($this->booking(BookingStatus::RECEIVED), [
            $this->request(ChangeRequestOrigin::RENTER),
            $this->request(ChangeRequestOrigin::RENTER),
            $this->request(ChangeRequestOrigin::MANAGER),
        ]);

        $this->assertNotNull($attention);
        $this->assertSame(
            [AttentionReason::STATUS, AttentionReason::RENTER_REQUEST, AttentionReason::UNIT_PROPOSAL],
            $attention->reasons
        );
    }

    // ── What must NOT come back ─────────────────────────────────────────

    public function testADecidedRequestPutsNobodyOnTheList(): void
    {
        $this->assertNull(BookingAttention::of(
            $this->booking(BookingStatus::CONFIRMED),
            [$this->request(ChangeRequestOrigin::RENTER, ChangeRequestStatus::ACCEPTED)]
        ));
    }

    /**
     * A final booking is nobody's work any more. `RentalBookingService`
     * refuses every request still pending the moment a booking closes, so a
     * row that survived that must not resurrect a closed file on somebody's
     * list.
     */
    public function testAFinalBookingStaysOffTheListWhateverIsRecordedAgainstIt(): void
    {
        foreach ([BookingStatus::REFUSED, BookingStatus::CANCELLED, BookingStatus::CLOSED] as $status) {
            $this->assertNull(
                BookingAttention::of($this->booking($status), [$this->request(ChangeRequestOrigin::RENTER)]),
                $status->value . ' is over.'
            );
        }
    }

    // ── The list, and the figure above it ───────────────────────────────

    public function testTheListKeepsTheOrderItWasGivenAndDropsWhatIsNotWaiting(): void
    {
        $bookings = [
            $this->booking(BookingStatus::RECEIVED, 1),
            $this->booking(BookingStatus::CONFIRMED, 2),
            $this->booking(BookingStatus::CONFIRMED, 3),
        ];

        $attention = BookingAttention::from($bookings, [3 => [$this->request(ChangeRequestOrigin::RENTER, bookingId: 3)]]);

        $this->assertSame([1, 3], array_map(static fn($one) => $one->booking->id, $attention));
    }

    /**
     * The figure and the list are computed from one source on purpose: a
     * tile saying « 2 » over a list of five is the failure this exists to
     * avoid.
     */
    public function testTheCountIsTheLengthOfTheSameList(): void
    {
        $bookings = [
            $this->booking(BookingStatus::RECEIVED, 1),
            $this->booking(BookingStatus::CONFIRMED, 2),
        ];
        $pending = [2 => [$this->request(ChangeRequestOrigin::MANAGER, bookingId: 2)]];

        $this->assertSame(
            count(BookingAttention::from($bookings, $pending)),
            BookingAttention::countIn($bookings, $pending)
        );
        $this->assertSame(2, BookingAttention::countIn($bookings, $pending));
    }

    public function testEveryReasonSaysSomethingAManagerCanRead(): void
    {
        foreach (AttentionReason::cases() as $reason) {
            $this->assertNotSame('', trim($reason->label()));
        }
    }
}
