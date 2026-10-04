<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Modules\Rental\Reminder\RenterDeadline;
/**
 * One booking on « À traiter », and what put it there (§22.5).
 *
 * **The single definition of "à traiter" for the whole module.** It used to
 * be `$booking->status->needsAttention()`, written out at four call sites:
 * the asset overview's list, the same page's « À traiter » figure, the
 * bookings list's own filter, and the per-asset badge on « Gérer mes locations ».
 * Four copies of one rule is four chances for the tile and the list under it
 * to disagree — and widening one of them without the others would have
 * guaranteed it.
 *
 * What it answers: **does the unit have something to do about this
 * booking?** The status asking for a decision, a request or a proposal
 * pending — and, since #708 (IT-12), **the step its page puts forward**:
 * the unit's own, or the renter's once they are late. A confirmed booking
 * used to vanish from the list although it almost always has steps left
 * up to its closing. A confirmed booking
 * carrying a change request the renter sent yesterday appeared on no list at
 * all, because its status is `confirmed` and nothing about a status knows
 * what is pending against it.
 *
 * Pure: no database, no clock. The step put forward and the renter's
 * deadline for it are handed in, computed by the caller from the same
 * journey the booking's page shows (`BookingJourney::next()`) and the
 * same rule as the reminders (`ReminderPlanner::renterDeadline()`), so the
 * list and the page can never say two different things. The pending change
 * requests are handed in too, already loaded — see `RentalChangeRequestRepository::findPendingForBookings()`,
 * which exists precisely so that a page showing thirty bookings does not ask
 * thirty questions.
 */
final class BookingAttention
{
    /**
     * @param list<AttentionReason> $reasons never empty — a booking with no
     *   reason is not on the list at all
     */
    private function __construct(
        public readonly RentalBooking $booking,
        public readonly array $reasons,
        /**
         * What the step reasons say about the step itself, by reason value.
         *
         * @var array<string, string>
         */
        private readonly array $stepLines = []
    ) {
    }

    /**
     * Why the booking is here, one line per reason — the step named when a
     * step put it there: « À faire : envoyer le contrat », « En retard :
     * acompte attendu depuis le 03/10/2027 ».
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(
            fn(AttentionReason $reason): string => $this->stepLines[$reason->value] ?? $reason->label(),
            $this->reasons
        );
    }

    /**
     * Whichever of the three apply, or null when nobody is waiting.
     *
     * @param ChangeRequest[] $pending this booking's pending change requests
     */
    public static function of(
        RentalBooking $booking,
        array $pending,
        ?BookingMilestone $next = null,
        ?\Modules\Rental\Reminder\RenterDeadline $renterDeadline = null,
        ?\DateTimeImmutable $today = null
    ): ?self {
        $reasons = [];
        $stepLines = [];

        if ($booking->status->needsAttention()) {
            $reasons[] = AttentionReason::STATUS;
        }

        // The step the booking's page puts forward (#708, IT-12). Never on
        // a final booking, and not twice for a request whose status already
        // says a decision is expected.
        if ($next !== null && !$booking->status->isFinal() && $reasons === []) {
            if ($next->actor === StepActor::UNIT) {
                $reasons[] = AttentionReason::UNIT_STEP;
                $stepLines[AttentionReason::UNIT_STEP->value] = 'À faire : ' . self::unitTask($next);
            } elseif ($next->actor === StepActor::RENTER
                && $renterDeadline !== null
                && $today !== null
                && $renterDeadline->isLate($today)
            ) {
                $reasons[] = AttentionReason::RENTER_LATE;
                $stepLines[AttentionReason::RENTER_LATE->value] = 'En retard : ' . self::renterWait($next)
                    . ' depuis le ' . $renterDeadline->expected->format('d/m/Y');
            }
        }

        // A final booking is nobody's work any more, whatever is still
        // recorded against it: `RentalBookingService` refuses every request
        // left pending the moment a booking closes, and a row that survived
        // that must not resurrect a closed file on somebody's list.
        if (!$booking->status->isFinal()) {
            foreach ($pending as $request) {
                if (!$request->isPending()) {
                    continue;
                }

                $reason = $request->origin === ChangeRequestOrigin::RENTER
                    ? AttentionReason::RENTER_REQUEST
                    : AttentionReason::UNIT_PROPOSAL;

                if (!in_array($reason, $reasons, true)) {
                    $reasons[] = $reason;
                }
            }
        }

        return $reasons === [] ? null : new self($booking, $reasons, $stepLines);
    }

    /** The unit's step as a thing to do. */
    private static function unitTask(BookingMilestone $step): string
    {
        return match ($step->key) {
            BookingMilestones::CONTRACT_GENERATED => 'générer le contrat',
            BookingMilestones::CONTRACT_SENT => 'envoyer le contrat',
            BookingMilestones::CONTRACT_COUNTERSIGNED => 'contresigner le contrat',
            'confirmed' => 'confirmer la réservation',
            BookingMilestones::ARRIVAL_INVENTORY => "l'état des lieux d'entrée",
            BookingMilestones::METER_READINGS => 'relever les compteurs',
            BookingMilestones::DEPARTURE_INVENTORY => "l'état des lieux de sortie",
            BookingMilestones::FINAL_SETTLEMENT => 'établir le décompte final',
            BookingMilestones::SECURITY_DEPOSIT_RETURNED => 'restituer la caution',
            'closed' => 'clôturer la location',
            default => mb_strtolower($step->label),
        };
    }

    /**
     * What the renter owes, as the thing expected — with its participle,
     * which agrees with it: « caution attendue », « acompte attendu ».
     */
    private static function renterWait(BookingMilestone $step): string
    {
        return match ($step->key) {
            BookingMilestones::SIGNED_COPY_RECEIVED => 'contrat signé attendu',
            BookingMilestones::DEPOSIT_RECEIVED => 'acompte attendu',
            BookingMilestones::BALANCE_RECEIVED => 'solde attendu',
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED => 'caution attendue',
            default => 'étape « ' . $step->label . ' » attendue',
        };
    }

    /**
     * @param RentalBooking[] $bookings
     * @param array<int, ChangeRequest[]> $pendingByBooking keyed by booking id
     * @param array<int, array{next: ?BookingMilestone, deadline: ?RenterDeadline}> $stepsByBooking
     *   keyed by booking id — the step each booking's page puts forward
     * @return list<self>
     */
    public static function from(
        array $bookings,
        array $pendingByBooking,
        array $stepsByBooking = [],
        ?\DateTimeImmutable $today = null
    ): array {
        $attention = [];
        foreach ($bookings as $booking) {
            $one = self::of(
                $booking,
                $pendingByBooking[$booking->id] ?? [],
                $stepsByBooking[$booking->id]['next'] ?? null,
                $stepsByBooking[$booking->id]['deadline'] ?? null,
                $today
            );
            if ($one !== null) {
                $attention[] = $one;
            }
        }

        return $attention;
    }

    /**
     * The same list, counted — what the « À traiter » figure shows.
     *
     * Named rather than left to `count()` at each call site, so the figure
     * and the list cannot be computed from two different sets.
     *
     * @param RentalBooking[] $bookings
     * @param array<int, ChangeRequest[]> $pendingByBooking
     * @param array<int, array{next: ?BookingMilestone, deadline: ?RenterDeadline}> $stepsByBooking
     */
    public static function countIn(
        array $bookings,
        array $pendingByBooking,
        array $stepsByBooking = [],
        ?\DateTimeImmutable $today = null
    ): int {
        return count(self::from($bookings, $pendingByBooking, $stepsByBooking, $today));
    }
}
