<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle table (§6.15).
 *
 * Pinned exhaustively rather than by example: a transition table is exactly
 * the kind of thing that grows a hole when somebody adds a status, and the
 * hole is invisible until a real booking falls through it.
 */
class BookingTransitionTest extends TestCase
{
    public function testEveryStatusHasAnEntryInTheTable(): void
    {
        // allowedFrom() indexes the table directly, with no fallback — a
        // status added to the enum and forgotten here throws rather than
        // silently allowing nothing.
        foreach (BookingStatus::cases() as $status) {
            $this->assertIsArray(BookingTransition::allowedFrom($status));
        }
    }

    public function testAFinalStateIsTrulyFinal(): void
    {
        // Reopening one would silently re-take a period that may since have
        // been let to somebody else.
        foreach (BookingStatus::cases() as $status) {
            if (!$status->isFinal()) {
                continue;
            }

            $this->assertSame([], BookingTransition::allowedFrom($status), $status->value . ' must be final.');

            foreach (BookingStatus::cases() as $target) {
                $this->assertFalse(BookingTransition::isAllowed($status, $target));
            }
        }
    }

    public function testAReceivedRequestCanBeRefusedOrConfirmed(): void
    {
        $this->assertTrue(BookingTransition::isAllowed(BookingStatus::RECEIVED, BookingStatus::REFUSED));
        $this->assertTrue(BookingTransition::isAllowed(BookingStatus::RECEIVED, BookingStatus::CONFIRMED));
    }

    public function testConfirmationIsOnlyReachableFromAStateSomebodyLookedAt(): void
    {
        $reachable = [];
        foreach (BookingStatus::cases() as $from) {
            if (BookingTransition::isAllowed($from, BookingStatus::CONFIRMED)) {
                $reachable[] = $from->value;
            }
        }

        sort($reachable);
        $this->assertSame(['contract_sent', 'info_requested', 'proposed', 'received'], $reachable);
    }

    /**
     * « Remettre en attente » leads back to « Demande reçue » from both
     * states that wait on the renter (#708, IT-11).
     */
    public function testARequestWaitingOnTheRenterCanBePutBackOnHold(): void
    {
        foreach ([BookingStatus::INFO_REQUESTED, BookingStatus::PROPOSED] as $from) {
            $this->assertTrue(BookingTransition::isAllowed($from, BookingStatus::RECEIVED), $from->value);
            $this->assertSame('Remettre en attente', BookingTransition::actionLabel($from, BookingStatus::RECEIVED));
        }
    }

    /** The internal « En cours d'examen » label is gone, and so is its action. */
    public function testThereIsNoReviewingStatusAnyMore(): void
    {
        $this->assertNull(BookingStatus::tryFrom('reviewing'));
        foreach (BookingStatus::cases() as $from) {
            foreach (BookingTransition::allowedFrom($from) as $to) {
                $this->assertNotSame('Mettre en examen', BookingTransition::actionLabel($from, $to));
            }
        }
    }

    /**
     * Out of « Contrat envoyé »: confirm, cancel, or back on hold — never
     * refused, the unit having said yes by sending its contract; and it is
     * reached by sending, never offered as a decision (#708, IT-13).
     */
    public function testAContractSentCanBeConfirmedCancelledOrPutBackOnHold(): void
    {
        $this->assertSame(
            [BookingStatus::CONFIRMED, BookingStatus::CANCELLED, BookingStatus::RECEIVED],
            BookingTransition::allowedFrom(BookingStatus::CONTRACT_SENT)
        );
        foreach (BookingStatus::cases() as $from) {
            $this->assertFalse(BookingTransition::isAllowed($from, BookingStatus::CONTRACT_SENT), $from->value);
        }
    }

    public function testAConfirmedBookingCanOnlyBeClosedOrCancelled(): void
    {
        $this->assertSame(
            [BookingStatus::CLOSED, BookingStatus::CANCELLED],
            BookingTransition::allowedFrom(BookingStatus::CONFIRMED)
        );
    }

    public function testCancellationIsAvailableFromEveryLiveState(): void
    {
        // §6.17: a rental that falls through falls through, whatever stage
        // it had reached.
        foreach (BookingStatus::cases() as $status) {
            if ($status->isFinal()) {
                continue;
            }

            $this->assertTrue(
                BookingTransition::isAllowed($status, BookingStatus::CANCELLED),
                $status->value . ' must be cancellable.'
            );
        }
    }

    public function testOnlyAProposalCanExpire(): void
    {
        // Expiry means a promised option lapsed. An automatic hold lapsing
        // does NOT expire anything — nobody promised the dates — which is
        // why `received` has no route here.
        $expirable = array_values(array_filter(
            BookingStatus::cases(),
            static fn(BookingStatus $s) => BookingTransition::isAllowed($s, BookingStatus::EXPIRED)
        ));

        $this->assertSame([BookingStatus::PROPOSED], $expirable);
    }

    public function testNoStatusMayTransitionToItself(): void
    {
        foreach (BookingStatus::cases() as $status) {
            $this->assertFalse(BookingTransition::isAllowed($status, $status), $status->value);
        }
    }

    public function testTheRefusalReasonSaysWhatIsWrongRatherThanNamingTheRule(): void
    {
        $this->assertStringContainsString(
            'déjà',
            BookingTransition::refusalReason(BookingStatus::CONFIRMED, BookingStatus::CONFIRMED)
        );
        $this->assertStringContainsString(
            'nouvelle demande',
            BookingTransition::refusalReason(BookingStatus::CANCELLED, BookingStatus::CONFIRMED)
        );
        $this->assertStringContainsString(
            'Confirmée',
            BookingTransition::refusalReason(BookingStatus::CONFIRMED, BookingStatus::REFUSED)
        );
    }
}
