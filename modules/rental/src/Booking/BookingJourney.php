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
 *
 * Since #708 (IT-19) the page shows the two halves as two cards,
 * « Prochaine action » and « Cycle de vie ». That separates the display,
 * never the calculation: both still read this one object.
 *
 * What only the controller knows comes in through `of()`'s optional
 * arguments — a modification in progress, which comes before everything
 * else (#927, chantier §2.4); a renter late on their step (IT-12); dates no
 * longer held (IT-01).
 */
final class BookingJourney
{
    /**
     * @param list<JourneyPhase> $phases
     */
    private function __construct(
        private readonly array $phases,
        private readonly ?BookingMilestone $next,
        private readonly BookingStatus $status,
        private readonly ?ModificationProgress $modification = null,
        private readonly ?\DateTimeImmutable $holdUntil = null,
        private readonly ?\DateTimeImmutable $lateSince = null,
        private readonly ?\DateTimeImmutable $holdLapsedSince = null,
        private readonly ?\DateTimeImmutable $firmlyHeldSince = null
    ) {
    }

    /**
     * @param list<BookingMilestone> $milestones from `BookingMilestones::for()`
     * @param ?ModificationProgress $modification the booking's modification
     *   in progress (#927, chantier §2.4)
     * @param ?\DateTimeImmutable $holdUntil until when the dates are held
     * @param ?\DateTimeImmutable $lateSince when the renter was expected by,
     *   for a step of theirs now late (IT-12)
     * @param ?\DateTimeImmutable $holdLapsedSince since when the dates are
     *   no longer held, on a request still waiting (IT-01)
     * @param ?\DateTimeImmutable $firmlyHeldSince since when a signed copy
     *   holds the dates firmly (#927, chantier §2.1)
     */
    public static function of(
        array $milestones,
        BookingStatus $status,
        ?ModificationProgress $modification = null,
        ?\DateTimeImmutable $holdUntil = null,
        ?\DateTimeImmutable $lateSince = null,
        ?\DateTimeImmutable $holdLapsedSince = null,
        ?\DateTimeImmutable $firmlyHeldSince = null
    ): self {
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

        $next = self::firstOutstanding($milestones);

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

        return new self(
            $phases,
            $next,
            $status,
            $status->isFinal() ? null : $modification,
            $holdUntil,
            $lateSince,
            $holdLapsedSince,
            $firmlyHeldSince
        );
    }

    /**
     * The first milestone still waiting, read straight off the list: what
     * `next()` returns, for a caller that needs it before it has the rest
     * of the journey to build — the dashboard's lateness (IT-12) is worked
     * out from it and then handed to `of()`.
     *
     * @param list<BookingMilestone> $milestones
     */
    public static function firstOutstanding(array $milestones): ?BookingMilestone
    {
        foreach ($milestones as $milestone) {
            // A state (« Dates bloquées ») is never the next thing to do.
            if ($milestone->isOutstanding()) {
                return $milestone;
            }
        }

        return null;
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

    /** The booking's modification in progress, or null (#927, chantier §2.4). */
    public function modification(): ?ModificationProgress
    {
        return $this->modification;
    }

    public function isComplete(): bool
    {
        return $this->next === null;
    }

    /**
     * The sentence at the top of « Prochaine action »: what holds the
     * booking up (#708, IT-19).
     *
     * In this order: a final status, which nothing changes; a
     * modification in progress, which comes before everything else (#927,
     * chantier §2.4); a question the renter owes an answer to; then the
     * next step, one sentence each — `BookingJourneyTest` walks every step
     * key and every status.
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

        if ($this->modification !== null) {
            return $this->modification->stage->headline();
        }

        if ($this->next === null) {
            return $this->status === BookingStatus::CLOSED
                ? 'Cette location est clôturée : il ne reste rien à faire.'
                : "Rien n'attend de vous sur cette réservation.";
        }

        if ($this->status === BookingStatus::INFO_REQUESTED) {
            return 'Une précision a été demandée au locataire : la suite attend sa réponse.';
        }
        return self::stepSentence($this->next, $this->holdUntil, $this->status);
    }

    /**
     * The sentence for a step put forward — one per key, never a default.
     *
     * @throws \LogicException for a step nobody wrote one for —
     *   `BookingJourneyTest` fails first
     */
    public static function stepSentence(
        BookingMilestone $next,
        ?\DateTimeImmutable $holdUntil = null,
        ?BookingStatus $status = null
    ): string {
        $detail = $next->detail !== null ? ' : ' . $next->detail : '';

        return match ($next->key) {
            'request_received', 'hold' => 'La demande attend votre réponse.',
            BookingMilestones::CONTRACT_GENERATED => 'Le contrat reste à générer.',
            BookingMilestones::CONTRACT_SENT => 'Le contrat reste à envoyer.',
            BookingMilestones::SIGNED_COPY_RECEIVED => 'Le contrat attend la signature du locataire'
                . ($holdUntil !== null
                    ? ' : les dates sont bloquées jusqu\'au ' . $holdUntil->format('d/m/Y')
                    : '')
                . '.',
            BookingMilestones::CONTRACT_COUNTERSIGNED => 'Une copie signée attend votre vérification.',
            BookingMilestones::DEPOSIT_RECEIVED => "En attente de l'acompte" . $detail . '.',
            // A request nobody answered yet, on an asset with no contract:
            // the confirmation is the answer.
            'confirmed' => $status === BookingStatus::RECEIVED
                ? 'La demande attend votre réponse : la réservation peut être confirmée.'
                : 'Tout est prêt : la réservation peut être confirmée.',
            BookingMilestones::BALANCE_RECEIVED => 'En attente du solde' . $detail . '.',
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED => 'En attente de la caution' . $detail . '.',
            BookingMilestones::ARRIVAL_INVENTORY => "L'état des lieux d'entrée reste à faire.",
            BookingMilestones::DEPARTURE_INVENTORY => "L'état des lieux de sortie reste à faire.",
            BookingMilestones::FINAL_SETTLEMENT => 'Le décompte final reste à établir, et la facture à générer.',
            BookingMilestones::SECURITY_DEPOSIT_RETURNED => 'La caution reste à restituer.',
            'closed' => 'Tout est réglé : la location peut être clôturée.',
            default => throw new \LogicException("No headline for the step '{$next->key}'."),
        };
    }

    /**
     * « En retard : acompte attendu depuis le 03/10/2027. » — a step of the
     * renter's past its date (#708, IT-12), or null.
     */
    public function lateLine(): ?string
    {
        if ($this->lateSince === null || $this->next === null || $this->modification !== null) {
            return null;
        }

        $what = match ($this->next->key) {
            BookingMilestones::SIGNED_COPY_RECEIVED => 'copie signée du contrat attendue',
            BookingMilestones::DEPOSIT_RECEIVED => 'acompte attendu',
            BookingMilestones::BALANCE_RECEIVED => 'solde attendu',
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED => 'caution attendue',
            default => null,
        };

        return $what === null ? null : 'En retard : ' . $what . ' depuis le ' . $this->lateSince->format('d/m/Y') . '.';
    }

    /**
     * While a modification runs, what the page around it still means
     * (#927, chantier §2.4): nothing on it changes before the
     * countersignature. Null otherwise.
     */
    public function modificationLine(): ?string
    {
        return $this->modification === null
            ? null
            : "Les dates, les participants, le prix et le contrat de cette page restent ceux qui "
                . "s'appliquent jusqu'à la contresignature.";
    }

    /**
     * While a modification runs, how the booking's own dates are held —
     * they follow their own deadline, whatever the project's new ones do
     * (chantier §2.3). Null otherwise, and when nothing holds them: then
     * `holdLine()` speaks.
     */
    public function datesLine(): ?string
    {
        if ($this->modification === null) {
            return null;
        }
        if ($this->firmlyHeldSince !== null) {
            return 'Dates bloquées fermement : copie signée reçue le '
                . $this->firmlyHeldSince->format('d/m/Y') . '.';
        }
        if ($this->status === BookingStatus::CONFIRMED) {
            return 'Dates bloquées fermement : la réservation est confirmée.';
        }
        if ($this->holdUntil !== null) {
            return "Dates bloquées jusqu'au " . $this->holdUntil->format('d/m/Y')
                . ' ; sans copie signée, elles se libèrent à cette date.';
        }

        return null;
    }

    /**
     * A second line when the dates are no longer protected while the
     * booking is not confirmed (#708, IT-01), or null.
     */
    public function holdLine(): ?string
    {
        return $this->holdLapsedSince === null
            ? null
            : 'Les dates ne sont plus bloquées depuis le ' . $this->holdLapsedSince->format('d/m/Y')
                . ' : une autre demande peut les prendre.';
    }

    /**
     * The one action put forward (D7), and only when the next move is the
     * unit's (#708, IT-19): the modification in progress, else the step's
     * own action. While the renter is the one expected — a
     * copy to sign, a payment to make — nothing is put forward: the
     * heading says what is awaited, and ticking it by hand stays in the
     * step (IT-14).
     *
     * Never a refusal nor a cancellation: `BookingMilestones` only ever
     * gives a step a forward transition, and `BookingJourneyTest` holds that
     * for every status.
     */
    public function primaryAction(): ?MilestoneAction
    {
        // A final booking offers nothing — the headline already says it
        // can no longer change.
        if ($this->status->isFinal()) {
            return null;
        }

        // A modification in progress is the one thing to move on (#927,
        // chantier §2.4): its copy to countersign, on Documents, or the
        // project itself on its own page.
        if ($this->modification !== null) {
            return $this->modification->stage === ModificationStage::COPY_RECEIVED
                ? MilestoneAction::openPage('Vérifier et contresigner', BookingPage::DOCUMENTS)
                : MilestoneAction::openPage(
                    'Ouvrir la modification',
                    BookingPage::CHANGES,
                    '/' . $this->modification->modification->id
                );
        }

        if ($this->next === null || $this->next->actor === StepActor::RENTER) {
            return null;
        }

        return $this->next->action;
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
