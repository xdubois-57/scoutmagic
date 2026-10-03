<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The checklist of milestones a manager actually reads (§6.15).
 *
 * The spec is explicit that this is a **derived representation** of a more
 * compact internal machine, not a second source of truth. Nothing here is
 * stored: every line is computed from the booking's own state, so the
 * checklist and the status can never drift apart, and no scheduled task has
 * to keep them in step.
 *
 * Pure: no database, no clock beyond the `$now` handed in.
 *
 * **Later iterations plug in rather than rewrite.** The contract, the
 * deposit, the security deposit, the meters, the inventories and the final
 * settlement each arrive in their own iteration and each fills in one line
 * here. Until then those lines are *not applicable* rather than unticked —
 * an unreachable unticked box reads as outstanding work and would make the
 * whole checklist noise.
 */
final class BookingMilestones
{
    /**
     * @param array<string, bool> $completedExtras Milestones later iterations own,
     *   keyed by the constants below. A key absent from this map is "not
     *   applicable yet" and is rendered greyed rather than unticked.
     */
    public const CONTRACT_SENT = 'contract_sent';
    public const CONTRACT_ACCEPTED = 'contract_accepted';
    public const DEPOSIT_RECEIVED = 'deposit_received';
    public const BALANCE_RECEIVED = 'balance_received';
    public const SECURITY_DEPOSIT_RECEIVED = 'security_deposit_received';
    public const ARRIVAL_INVENTORY = 'arrival_inventory';
    public const METER_READINGS = 'meter_readings';
    public const DEPARTURE_INVENTORY = 'departure_inventory';
    public const FINAL_SETTLEMENT = 'final_settlement';
    public const SECURITY_DEPOSIT_RETURNED = 'security_deposit_returned';

    /**
     * The lines a manager may tick by hand when the site cannot derive them
     * — the walk-throughs, on an asset whose inventory the site does not
     * keep (issue #462, D5). A line becomes one only per booking, through
     * `$offsite`: where the stay page records the inventory line by line,
     * the same line derives itself and carries no box.
     */
    public const MARKABLE = [self::ARRIVAL_INVENTORY, self::DEPARTURE_INVENTORY];

    /**
     * The steps whose disc is never a box to tick (#708, IT-14): « Demande
     * reçue » is always the site's, « Dates bloquées » is a state, and
     * « Réservation confirmée » and « Location clôturée » are statuses —
     * their disc runs the transition itself, never a tick.
     */
    public const NEVER_BY_HAND = ['request_received', 'hold', 'confirmed', 'closed'];

    /**
     * Who has to act for each step to be done (#708, IT-12) — what « À
     * traiter » reads off the step put forward. Explicit for every key, no
     * default: `shaped()` refuses a key missing here, and a test walks them.
     * « Demande reçue » is the renter's — they sent it — and is always done.
     */
    public const ACTORS = [
        'request_received' => StepActor::RENTER,
        'hold' => StepActor::UNIT,
        self::CONTRACT_SENT => StepActor::UNIT,
        self::CONTRACT_ACCEPTED => StepActor::RENTER,
        self::DEPOSIT_RECEIVED => StepActor::RENTER,
        'confirmed' => StepActor::UNIT,
        self::BALANCE_RECEIVED => StepActor::RENTER,
        self::SECURITY_DEPOSIT_RECEIVED => StepActor::RENTER,
        self::ARRIVAL_INVENTORY => StepActor::UNIT,
        self::METER_READINGS => StepActor::UNIT,
        self::DEPARTURE_INVENTORY => StepActor::UNIT,
        self::FINAL_SETTLEMENT => StepActor::UNIT,
        self::SECURITY_DEPOSIT_RETURNED => StepActor::UNIT,
        'closed' => StepActor::UNIT,
    ];

