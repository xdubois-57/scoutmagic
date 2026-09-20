<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingBox;
use Modules\Rental\Booking\BookingJourney;
use Modules\Rental\Booking\BookingMilestone;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingPhase;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use PHPUnit\Framework\TestCase;

/**
 * The journey a booking's page reads as (§6.15, IT-05).
 *
 * The thing worth testing here is not the grouping — that is a table — but
 * the two questions the page puts to it and the one invariant that keeps
 * the table honest:
 *
 * - **Every milestone lands in a named stretch.** `BookingPhase::of()`
 *   answers null for a key nobody has shelved, and a null is how a
 *   checklist line quietly ends up at the bottom of the page in the wrong
 *   phase. The test below walks the keys `BookingMilestones::for()` really
 *   produces, so a sixteenth milestone added without a phase fails here
 *   rather than on somebody's screen.
 * - **« L'action suivante » is the first applicable unticked line**, and
 *   the stretch holding it is the one that unfolds.
 * - **A decision offered at the top is not also offered below.** Two
 *   « Confirmée » buttons on one page is a page where pressing either is a
 *   guess.
 */
class BookingJourneyTest extends TestCase
{
    private function booking(
        BookingStatus $status = BookingStatus::RECEIVED,
        ?\DateTimeImmutable $holdUntil = null
    ): RentalBooking {
        return new RentalBooking(
            id: 1,
            assetId: 7,
            reference: 'LOC-2027-0001',
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
            holdUntil: $holdUntil,
            holdOrigin: $holdUntil === null ? null : HoldOrigin::MANAGER,
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
     * @param array<string, bool> $extras
     * @return list<BookingMilestone>
     */
    private function milestones(
        BookingStatus $status = BookingStatus::RECEIVED,
        array $extras = [],
        ?\DateTimeImmutable $holdUntil = null
    ): array {
        return BookingMilestones::for(
            $this->booking($status, $holdUntil),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            $extras
        );
    }

    // ── The invariant that keeps the table honest ───────────────────────

    /**
     * Every key the checklist can produce has a stretch. Run over a booking
     * whose every optional line is present, so the walk covers the whole
     * list and not the subset one state happens to reach.
     */
    public function testEveryMilestoneTheChecklistProducesHasAPhase(): void
    {
        $extras = [];
        foreach ([
            BookingMilestones::CONTRACT_SENT,
            BookingMilestones::CONTRACT_ACCEPTED,
            BookingMilestones::DEPOSIT_RECEIVED,
            BookingMilestones::BALANCE_RECEIVED,
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED,
            BookingMilestones::ARRIVAL_INVENTORY,
            BookingMilestones::METER_READINGS,
            BookingMilestones::DEPARTURE_INVENTORY,
            BookingMilestones::FINAL_SETTLEMENT,
            BookingMilestones::SECURITY_DEPOSIT_RETURNED,
        ] as $key) {
            $extras[$key] = false;
        }

        $milestones = $this->milestones(
            BookingStatus::CONFIRMED,
            $extras,
            new \DateTimeImmutable('2027-02-01 18:00:00')
        );

        foreach ($milestones as $milestone) {
            $this->assertNotNull(
                BookingPhase::of($milestone->key),
                "milestone '{$milestone->key}' belongs to no phase — add it to BookingPhase::of()"
            );
        }
    }

    public function testEveryMilestoneRendersInExactlyOnePhase(): void
    {
        $milestones = $this->milestones();
        $journey = BookingJourney::of($milestones);

        $rendered = [];
        foreach ($journey->phases() as $phase) {
            foreach ($phase->milestones as $milestone) {
                $rendered[] = $milestone->key;
            }
        }

        $this->assertSame(
            count($milestones),
            count($rendered),
            'a milestone is missing from the journey, or shown twice'
        );
        $this->assertSame(count($rendered), count(array_unique($rendered)));
    }

    public function testThePhasesComeInTheOrderThePageReadsThem(): void
    {
        $journey = BookingJourney::of($this->milestones());

        $this->assertSame(
            ['La demande', "L'accord", 'Avant le séjour', 'Le séjour', 'Après le séjour'],
            array_map(static fn($p): string => $p->label(), $journey->phases())
        );
    }

    // ── « L'action suivante » ───────────────────────────────────────────

    /**
     * The line the checklist never had. « Demande reçue » ticks when the
     * request ARRIVES, so before this milestone existed a request nobody
     * had looked at pointed the manager straight at the contract.
     */
    public function testAnUndecidedRequestAsksForTheDecisionFirst(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::RECEIVED));

        $this->assertNotNull($journey->next());
        $this->assertSame('decision', $journey->next()->key);
        $this->assertFalse($journey->isComplete());
    }

