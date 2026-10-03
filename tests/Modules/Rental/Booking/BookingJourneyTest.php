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
use Modules\Rental\Booking\MilestoneKind;
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

    /**
     * @param array<string, bool> $extras
     */
    private function journey(
        BookingStatus $status = BookingStatus::RECEIVED,
        array $extras = [],
        ?\DateTimeImmutable $holdUntil = null
    ): BookingJourney {
        return BookingJourney::of($this->milestones($status, $extras, $holdUntil), $status);
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
        $journey = BookingJourney::of($milestones, BookingStatus::RECEIVED);

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
        $journey = $this->journey();

        $this->assertSame(
            ['La demande', "L'accord", 'Avant le séjour', 'Le séjour', 'Après le séjour'],
            array_map(static fn($p): string => $p->label(), $journey->phases())
        );
    }

    // ── « L'action suivante » ───────────────────────────────────────────

    /**
     * The unit's answer to a request is its contract (#708, IT-13): the
     * next step of a received request is « Contrat envoyé », and there is
     * no « Décision prise sur la demande » line any more.
     */
    public function testAReceivedRequestAsksForTheContractFirst(): void
    {
        $journey = $this->journey(BookingStatus::RECEIVED, [BookingMilestones::CONTRACT_SENT => false]);

        $this->assertSame(BookingMilestones::CONTRACT_SENT, $journey->next()?->key);
        $this->assertSame('Cette demande attend votre réponse : envoyez le contrat.', $journey->headline());
        $this->assertNotContains('decision', array_map(
            static fn(BookingMilestone $m): string => $m->key,
            $this->milestones(BookingStatus::RECEIVED)
        ));
    }

    /** An asset with no contract moves on to the next applicable line. */
    public function testWithNoContractTheNextStepIsTheFollowingLine(): void
    {
        $this->assertSame(
            BookingMilestones::DEPOSIT_RECEIVED,
            $this->journey(BookingStatus::RECEIVED, [BookingMilestones::DEPOSIT_RECEIVED => false])->next()?->key
        );
        $this->assertSame('confirmed', $this->journey(BookingStatus::RECEIVED)->next()?->key);
    }

    /**
     * « Réservation confirmée » closes L'accord, the unit's, and offers its
     * button only once every applicable line before it is done (#708, IT-13).
     */
    public function testConfirmingIsTheLastLineOfTheAgreementAndWaitsForIt(): void
    {
        $agreement = array_values(array_filter(
            $this->milestones(BookingStatus::CONTRACT_SENT, [
                BookingMilestones::CONTRACT_SENT => true,
                BookingMilestones::CONTRACT_ACCEPTED => false,
                BookingMilestones::DEPOSIT_RECEIVED => false,
            ]),
            static fn(BookingMilestone $m): bool => BookingPhase::of($m->key) === BookingPhase::AGREEMENT
        ));
        $confirmed = $agreement[count($agreement) - 1];

        $this->assertSame('confirmed', $confirmed->key);
        $this->assertNull($confirmed->action);
        $this->assertStringContainsString('« Conditions et contrat acceptés », « Acompte reçu »', (string) $confirmed->explanation);

        $ready = array_values(array_filter(
            $this->milestones(BookingStatus::CONTRACT_SENT, [
                BookingMilestones::CONTRACT_SENT => true,
                BookingMilestones::CONTRACT_ACCEPTED => true,
                BookingMilestones::DEPOSIT_RECEIVED => true,
            ]),
            static fn(BookingMilestone $m): bool => $m->key === 'confirmed'
        ))[0];
        $this->assertSame(BookingStatus::CONFIRMED, $ready->action?->transition);
    }

    /** Confirming is never among the other decisions any more. */
    public function testConfirmingIsNotAmongTheOtherDecisions(): void
    {
        foreach ([BookingStatus::RECEIVED, BookingStatus::INFO_REQUESTED, BookingStatus::PROPOSED, BookingStatus::CONTRACT_SENT] as $status) {
            foreach ($this->journey($status, [BookingMilestones::CONTRACT_SENT => false])->otherDecisions() as $decision) {
                $this->assertNotSame(BookingStatus::CONFIRMED, $decision->transition, $status->value);
            }
        }
    }

    public function testAProposalSentWaitsOnTheRenter(): void
    {
        $journey = $this->journey(BookingStatus::PROPOSED, [BookingMilestones::CONTRACT_SENT => false]);

        $this->assertSame(BookingMilestones::CONTRACT_SENT, $journey->next()?->key);
        $this->assertSame('Une proposition attend la réponse du locataire.', $journey->headline());
    }

    public function testOnceDecidedTheNextThingIsTheFirstUntickedLineAfterIt(): void
    {
        $journey = $this->journey(
            BookingStatus::CONFIRMED,
            [BookingMilestones::CONTRACT_SENT => false]
        );

        $this->assertSame(BookingMilestones::CONTRACT_SENT, $journey->next()?->key);
        // Its action is the way to the box it is settled in.
        $this->assertSame(BookingBox::DOCUMENTS, $journey->primaryAction()?->box);
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
        $this->assertSame(BookingMilestones::BALANCE_RECEIVED, BookingJourney::of($milestones, BookingStatus::CONFIRMED)->next()?->key);
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
        $journey = $this->journey(
            $status,
            [
                BookingMilestones::CONTRACT_SENT => false,
                BookingMilestones::DEPOSIT_RECEIVED => false,
                BookingMilestones::BALANCE_RECEIVED => false,
            ],
            new \DateTimeImmutable('2027-01-05 18:00:00')
        );

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
        $journey = $this->journey(
            BookingStatus::CLOSED,
            [BookingMilestones::BALANCE_RECEIVED => false]
        );

        $this->assertSame(BookingMilestones::BALANCE_RECEIVED, $journey->next()?->key);
    }

    public function testAClosedBookingHasNothingLeftToDo(): void
    {
        $journey = $this->journey(BookingStatus::CLOSED);

        $this->assertNull($journey->next());
        $this->assertTrue($journey->isComplete());
    }

    // ── Which stretch unfolds ───────────────────────────────────────────

    public function testTheStretchHoldingTheNextThingIsTheCurrentOne(): void
    {
        $journey = $this->journey(BookingStatus::RECEIVED, [BookingMilestones::CONTRACT_SENT => false]);

        $current = array_values(array_filter(
            $journey->phases(),
            static fn($p): bool => $p->isCurrent
        ));

        $this->assertCount(1, $current);
        // The contract is the answer, and it opens the agreement.
        $this->assertSame("L'accord", $current[0]->label());
    }

    /**
     * With nothing left, the last stretch that had anything applicable in
     * it unfolds — that is where the file ended. Unfolding none would leave
     * the reader of a finished booking five closed lines and no way to tell
     * which was last.
     */
    public function testAFinishedBookingUnfoldsTheStretchItEndedIn(): void
    {
        $journey = $this->journey(BookingStatus::CLOSED);

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
        $journey = $this->journey(
            BookingStatus::RECEIVED,
            [BookingMilestones::CONTRACT_SENT => true, BookingMilestones::DEPOSIT_RECEIVED => false]
        );

        $agreement = $this->phase($journey, BookingPhase::AGREEMENT);

        // Contract sent (done), deposit (not), confirmed (applicable, not
        // done) — « Conditions et contrat acceptés » is absent from the
        // extras map and therefore not applicable at all.
        $this->assertSame('1 sur 3', $agreement->summary());
        $this->assertFalse($agreement->isDone());
    }

    public function testAStretchWithNothingApplicableSaysSoRatherThanZero(): void
    {
        $journey = $this->journey(BookingStatus::RECEIVED);

        $this->assertSame('Sans objet', $this->phase($journey, BookingPhase::STAY)->summary());
    }

    public function testAFinishedStretchSaysFait(): void
    {
        $journey = $this->journey(BookingStatus::CLOSED);

        $this->assertSame('Fait', $this->phase($journey, BookingPhase::REQUEST)->summary());
        $this->assertTrue($this->phase($journey, BookingPhase::REQUEST)->isDone());
    }

    public function testEveryBoxAnchorIsDistinct(): void
    {
        $anchors = array_map(static fn(BookingBox $b): string => $b->anchor(), BookingBox::cases());

        $this->assertSame($anchors, array_values(array_unique($anchors)));
    }

    // ── Stretches the booking has not reached (issue #462, D5) ──────────

    /**
     * Before a booking is confirmed, every stretch after the current one is
     * future — visible, inert: `BookingStatus` is a real machine, and a
     * contract cannot be sent before anybody decided.
     */
    public function testTheStretchesAfterTheCurrentOneAreInert(): void
    {
        $journey = $this->journey(BookingStatus::RECEIVED, [BookingMilestones::CONTRACT_SENT => false]);

        $this->assertSame(
            ['request' => false, 'agreement' => false, 'before_stay' => true, 'stay' => true, 'after_stay' => true],
            array_combine(
                array_map(static fn($p): string => $p->key(), $journey->phases()),
                array_map(static fn($p): bool => $p->isFuture, $journey->phases())
            )
        );

    }

    /**
     * **Once confirmed, nothing is locked.** The stretches still read in
     * order, but a stay that happened while the signed contract was still
     * in the post must not leave its walk-through impossible to record.
     */
    public function testAConfirmedBookingLocksNoStretch(): void
    {
        foreach ([BookingStatus::CONFIRMED, BookingStatus::CLOSED] as $status) {
            $journey = $this->journey($status, [BookingMilestones::CONTRACT_ACCEPTED => false]);

            $this->assertSame(BookingMilestones::CONTRACT_ACCEPTED, $journey->next()?->key, $status->value);
            foreach ($journey->phases() as $phase) {
                $this->assertFalse($phase->isFuture, "{$status->value}: {$phase->key()} is locked");
            }
        }
    }

    // ── The heading (issue #462, D3, D7) ────────────────────────────────

    /**
     * One sentence per status, naming what holds the booking up — never
     * the stretch it sits in. A heading that read the same for two
     * statuses would be one that says nothing about either.
     *
     * @return array<string, array{BookingStatus, string}>
     */
    public static function headlines(): array
    {
        return [
            'reçue' => [BookingStatus::RECEIVED, 'Cette demande attend votre réponse : confirmez la réservation.'],
            'précision demandée' => [BookingStatus::INFO_REQUESTED, 'Une précision a été demandée au locataire : la réponse attend la sienne.'],
            'proposition' => [BookingStatus::PROPOSED, 'Une proposition attend la réponse du locataire.'],
            'contrat envoyé' => [BookingStatus::CONTRACT_SENT, "L'accord est complet : la réservation reste à confirmer."],
            'confirmée' => [BookingStatus::CONFIRMED, 'Tout est réglé : la location peut être clôturée.'],
            'refusée' => [BookingStatus::REFUSED, 'Cette demande a été refusée : elle ne peut plus changer.'],
            'annulée' => [BookingStatus::CANCELLED, 'Cette réservation a été annulée : elle ne peut plus changer.'],
            'expirée' => [BookingStatus::EXPIRED, "Cette demande a expiré : l'option est échue sans confirmation."],
            'clôturée' => [BookingStatus::CLOSED, 'Cette location est clôturée : il ne reste rien à faire.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('headlines')]
    public function testTheHeadingNamesWhatHoldsTheBookingUp(BookingStatus $status, string $expected): void
    {
        $this->assertSame($expected, $this->journey($status)->headline());
    }

    public function testEveryStatusHasAHeadlineOfItsOwn(): void
    {
        $statuses = array_map(static fn(array $case): BookingStatus => $case[0], self::headlines());
        $this->assertEqualsCanonicalizing(BookingStatus::cases(), array_values($statuses), 'a status has no heading test');

        $sentences = array_map(static fn(array $case): string => $case[1], self::headlines());
        $this->assertSame(array_values($sentences), array_values(array_unique($sentences)));
    }

    /**
     * A payment holding the booking up is named with what is owed and when:
     * « En attente du solde », not « Avant le séjour ».
     */
    public function testAWaitingLineCarriesItsDerivedSentence(): void
    {
        $milestones = BookingMilestones::for(
            $this->booking(BookingStatus::CONFIRMED),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            [BookingMilestones::BALANCE_RECEIVED => false],
            [BookingMilestones::BALANCE_RECEIVED => '350,00 € attendus — échéance dépassée de 4 jours']
        );

        $this->assertSame(
            'En attente du solde : 350,00 € attendus — échéance dépassée de 4 jours.',
            BookingJourney::of($milestones, BookingStatus::CONFIRMED)->headline()
        );
    }

    /**
     * **The action put forward is never a retreat** (D7), whatever the
     * status: refusing or cancelling is a real answer, offered among the
     * others, never the one that moves the file on.
     *
     * @return array<string, array{BookingStatus}>
     */
    public static function everyStatus(): array
    {
        $cases = [];
        foreach (BookingStatus::cases() as $status) {
            $cases[$status->value] = [$status];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyStatus')]
    public function testTheProposedActionIsNeverARefusalNorACancellation(BookingStatus $status): void
    {
        foreach ([[], [BookingMilestones::BALANCE_RECEIVED => false], [BookingMilestones::CONTRACT_SENT => false]] as $extras) {
            $journey = $this->journey($status, $extras);

            $this->assertFalse($journey->primaryAction()?->isRetreat() ?? false, $status->value);
            foreach ($journey->phases() as $phase) {
                foreach ($phase->milestones as $milestone) {
                    $this->assertFalse($milestone->action?->isRetreat() ?? false, $milestone->key);
                }
            }
        }
    }

    /**
     * Every decision the table still allows is offered exactly once: the
     * proposed one, or among the others — never both, never neither.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyStatus')]
    public function testEveryAllowedDecisionIsOfferedExactlyOnce(BookingStatus $status): void
    {
        $journey = $this->journey($status);

        $offered = array_map(static fn($a) => $a->transition, $journey->otherDecisions());
        if ($journey->primaryAction()?->transition !== null) {
            $offered[] = $journey->primaryAction()->transition;
        }

        $this->assertEqualsCanonicalizing(BookingTransition::allowedFrom($status), $offered);
    }

    public function testAnUndecidedRequestPutsTheConfirmationForward(): void
    {
        $journey = $this->journey(BookingStatus::RECEIVED);

        $this->assertSame(BookingStatus::CONFIRMED, $journey->primaryAction()?->transition);
        $this->assertSame('Confirmer la réservation', $journey->primaryAction()?->label);
        $this->assertContains(BookingStatus::REFUSED, array_map(static fn($a) => $a->transition, $journey->otherDecisions()));
    }

    public function testAConfirmedBookingWithNothingLeftPutsTheClosureForward(): void
    {
        $journey = $this->journey(BookingStatus::CONFIRMED);

        $this->assertSame('closed', $journey->next()?->key);
        $this->assertSame(BookingStatus::CLOSED, $journey->primaryAction()?->transition);
        $this->assertSame(
            [BookingStatus::CANCELLED],
            array_map(static fn($a) => $a->transition, $journey->otherDecisions())
        );
    }

    public function testAPaymentHoldingTheBookingUpLeadsToThePayments(): void
    {
        $journey = $this->journey(BookingStatus::CONFIRMED, [BookingMilestones::BALANCE_RECEIVED => false]);

        $this->assertSame(BookingBox::PAYMENT, $journey->primaryAction()?->box);
        $this->assertNull($journey->primaryAction()?->transition);
    }

    public function testAFinalBookingOffersNothingToPress(): void
    {
        foreach ([BookingStatus::REFUSED, BookingStatus::CANCELLED, BookingStatus::EXPIRED] as $status) {
            $journey = $this->journey($status);

            $this->assertNull($journey->primaryAction(), $status->value);
            $this->assertSame([], $journey->otherDecisions(), $status->value);
        }
    }

    // ── The nature of each step (issue #462, D6) ────────────────────────

    public function testEachStepSaysHowItGetsTicked(): void
    {
        $extras = array_fill_keys([
            BookingMilestones::CONTRACT_SENT, BookingMilestones::CONTRACT_ACCEPTED,
            BookingMilestones::DEPOSIT_RECEIVED, BookingMilestones::BALANCE_RECEIVED,
            BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::METER_READINGS,
            BookingMilestones::DEPARTURE_INVENTORY, BookingMilestones::FINAL_SETTLEMENT,
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED, BookingMilestones::SECURITY_DEPOSIT_RETURNED,
        ], false);
        $kinds = [];
        foreach ($this->milestones(BookingStatus::CONFIRMED, $extras) as $milestone) {
            $kinds[$milestone->key] = $milestone->kind;
        }

        $this->assertSame(MilestoneKind::DERIVED, $kinds['request_received']);
        $this->assertSame(MilestoneKind::HERE, $kinds[BookingMilestones::CONTRACT_SENT]);
        $this->assertSame(MilestoneKind::RENTER, $kinds[BookingMilestones::CONTRACT_ACCEPTED]);
        $this->assertSame(MilestoneKind::DERIVED, $kinds[BookingMilestones::DEPOSIT_RECEIVED]);
        $this->assertSame(MilestoneKind::DERIVED, $kinds[BookingMilestones::BALANCE_RECEIVED]);
        // With the stay page recording the inventory, it is done there.
        $this->assertSame(MilestoneKind::HERE, $kinds[BookingMilestones::ARRIVAL_INVENTORY]);
        $this->assertSame(MilestoneKind::HERE, $kinds['closed']);
    }

    /**
     * A walk-through the site keeps no inventory for is the one line a
     * manager ticks by hand — and only that line: no derived line is ever
     * markable, whatever else is offsite.
     */
    public function testOnlyALineTheSiteCannotDeriveIsTickedByHand(): void
    {
        $milestones = BookingMilestones::for(
            $this->booking(BookingStatus::CONFIRMED),
            new \DateTimeImmutable('2027-01-10 12:00:00'),
            [
                BookingMilestones::ARRIVAL_INVENTORY => false,
                BookingMilestones::DEPARTURE_INVENTORY => false,
                BookingMilestones::DEPOSIT_RECEIVED => false,
            ],
            [],
            [BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::DEPARTURE_INVENTORY, BookingMilestones::DEPOSIT_RECEIVED]
        );

        foreach ($milestones as $milestone) {
            $this->assertSame(
                in_array($milestone->key, [BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::DEPARTURE_INVENTORY], true),
                $milestone->kind->isMarkable(),
                $milestone->key
            );
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

    // ── « Dates bloquées » is a state, never a task (#708, IT-01) ──────

    private function holdLine(RentalBooking $booking, string $now): BookingMilestone
    {
        foreach (BookingMilestones::for($booking, new \DateTimeImmutable($now)) as $milestone) {
            if ($milestone->key === 'hold') {
                return $milestone;
            }
        }

        self::fail('No hold line.');
    }

    private function automaticallyHeld(?\DateTimeImmutable $until, ?\DateTimeImmutable $lapsedAt = null): RentalBooking
    {
        $base = $this->booking();

        return new RentalBooking(
            ...array_merge(get_object_vars($base), [
                'holdUntil' => $until,
                'holdOrigin' => $until === null ? null : HoldOrigin::AUTOMATIC,
                'holdLapsedAt' => $lapsedAt,
            ])
        );
    }

    public function testARunningHoldIsTickedWithItsDeadline(): void
    {
        $line = $this->holdLine($this->automaticallyHeld(new \DateTimeImmutable('2027-01-29 14:00:00')), '2027-01-10 12:00:00');

        $this->assertTrue($line->isState);
        $this->assertTrue($line->isDone);
        $this->assertSame("jusqu'au 29/01/2027 à 14h00", $line->detail);
        $this->assertNull($line->warning);
    }

    public function testALapsedAutomaticHoldWarnsAndIsNeverTheNextAction(): void
    {
        foreach ([
            'before the task ran' => $this->automaticallyHeld(new \DateTimeImmutable('2027-01-09 14:00:00')),
            'after it ran' => $this->automaticallyHeld(null, new \DateTimeImmutable('2027-01-09 14:00:00')),
        ] as $case => $booking) {
            $now = new \DateTimeImmutable('2027-01-10 12:00:00');
            $milestones = BookingMilestones::for($booking, $now);
            $line = $this->holdLine($booking, '2027-01-10 12:00:00');

            $this->assertTrue($line->isApplicable, $case);
            $this->assertFalse($line->isDone, $case);
            $this->assertStringContainsString('ne sont plus bloquées depuis le 09/01/2027', (string) $line->warning, $case);
            $this->assertFalse($line->isOutstanding(), $case);
            $this->assertNotSame('hold', BookingJourney::of($milestones, BookingStatus::RECEIVED)->next()?->key, $case);
        }
    }

    public function testTheHoldExplanationDependsOnItsOrigin(): void
    {
        $automatic = $this->holdLine($this->automaticallyHeld(new \DateTimeImmutable('2027-01-29 14:00:00')), '2027-01-10 12:00:00');
        $option = $this->holdLine($this->booking(BookingStatus::RECEIVED, new \DateTimeImmutable('2027-01-29 14:00:00')), '2027-01-10 12:00:00');

        $this->assertStringContainsString('la demande reste en attente', (string) $automatic->explanation);
        $this->assertStringContainsString('la réservation expire', (string) $option->explanation);
    }

    public function testAConfirmedBookingHasNoHoldWarning(): void
    {
        $base = $this->automaticallyHeld(null, new \DateTimeImmutable('2027-01-09 14:00:00'));
        $confirmed = new RentalBooking(...array_merge(get_object_vars($base), ['status' => BookingStatus::CONFIRMED]));

        $line = $this->holdLine($confirmed, '2027-01-10 12:00:00');

        $this->assertNull($line->warning);
        $this->assertFalse($line->isApplicable);
    }
}
