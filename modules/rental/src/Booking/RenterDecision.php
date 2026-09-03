<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * A decision a manager has taken that the renter has to be told about.
 *
 * Until this existed, none of them told anybody. A manager confirmed a
 * booking, refused one, proposed other dates or answered a change request,
 * and the only trace of it was on a page the renter had no reason to
 * reload — `propose()` even set a flash saying « Proposition envoyée »
 * while sending nothing at all. A renter here has no account and no
 * notification centre: email is not one channel among several, it is the
 * only one.
 *
 * Deliberately NOT `BookingStatus`. Several statuses are nobody's decision
 * and must stay silent — a hold lapsing into `EXPIRED` by a scheduled
 * sweep, a booking moved to `REVIEWING` because a manager opened it, a
 * `CLOSED` at the end of a stay that already happened. And two of the
 * decisions here are not status changes at all: accepting or refusing a
 * change request leaves the status exactly where it was. What is enumerated
 * is what somebody decided, which is what is worth an email.
 */
enum RenterDecision: string
{
    case CONFIRMED = 'confirmed';
    case REFUSED = 'refused';
    case PROPOSED = 'proposed';
    case INFO_REQUESTED = 'info_requested';
    case CHANGE_ACCEPTED = 'change_accepted';
    case CHANGE_REFUSED = 'change_refused';
    case CANCELLED = 'cancelled';

    /**
     * The status a manager's move lands on, mapped to the decision it is —
     * or null when nothing was decided and nobody should be written to.
     *
     * `EXPIRED` is the important null: it is what the hold sweep writes,
     * unattended, and an email saying "your dates have been released" is
     * exactly right in substance and exactly wrong as a thing that arrives
     * at 04:00 from nobody. `REVIEWING` is the other: it means a manager is
     * looking, which is not news.
     */
    public static function forStatus(BookingStatus $status): ?self
    {
        return match ($status) {
            BookingStatus::CONFIRMED => self::CONFIRMED,
            BookingStatus::REFUSED => self::REFUSED,
            BookingStatus::PROPOSED => self::PROPOSED,
            BookingStatus::INFO_REQUESTED => self::INFO_REQUESTED,
            BookingStatus::CANCELLED => self::CANCELLED,
            default => null,
        };
    }

    /** What goes after the reference in the subject line. */
    public function subject(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Votre réservation est confirmée',
            self::REFUSED => 'Votre demande de location',
            self::PROPOSED => 'Une proposition pour votre demande',
            self::INFO_REQUESTED => 'Nous avons besoin d\'une précision',
            self::CHANGE_ACCEPTED => 'Votre demande de modification est acceptée',
            self::CHANGE_REFUSED => 'Votre demande de modification',
            self::CANCELLED => 'Votre réservation a été annulée',
        };
    }

    /**
     * The sentence that says what happened, in the renter's terms.
     *
     * Never « statut : refused ». The renter did not choose a state
     * machine, they asked to borrow a hall.
     */
    public function announcement(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Votre réservation est confirmée.',
            self::REFUSED => 'Nous ne pouvons malheureusement pas donner suite à votre demande.',
            self::PROPOSED => 'Nous vous faisons une proposition pour votre demande.',
            self::INFO_REQUESTED => 'Nous avons besoin d\'une précision avant de vous répondre.',
            self::CHANGE_ACCEPTED => 'Votre demande de modification a été acceptée : votre réservation a été mise à '
                . 'jour.',
            self::CHANGE_REFUSED => 'Votre demande de modification n\'a pas pu être acceptée. Votre réservation est '
                . 'inchangée.',
            self::CANCELLED => 'Votre réservation a été annulée.',
        };
    }

    /**
     * What the renter is being asked to do next, or null when there is
     * nothing to do.
     *
     * A refusal asks for nothing, and inventing a call to action after one
     * would be worse than silence.
     */
    public function callToAction(): ?string
    {
        return match ($this) {
            self::PROPOSED => 'Vous pouvez accepter ou refuser cette proposition depuis votre page de suivi.',
            self::INFO_REQUESTED => 'Vous pouvez nous répondre depuis votre page de suivi.',
            self::CONFIRMED => 'Vous retrouvez le détail de votre réservation sur votre page de suivi.',
            self::CHANGE_ACCEPTED => 'Vous retrouvez les nouvelles dates sur votre page de suivi.',
            default => null,
        };
    }

    /**
     * Whether the email carries the tracking link.
     *
     * Everything the renter can still act on does. A refusal and a
     * cancellation do not: the booking is over, and re-issuing a capability
     * inside a message nobody can act on is how a link ends up forwarded
     * (the same reasoning as the practical-info email, §6.29).
     */
    public function carriesTheTrackingLink(): bool
    {
        return match ($this) {
            self::REFUSED, self::CANCELLED, self::CHANGE_REFUSED => false,
            default => true,
        };
    }

    /**
     * What the manager is asked before it happens — including the fact
     * that this one writes to the renter.
     *
     * Said out loud because it is new: pressing « Refuser » used to change
     * a badge, and now it sends somebody an email. A manager who does not
     * expect that is a manager who finds out from the reply.
     */
    public function question(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Confirmer cette réservation ? Le locataire recevra la confirmation par email.',
            self::REFUSED => 'Refuser cette demande ? Les dates seront libérées et le locataire en sera informé par '
                . 'email.',
            self::PROPOSED => 'Envoyer cette proposition au locataire par email ?',
            self::INFO_REQUESTED => 'Demander une précision au locataire ? Il la recevra par email.',
            self::CHANGE_ACCEPTED => 'Accepter cette demande ? Elle sera appliquée, et le locataire en sera informé '
                . 'par email.',
            self::CHANGE_REFUSED => 'Refuser cette demande ? La réservation reste inchangée, et le locataire en sera '
                . 'informé par email.',
            self::CANCELLED => 'Annuler cette réservation ? Les dates seront libérées et le locataire en sera informé '
                . 'par email.',
        };
    }

    /**
     * The label a manager sees above the free-text box, on the dialog that
     * asks whether they are sure.
     *
     * Phrased per decision because the word that belongs with a refusal is
     * not the word that belongs with a confirmation, and « Message » above
     * an empty box gets an empty box.
     */
    public function noteLabel(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Un mot au locataire (facultatif)',
            self::REFUSED => 'Dire pourquoi, si vous le souhaitez (facultatif)',
            self::PROPOSED => 'Expliquer votre proposition (facultatif)',
            self::INFO_REQUESTED => 'Ce que vous souhaitez savoir',
            self::CHANGE_ACCEPTED, self::CHANGE_REFUSED => 'Un mot au locataire (facultatif)',
            self::CANCELLED => "Dire pourquoi, si vous le souhaitez (facultatif)",
        };
    }
}