    /**
     * The applicable steps before « Réservation confirmée » still to do
     * (#708, IT-13) — what stands between a booking and its confirmation.
     * A step « sans objet » blocks nothing, and a state never does.
     *
     * @param list<BookingMilestone> $milestones
     * @return list<BookingMilestone>
     */
    public static function missingBeforeConfirmation(array $milestones): array
    {
        $missing = [];
        foreach ($milestones as $milestone) {
            if ($milestone->key === 'confirmed') {
                return $missing;
            }
            if ($milestone->isOutstanding()) {
                $missing[] = $milestone;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, bool> $extras
     * @param array<string, string> $details the grey suffix an extra line may
     *   carry — a send date, a settlement version (Booking\MilestoneEvidence)
     * @param list<string> $offsite the keys that are done outside the site on
     *   this booking, and therefore ticked by hand (Booking\MilestoneEvidence)
     * @param list<string> $manual the keys done because a manager ticked them
     *   by hand (#708, IT-14)
     * @return list<BookingMilestone>
     */
    public static function for(
        RentalBooking $booking,
        \DateTimeImmutable $now,
        array $extras = [],
        array $details = [],
        array $offsite = [],
        array $manual = []
    ): array {
        $milestones = [
            new BookingMilestone(
                'request_received',
                'Demande reçue',
                true,
                true,
                $booking->receivedAt->format('d/m/Y')
            ),
        ];

        // A request that died before it was ever confirmed will never reach
        // anything below the decision, so those lines are « sans objet »
        // rather than outstanding. Said once here because « L'action
        // suivante » is the first applicable line that is not done: without
        // it, an expired booking — one whose hold lapsed, by definition —
        // asked its manager to block the dates again, and a refused one
        // asked for a contract. Not `isFinal()`: a CLOSED rental is final
        // and may still owe a balance or hold a security deposit.
        $abandoned = $booking->status->isAbandoned();

        // The two holds are one line, because to the manager they are one
        // fact — "the dates are held until X" — and the wording is what
        // says which kind it is (specifications.md §22.5). A STATE, never a
        // task (#708, IT-01): ticked while a hold runs, a warning once an
        // automatic one ran out on a request still waiting, and never
        // « L'action suivante ».
        $lapsedSince = $abandoned ? null : $booking->holdLapsedSince($now);
        $milestones[] = new BookingMilestone(
            'hold',
            $booking->holdOrigin?->managerLabel() ?? 'Dates bloquées',
            $booking->holdIsActive($now),
            ($booking->holdIsActive($now) || $lapsedSince !== null) && !$abandoned,
            $booking->holdIsActive($now) && $booking->holdUntil !== null
                ? "jusqu'au " . $booking->holdUntil->format('d/m/Y à H\hi')
                : null,
            isState: true,
            warning: $lapsedSince !== null
                ? 'Les dates ne sont plus bloquées depuis le ' . $lapsedSince->format('d/m/Y à H\hi')
                    . ' : un autre visiteur peut les demander. Confirmez, ou posez une option pour les garder.'
                : null
        );

        // No « Décision prise sur la demande » line any more (#708, IT-13):
        // the unit's answer to a request is sending its contract, which is
        // the first line of L'accord — and « Réservation confirmée » closes
        // that stretch rather than duplicating it here.
        $milestones[] = self::extra($extras, $abandoned, self::CONTRACT_SENT, 'Contrat envoyé', $details);
        $milestones[] = self::extra(
            $extras,
            $abandoned,
            self::CONTRACT_ACCEPTED,
            'Conditions et contrat acceptés',
            $details
        );
        $milestones[] = self::extra($extras, $abandoned, self::DEPOSIT_RECEIVED, 'Acompte reçu', $details);

        $milestones[] = new BookingMilestone(
            'confirmed',
            'Réservation confirmée',
            $booking->status === BookingStatus::CONFIRMED
                || $booking->status === BookingStatus::CLOSED,
            // A refused, cancelled or expired booking is never going to be
            // confirmed; showing the box at all would suggest otherwise.
            !$abandoned
        );

        $milestones[] = self::extra($extras, $abandoned, self::BALANCE_RECEIVED, 'Solde reçu', $details);
        $milestones[] = self::extra($extras, $abandoned, self::SECURITY_DEPOSIT_RECEIVED, 'Caution reçue', $details);
        $milestones[] = self::extra($extras, $abandoned, self::ARRIVAL_INVENTORY, "État des lieux d'entrée", $details);
        $milestones[] = self::extra($extras, $abandoned, self::METER_READINGS, 'Relevés de compteurs', $details);
        $milestones[] = self::extra(
            $extras,
            $abandoned,
            self::DEPARTURE_INVENTORY,
            'État des lieux de sortie',
            $details
        );
        $milestones[] = self::extra($extras, $abandoned, self::FINAL_SETTLEMENT, 'Décompte final réglé', $details);
        $milestones[] = self::extra(
            $extras,
            $abandoned,
            self::SECURITY_DEPOSIT_RETURNED,
            'Caution restituée',
            $details
        );

        $milestones[] = new BookingMilestone(
            'closed',
            'Location clôturée',
            $booking->status === BookingStatus::CLOSED,
            !$abandoned
        );

        // What each line asks and how it gets ticked is decided once, over
        // the finished list, so that the facts above stay the only thing
        // each constructor call is about.
        $shaped = array_map(
            static fn(BookingMilestone $m): BookingMilestone => self::shaped(
                $m,
                $booking->status,
                $offsite,
                $booking->holdOrigin,
                in_array($m->key, $manual, true)
            ),
            $milestones
        );

        // Confirming is the end of the agreement, not a shortcut past it
        // (#708, IT-13): while a step before it is missing, the line offers
        // no button and says what is missing instead.
        $missing = self::missingBeforeConfirmation($shaped);
        if ($missing === []) {
            return $shaped;
        }

        return array_map(
            static fn(BookingMilestone $m): BookingMilestone => $m->key !== 'confirmed' ? $m : new BookingMilestone(
                $m->key,
                $m->label,
                $m->isDone,
                $m->isApplicable,
                $m->detail,
                $m->kind,
                'Se confirme quand l\'accord est complet. Il manque : '
                    . implode(', ', array_map(static fn(BookingMilestone $x): string => '« ' . $x->label . ' »', $missing))
                    . '.',
                null,
                $m->isState,
                $m->warning,
                $m->actor,
                $m->isManual
            ),
            $shaped
        );
    }

    /**
     * The line as a manager reads it on the journey: its nature, what it
     * asks, and what can be done about it (issue #462, D6–D7).
     *
     * The facts — done, applicable, detail — are untouched: this adds the
     * how, never a second answer to whether.
     *
     * @param list<string> $offsite
     */
    private static function shaped(
        BookingMilestone $m,
        BookingStatus $status,
        array $offsite,
        ?HoldOrigin $holdOrigin = null,
        bool $isManual = false
    ): BookingMilestone {
        $kind = MilestoneKind::DERIVED;
        $explanation = null;
        $action = null;

        switch ($m->key) {
            case 'hold':
                $kind = MilestoneKind::DERIVED;
                // What happens at the deadline depends on who set it.
                $explanation = $holdOrigin === HoldOrigin::MANAGER
                    ? "Une option bloque les dates jusqu'à son échéance ; passée sans confirmation, la "
                        . 'réservation expire et les dates se libèrent.'
                    : 'Les dates sont bloquées automatiquement le temps de répondre ; passé ce délai, elles '
                        . 'se libèrent et la demande reste en attente.';
                break;
            case self::CONTRACT_SENT:
                $kind = MilestoneKind::HERE;
                $explanation = "Le contrat est la réponse de l'unité à la demande : il reprend les conditions du "
                    . "bien et le prix convenu, se prépare et s'envoie depuis la page Documents. L'envoyer "
                    . 'passe la réservation à « Contrat envoyé ».';
                $action = MilestoneAction::openBox('Préparer le contrat', BookingBox::DOCUMENTS);
                break;
            case self::CONTRACT_ACCEPTED:
                $kind = MilestoneKind::RENTER;
                $explanation = "Le locataire accepte depuis sa page de suivi ; l'étape se coche quand la copie "
                    . 'signée est ajoutée aux documents.';
                break;
            case self::DEPOSIT_RECEIVED:
            case self::BALANCE_RECEIVED:
            case self::SECURITY_DEPOSIT_RECEIVED:
                $explanation = 'Se coche dès que le paiement est pointé dans les Finances.';
                break;
            case 'confirmed':
                // The last line of the agreement, the unit's (#708, IT-13).
                $kind = MilestoneKind::HERE;
                $explanation = "L'accord est complet : confirmez la réservation.";
                $action = self::forward($status, BookingStatus::CONFIRMED);
                break;
            case self::ARRIVAL_INVENTORY:
            case self::DEPARTURE_INVENTORY:
                if (in_array($m->key, $offsite, true)) {
                    $kind = MilestoneKind::OFFSITE;
                    $explanation = "Ce bien n'a pas d'inventaire sur le site : personne ne peut le deviner, "
                        . "cochez quand c'est fait.";
                    break;
                }
                $kind = MilestoneKind::HERE;
                $explanation = "L'inventaire se vérifie ligne par ligne sur la page Séjour.";
                $action = MilestoneAction::openBox("Faire l'état des lieux", BookingBox::STAY);
                break;
            case self::METER_READINGS:
                $kind = MilestoneKind::HERE;
                $explanation = "Chaque compteur se relève à l'arrivée et au départ, sur la page Séjour.";
                $action = MilestoneAction::openBox('Relever les compteurs', BookingBox::STAY);
                break;
            case self::FINAL_SETTLEMENT:
                $kind = MilestoneKind::HERE;
                $explanation = "Le décompte s'établit et se valide sur la page Séjour.";
                $action = MilestoneAction::openBox('Établir le décompte', BookingBox::STAY);
                break;
            case self::SECURITY_DEPOSIT_RETURNED:
                $kind = MilestoneKind::HERE;
                $explanation = "La restitution s'enregistre dans les paiements de la réservation.";
                $action = MilestoneAction::openBox('Enregistrer la restitution', BookingBox::PAYMENT);
                break;
            case 'closed':
                $kind = MilestoneKind::HERE;
                $explanation = 'Clôturez la location quand tout est réglé.';
                $action = self::forward($status, BookingStatus::CLOSED);
                break;
        }

        return new BookingMilestone(
            $m->key,
            $m->label,
            $m->isDone,
            $m->isApplicable,
            $m->detail,
            $kind,
            $explanation,
            $action,
            $m->isState,
            $m->warning,
            self::ACTORS[$m->key] ?? throw new \LogicException('No actor declared for step « ' . $m->key . ' ».'),
            $isManual
        );
    }

    /**
     * The transition that answers a line and moves the booking on (D7):
     * only ever `$forward`, and only while the table allows it — when it
     * does not, there is nothing to propose rather than a refusal promoted
     * to the front. Refusing and cancelling stay real answers, offered
     * among the booking's other decisions (`BookingJourney`).
     */
    private static function forward(BookingStatus $status, BookingStatus $forward): ?MilestoneAction
    {
        return BookingTransition::isAllowed($status, $forward)
            ? MilestoneAction::transition($forward, $status)
            : null;
    }

    /**
     * @param array<string, bool> $extras
     * @param array<string, string> $details
     */
    private static function extra(
        array $extras,
        bool $abandoned,
        string $key,
        string $label,
        array $details = []
    ): BookingMilestone {
        return new BookingMilestone(
            $key,
            $label,
            $extras[$key] ?? false,
            // Supplied by its module AND still reachable: see `$abandoned`
            // where it is computed.
            array_key_exists($key, $extras) && !$abandoned,
            $details[$key] ?? null
        );
    }
}