    /**
     * A proposal sent is not a decision taken: the renter may still refuse
     * it, and `BookingTransition` still offers confirming — which is the
     * definition this milestone borrows rather than keeping a second list.
     */
    public function testAProposalSentIsStillAnUndecidedRequest(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::PROPOSED));

        $this->assertSame('decision', $journey->next()?->key);
    }

    public function testOnceDecidedTheNextThingIsTheFirstUntickedLineAfterIt(): void
    {
        $journey = BookingJourney::of($this->milestones(
            BookingStatus::CONFIRMED,
            [BookingMilestones::CONTRACT_SENT => false]
        ));

        $this->assertSame(BookingMilestones::CONTRACT_SENT, $journey->next()?->key);
        $this->assertSame(BookingBox::DOCUMENTS, $journey->nextBox());
    }

    /**
     * A milestone belonging to something this installation cannot do is
     * skipped rather than proposed: an unreachable box is exactly what
     * `isApplicable` exists to say, and « L'action suivante » must not send
     * somebody to a caution no asset here charges.
     */
    public function testAnInapplicableLineIsNeverTheNextThingToDo(): void
    {
        // A key absent from $extras is emitted inapplicable and incomplete
        // (`BookingMilestones::extra()` keys applicability on
        // array_key_exists), so the three contract and deposit lines here
        // come BEFORE the balance and are exactly the shape that must be
        // stepped over.
        $milestones = $this->milestones(
            BookingStatus::CONFIRMED,
            [BookingMilestones::BALANCE_RECEIVED => false]
        );

        $skipped = array_values(array_filter(
            $milestones,
            static fn(BookingMilestone $m): bool => in_array($m->key, [
                BookingMilestones::CONTRACT_SENT,
                BookingMilestones::CONTRACT_ACCEPTED,
                BookingMilestones::DEPOSIT_RECEIVED,
            ], true)
        ));
        $this->assertCount(3, $skipped, 'The lines to be stepped over must be on the list at all.');
        foreach ($skipped as $milestone) {
            $this->assertFalse($milestone->isApplicable, $milestone->key);
            $this->assertFalse($milestone->isDone, $milestone->key);
        }

        // Incomplete and earlier, yet none of them is proposed: the balance
        // is, because it is the first incomplete line this installation can
        // actually do something about.
        $this->assertSame(BookingMilestones::BALANCE_RECEIVED, BookingJourney::of($milestones)->next()?->key);
    }

    /**
     * **A dead file has no next action, whatever its modules still supply.**
     *
     * « L'action suivante » is the first applicable line that is not done,
     * and nothing downstream of the decision will ever happen on a request
     * that was refused, cancelled or left to expire. Without that, the card
     * asked the manager to send a contract on a booking they had refused —
     * and the template's « Cette réservation est dans un état définitif »
     * branch, which only fires when there is no next action, was
     * unreachable in exactly those cases.
     *
     * An expired booking is the sharpest of the three: a lapsed hold is
     * *why* it expired, so the hold line is applicable-and-not-done and
     * wins `next()` before the decision is even reached — the page asked
     * for the dates to be blocked again.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('abandonedStatuses')]
    public function testAnAbandonedRequestHasNoNextAction(BookingStatus $status): void
    {
        $journey = BookingJourney::of($this->milestones(
            $status,
            [
                BookingMilestones::CONTRACT_SENT => false,
                BookingMilestones::DEPOSIT_RECEIVED => false,
                BookingMilestones::BALANCE_RECEIVED => false,
            ],
            new \DateTimeImmutable('2027-01-05 18:00:00')
        ));

        $this->assertNull($journey->next(), $status->value);
    }

    /**
     * @return array<string, array{BookingStatus}>
     */
    public static function abandonedStatuses(): array
    {
        return [
            'refusée' => [BookingStatus::REFUSED],
            'annulée' => [BookingStatus::CANCELLED],
            'expirée' => [BookingStatus::EXPIRED],
        ];
    }

    /**
     * **Clôturée n'est pas abandonnée**, and this is the distinction the
     * fix above turns on. A stay that happened may still owe a balance or
     * hold a security deposit — two of §6.29's reminders exist to chase
     * exactly that — so a closed booking keeps its next action while a
     * refused one has none.
     */
    public function testAClosedBookingStillChasesWhatIsOutstanding(): void
    {
        $journey = BookingJourney::of($this->milestones(
            BookingStatus::CLOSED,
            [BookingMilestones::BALANCE_RECEIVED => false]
        ));

        $this->assertSame(BookingMilestones::BALANCE_RECEIVED, $journey->next()?->key);
    }

    public function testAClosedBookingHasNothingLeftToDo(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::CLOSED));

        $this->assertNull($journey->next());
        $this->assertTrue($journey->isComplete());
    }

    // ── Which stretch unfolds ───────────────────────────────────────────

    public function testTheStretchHoldingTheNextThingIsTheCurrentOne(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::RECEIVED));

        $current = array_values(array_filter(
            $journey->phases(),
            static fn($p): bool => $p->isCurrent
        ));

        $this->assertCount(1, $current);
        $this->assertSame('La demande', $current[0]->label());
    }

    /**
     * With nothing left, the last stretch that had anything applicable in
     * it unfolds — that is where the file ended. Unfolding none would leave
     * the reader of a finished booking five closed lines and no way to tell
     * which was last.
     */
    public function testAFinishedBookingUnfoldsTheStretchItEndedIn(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::CLOSED));

        $current = array_values(array_filter(
            $journey->phases(),
            static fn($p): bool => $p->isCurrent
        ));

        $this->assertCount(1, $current);
        $this->assertSame('Après le séjour', $current[0]->label());
    }

    // ── What a folded stretch says ──────────────────────────────────────

    public function testAFoldedStretchCountsItsApplicableLinesOnly(): void
    {
        $journey = BookingJourney::of($this->milestones(
            BookingStatus::RECEIVED,
            [BookingMilestones::CONTRACT_SENT => true, BookingMilestones::DEPOSIT_RECEIVED => false]
        ));

        $agreement = $this->phase($journey, BookingPhase::AGREEMENT);

        // Contract sent (done), deposit (not), confirmed (applicable, not
        // done) — « Conditions et contrat acceptés » is absent from the
        // extras map and therefore not applicable at all.
        $this->assertSame('1 sur 3', $agreement->summary());
        $this->assertFalse($agreement->isDone());
    }

    public function testAStretchWithNothingApplicableSaysSoRatherThanZero(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::RECEIVED));

        $this->assertSame('Sans objet', $this->phase($journey, BookingPhase::STAY)->summary());
    }

    public function testAFinishedStretchSaysFait(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::CLOSED));

        $this->assertSame('Fait', $this->phase($journey, BookingPhase::REQUEST)->summary());
        $this->assertTrue($this->phase($journey, BookingPhase::REQUEST)->isDone());
    }

    // ── Where a stretch sends the reader ────────────────────────────────

    public function testAStretchPointsAtTheBoxItsNextLineIsSettledIn(): void
    {
        $journey = BookingJourney::of($this->milestones(
            BookingStatus::CONFIRMED,
            [BookingMilestones::CONTRACT_SENT => true, BookingMilestones::DEPOSIT_RECEIVED => false]
        ));

        // « Contrat envoyé » is done, so the stretch points at the deposit's
        // box rather than at the one whose work is behind it.
        $this->assertSame(BookingBox::PAYMENT, $this->phase($journey, BookingPhase::AGREEMENT)->box);
    }

    public function testAFinishedStretchStillPointsAtWhereItsEvidenceIsFiled(): void
    {
        $journey = BookingJourney::of($this->milestones(
            BookingStatus::CONFIRMED,
            [BookingMilestones::CONTRACT_SENT => true, BookingMilestones::CONTRACT_ACCEPTED => true]
        ));

        $this->assertSame(BookingBox::DOCUMENTS, $this->phase($journey, BookingPhase::AGREEMENT)->box);
    }

    public function testTheRequestStretchPointsNowhereBecauseItsButtonsAreInIt(): void
    {
        $journey = BookingJourney::of($this->milestones(BookingStatus::RECEIVED));

        $this->assertNull($this->phase($journey, BookingPhase::REQUEST)->box);
    }

    public function testEveryBoxAnchorIsDistinct(): void
    {
        $anchors = array_map(static fn(BookingBox $b): string => $b->anchor(), BookingBox::cases());

        $this->assertSame($anchors, array_values(array_unique($anchors)));
    }

    // ── The buttons, lifted exactly once ────────────────────────────────

    public function testAnUndecidedRequestLiftsEveryDecisionToTheTop(): void
    {
        $transitions = BookingTransition::allowedFrom(BookingStatus::RECEIVED);
        $journey = BookingJourney::of($this->milestones(BookingStatus::RECEIVED), $transitions);

        $this->assertSame($transitions, $journey->liftedTransitions());

        foreach ($journey->phases() as $phase) {
            $this->assertSame([], $phase->transitions, "{$phase->key()} repeats a lifted button");
        }
    }

    /**
     * A confirmed booking's last line is « Location clôturée », so closing
     * comes up at the top — and cancelling, offered beside it, does not:
     * abandoning a rental is not a way of finishing one, and it stays in
     * the stretch where the request is answered.
     */
    public function testClosingIsLiftedButCancellingIsNot(): void
    {
        $journey = BookingJourney::of(
            $this->milestones(BookingStatus::CONFIRMED),
            BookingTransition::allowedFrom(BookingStatus::CONFIRMED)
        );

        $this->assertSame('closed', $journey->next()?->key);
        $this->assertSame([BookingStatus::CLOSED], $journey->liftedTransitions());
        $this->assertSame(
            [BookingStatus::CANCELLED],
            $this->phase($journey, BookingPhase::REQUEST)->transitions
        );
        $this->assertSame([], $this->phase($journey, BookingPhase::AFTER_STAY)->transitions);
    }

    public function testAFinalBookingOffersNoButtonAnywhere(): void
    {
        $journey = BookingJourney::of(
            $this->milestones(BookingStatus::REFUSED),
            BookingTransition::allowedFrom(BookingStatus::REFUSED)
        );

        $this->assertSame([], $journey->liftedTransitions());
        foreach ($journey->phases() as $phase) {
            $this->assertSame([], $phase->transitions);
        }
    }

    private function phase(BookingJourney $journey, BookingPhase $wanted): \Modules\Rental\Booking\JourneyPhase
    {
        foreach ($journey->phases() as $phase) {
            if ($phase->phase === $wanted) {
                return $phase;
            }
        }

        $this->fail('no phase ' . $wanted->value);
    }
}
