<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The fifteen milestones, staged (§6.15) — and the one answer the page gives
 * to « où en est cette réservation, et que dois-je faire ? » (issue #462).
 *
 * Pure, and derived from a derivation: it takes what
 * `BookingMilestones::for()` already computed and adds no fact of its own.
 * That is the whole point — the checklist and the journey cannot drift
 * apart, because there is only one of them.
 *
 * It used to answer that question twice, in two cards: « L'action
 * suivante » above and « Où en est cette location » below, each deriving
 * its own reading of the same checklist. Two boxes for one question are the
 * defect, not a layout to rearrange (D3), so there is one answer now:
 *
 * - **the heading** — a sentence naming what holds the booking up (« En
 *   attente du solde : 350,00 € attendus — échéance dépassée de 4 jours »,
 *   not « Avant le séjour »), the one action that moves it on, and every
 *   other decision still open, folded behind it (D7);
 * - **the stretches** — the five phases, the current one open, each
 *   milestone a step with its nature (D6). A stretch the state machine
 *   does not allow yet — anything after the request, before a booking is
 *   confirmed — stays visible but inert (D5).
 */
final class BookingJourney
{
    /**
     * @param list<JourneyPhase> $phases
     */
    private function __construct(
        private readonly array $phases,
        private readonly ?BookingMilestone $next,
        private readonly BookingStatus $status
    ) {
    }

    /**
     * @param list<BookingMilestone> $milestones from `BookingMilestones::for()`
     */
    public static function of(array $milestones, BookingStatus $status): self
    {
        /** @var array<string, list<BookingMilestone>> $grouped */
        $grouped = [];
        foreach (BookingPhase::cases() as $phase) {
            $grouped[$phase->value] = [];
        }

        foreach ($milestones as $milestone) {
            // An unshelved key lands in the last stretch rather than
            // nowhere: it renders at the end of the journey, which is odd,
            // where dropping it would be a line of the checklist silently
            // missing. `BookingJourneyTest` fails before that ships.
            $phase = BookingPhase::of($milestone->key) ?? BookingPhase::AFTER_STAY;
            $grouped[$phase->value][] = $milestone;
        }

        $next = null;
        foreach ($milestones as $milestone) {
            // A state (« Dates bloquées ») is never the next thing to do.
            if ($milestone->isOutstanding()) {
                $next = $milestone;
                break;
            }
        }

        // The stretch holding that milestone is the one that unfolds. With
        // nothing left to do — a closed file, a refused one — it is the
        // last stretch that has anything applicable at all, because that is
        // where the file actually ended; unfolding none would leave the
        // reader of a finished booking with five closed lines and no way to
        // tell which of them was the last to happen.
        $current = $next !== null
            ? (BookingPhase::of($next->key) ?? BookingPhase::AFTER_STAY)
            : self::lastStretchWithWork($grouped);

        // What is out of reach is what the MACHINE forbids (D5), not what
        // the checklist has not got to: before a booking is confirmed,
        // nothing after the request can happen — no contract before a
        // decision, no stay before a booking. Once it is confirmed, the
        // stretches still read in order, but none is locked: a stay that
        // happened while the signed contract was still in the post must
        // not leave its walk-through impossible to record.
        $committed = $status === BookingStatus::CONFIRMED || $status === BookingStatus::CLOSED;

        $phases = [];
        $reached = true;
        foreach (BookingPhase::cases() as $phase) {
            $isCurrent = $phase === $current;
            $phases[] = new JourneyPhase($phase, $grouped[$phase->value], $isCurrent, !$reached && !$committed);
            if ($isCurrent) {
                $reached = false;
            }
        }

        return new self($phases, $next, $status);
    }

    /**
     * @return list<JourneyPhase>
     */
    public function phases(): array
    {
        return $this->phases;
    }

    /**
     * The first milestone still waiting, or null when there is none.
     */
    public function next(): ?BookingMilestone
    {
        return $this->next;
    }

    public function isComplete(): bool
    {
        return $this->next === null;
    }

    /**
     * The sentence at the top of the journey: what holds the booking up.
     *
     * One per situation, and each names the thing itself rather than the
     * stretch it sits in — « Avant le séjour » says where the booking is,
     * never what it is waiting for.
     */
    public function headline(): string
    {
        if ($this->status->isAbandoned()) {
            return match ($this->status) {
                BookingStatus::REFUSED => 'Cette demande a été refusée : elle ne peut plus changer.',
                BookingStatus::EXPIRED => "Cette demande a expiré : l'option est échue sans confirmation.",
                default => 'Cette réservation a été annulée : elle ne peut plus changer.',
            };
        }

        if ($this->next === null) {
            return $this->status === BookingStatus::CLOSED
                ? 'Cette location est clôturée : il ne reste rien à faire.'
                : "Rien n'attend de vous sur cette réservation.";
        }

        // While the renter has a question or a proposal to answer, that is
        // what holds the booking up, whatever the next line says.
        if ($this->status === BookingStatus::INFO_REQUESTED) {
            return 'Une précision a été demandée au locataire : la suite attend sa réponse.';
        }
        if ($this->status === BookingStatus::PROPOSED) {
            return 'Une proposition attend la réponse du locataire.';
        }

        $waiting = match ($this->next->key) {
            BookingMilestones::SIGNED_COPY_RECEIVED => 'En attente du contrat signé par le locataire',
            BookingMilestones::DEPOSIT_RECEIVED => "En attente de l'acompte",
            BookingMilestones::BALANCE_RECEIVED => 'En attente du solde',
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED => 'En attente de la caution',
            default => null,
        };
        if ($waiting !== null) {
            return $waiting . ($this->next->detail !== null ? ' : ' . $this->next->detail : '') . '.';
        }

        return match ($this->next->key) {
            BookingMilestones::CONTRACT_GENERATED => $this->status === BookingStatus::RECEIVED
                ? 'Cette demande attend votre réponse : générez le contrat.'
                : 'Le contrat reste à générer.',
            BookingMilestones::CONTRACT_SENT => $this->status === BookingStatus::RECEIVED
                ? 'Cette demande attend votre réponse : relisez le contrat, puis envoyez-le.'
                : 'Le contrat reste à envoyer.',
            BookingMilestones::CONTRACT_COUNTERSIGNED => 'Le contrat signé par le locataire reste à contresigner.',
            'confirmed' => match ($this->status) {
                BookingStatus::RECEIVED => 'Cette demande attend votre réponse : confirmez la réservation.',
                BookingStatus::CONTRACT_SENT => "L'accord est complet : la réservation reste à confirmer.",
                default => 'La réservation reste à confirmer.',
            },
            BookingMilestones::ARRIVAL_INVENTORY => "L'état des lieux d'entrée reste à faire.",
            BookingMilestones::METER_READINGS => 'Les relevés de compteurs restent à faire.',
            BookingMilestones::DEPARTURE_INVENTORY => "L'état des lieux de sortie reste à faire.",
            BookingMilestones::FINAL_SETTLEMENT => 'Le décompte final reste à régler.',
            BookingMilestones::SECURITY_DEPOSIT_RETURNED => 'La caution reste à restituer.',
            'closed' => 'Tout est réglé : la location peut être clôturée.',
            default => $this->next->label . ' : reste à faire.',
        };
    }

    /**
     * The one action put forward (D7): the step's own when it has one, else
     * the way to where its answer will show — the payments for a payment,
     * the documents for a signed copy. Null when there is nothing to press
     * at all: a line ticked by hand has its box in the step itself.
     *
     * Never a refusal nor a cancellation: `BookingMilestones` only ever
     * gives a step a forward transition, and `BookingJourneyTest` holds that
     * for every status.
     */
    public function primaryAction(): ?MilestoneAction
    {
        if ($this->next === null) {
            return null;
        }

        if ($this->next->action !== null) {
            return $this->next->action;
        }

        return match ($this->next->key) {
            BookingMilestones::DEPOSIT_RECEIVED,
            BookingMilestones::BALANCE_RECEIVED,
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED
                => MilestoneAction::openBox('Voir les paiements', BookingBox::PAYMENT),
            BookingMilestones::SIGNED_COPY_RECEIVED
                => MilestoneAction::openBox('Voir les documents', BookingBox::DOCUMENTS),
            default => null,
        };
    }

    /**
     * Every other decision still open on this booking — each status the
     * table allows, but the one put forward. Folded behind a secondary
     * button: aligned beside the proposed one, four decisions would ask the
     * manager to choose their policy afresh on every file.
     *
     * @return list<MilestoneAction>
     */
    public function otherDecisions(): array
    {
        $forward = $this->primaryAction()?->transition;
        $others = [];

        foreach (BookingTransition::allowedFrom($this->status) as $to) {
            // Confirming is never one decision among others (#708, IT-13):
            // it is the last line of the agreement, offered there once the
            // agreement is complete.
            if ($to !== $forward && $to !== BookingStatus::CONFIRMED) {
                $others[] = MilestoneAction::transition($to, $this->status);
            }
        }

        return $others;
    }

    /**
     * @param array<string, list<BookingMilestone>> $grouped
     */
    private static function lastStretchWithWork(array $grouped): ?BookingPhase
    {
        $last = null;

        foreach (BookingPhase::cases() as $phase) {
            foreach ($grouped[$phase->value] as $milestone) {
                if ($milestone->isApplicable) {
                    $last = $phase;
                    break;
                }
            }
        }

        return $last;
    }
}
