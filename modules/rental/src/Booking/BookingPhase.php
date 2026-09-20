<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The five stretches a rental goes through, as a manager tells them (§6.15).
 *
 * `BookingMilestones` already derives the checklist in the right order and
 * nothing here changes it. What this adds is the only thing missing to make
 * fourteen ticked boxes readable: the shape of the story they tell. Fourteen
 * checkboxes in a column are a list; five named stretches, one of them open,
 * are a position — the same reason the scout-year transition shows four
 * steps rather than its whole procedure.
 *
 * **This is presentation, not a second lifecycle.** `BookingStatus` remains
 * the machine and `BookingMilestones` the derivation; a phase is a grouping
 * of milestone keys and carries no state of its own. Adding a milestone
 * therefore means adding one line to `of()`, and `BookingJourneyTest` is
 * what says so: it walks every key `BookingMilestones::for()` can produce
 * and fails on one that falls through to the default arm.
 */
enum BookingPhase: string
{
    case REQUEST = 'request';
    case AGREEMENT = 'agreement';
    case BEFORE_STAY = 'before_stay';
    case STAY = 'stay';
    case AFTER_STAY = 'after_stay';

    public function label(): string
    {
        return match ($this) {
            self::REQUEST => 'La demande',
            self::AGREEMENT => "L'accord",
            self::BEFORE_STAY => 'Avant le séjour',
            self::STAY => 'Le séjour',
            self::AFTER_STAY => 'Après le séjour',
        };
    }

    /**
     * One line saying what this stretch is for, under its title when it is
     * the one open. Not a repeat of the milestones below it — those say
     * what is left to do; this says what the stretch is about.
     */
    public function description(): string
    {
        return match ($this) {
            self::REQUEST => 'Ce que le locataire a demandé, et la décision qui lui revient.',
            self::AGREEMENT => "Le contrat, son acceptation et l'acompte : ce qui engage les deux parties.",
            self::BEFORE_STAY => "Le solde et la caution, avant que les clés ne changent de main.",
            self::STAY => 'Les états des lieux et les relevés, pendant et autour du séjour.',
            self::AFTER_STAY => 'Le décompte, la restitution de la caution et la clôture du dossier.',
        };
    }

    /**
     * Which phase a milestone belongs to, or **null** when nothing has
     * shelved that key yet.
     *
     * Deliberately keyed on the milestone's own key rather than on its
     * position in the list: the list grows by iteration (§6.15 says each
     * later iteration fills one line in), and a grouping by index would
     * quietly re-shelve every milestone after the one that was inserted.
     *
     * Null rather than a guess, because the two callers want opposite
     * things from an unshelved key: `BookingJourney` still has to render
     * the line somewhere (a milestone that vanishes reads as work nobody
     * did), while `BookingJourneyTest` has to fail on it.
     */
    public static function of(string $milestoneKey): ?self
    {
        return match ($milestoneKey) {
            'request_received', 'hold', 'decision' => self::REQUEST,
            BookingMilestones::CONTRACT_SENT,
            BookingMilestones::CONTRACT_ACCEPTED,
            BookingMilestones::DEPOSIT_RECEIVED,
            'confirmed' => self::AGREEMENT,
            BookingMilestones::BALANCE_RECEIVED,
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED => self::BEFORE_STAY,
            BookingMilestones::ARRIVAL_INVENTORY,
            BookingMilestones::METER_READINGS,
            BookingMilestones::DEPARTURE_INVENTORY => self::STAY,
            BookingMilestones::FINAL_SETTLEMENT,
            BookingMilestones::SECURITY_DEPOSIT_RETURNED,
            'closed' => self::AFTER_STAY,
            default => null,
        };
    }

    /**
     * Which status transitions are decisions of this phase.
     *
     * The « État » card is gone (its status is in the details at the top of
     * the page and its buttons are decisions, not a state): each button now
     * sits in the stretch it belongs to.
     *
     * Only closing belongs anywhere but « La demande ». Confirming looks
     * like it should live in « L'accord », but `BookingTransition` only
     * ever offers it from a status where nobody has decided yet — so on the
     * screen it is one of the answers to the request, standing beside
     * refusing and proposing, and shelving it a stretch away would separate
     * a decision from its alternatives.
     */
    public static function ofTransition(BookingStatus $to): self
    {
        return $to === BookingStatus::CLOSED ? self::AFTER_STAY : self::REQUEST;
    }
}
