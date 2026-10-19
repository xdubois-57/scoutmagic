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
 * Until this existed, none of them told anybody: a manager confirmed a
 * booking or refused one, and the only trace of it was on a page the
 * renter had no reason to reload. A renter here has no account and no
 * notification centre: email is not one channel among several, it is the
 * only one.
 *
 * Deliberately NOT `BookingStatus`. Several statuses are nobody's decision
 * and must stay silent — a hold lapsing into `EXPIRED` by a scheduled
 * sweep, a `CLOSED` at the end of a stay that already happened. What is
 * enumerated is what somebody decided, which is what is worth an email.
 *
 * The renter answers nothing from their page any more (#927, chantier
 * §2.2): no proposal of the unit to accept, no change request of theirs to
 * decide. A change of the booking reaches them as a replacement contract to
 * sign, by its own e-mail.
 */
enum RenterDecision: string
{
    case CONFIRMED = 'confirmed';
    case REFUSED = 'refused';
    case INFO_REQUESTED = 'info_requested';
    case CANCELLED = 'cancelled';

    /**
     * The status a manager's move lands on, mapped to the decision it is —
     * or null when nothing was decided and nobody should be written to.
     *
     * `EXPIRED` is the important null: it is what the hold sweep writes,
     * unattended, and an email saying "your dates have been released" is
     * exactly right in substance and exactly wrong as a thing that arrives
     * at 04:00 from nobody.
     */
    public static function forStatus(BookingStatus $status): ?self
    {
        return match ($status) {
            BookingStatus::CONFIRMED => self::CONFIRMED,
            BookingStatus::REFUSED => self::REFUSED,
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
            self::INFO_REQUESTED => 'Nous avons besoin d\'une précision',
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
            self::INFO_REQUESTED => 'Nous avons besoin d\'une précision avant de vous répondre.',
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
            self::INFO_REQUESTED => 'Vous pouvez nous répondre depuis votre page de suivi.',
            // The one place the billing coordinates are ever asked for
            // (§22.6): no dedicated email, and no reminder about them —
            // both are written out of the chantier. This sentence, on
            // the message that already carries the tracking link, is
            // the whole mechanism.
            self::CONFIRMED => 'Vous retrouvez le détail de votre réservation sur votre page de suivi — '
                . 'et si votre facture doit être établie au nom d\'une association ou d\'une société, '
                . 'vous pouvez y renseigner vos coordonnées de facturation.',
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
            self::REFUSED, self::CANCELLED => false,
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
            self::INFO_REQUESTED => 'Demander une précision au locataire ? Il la recevra par email.',
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
            self::INFO_REQUESTED => 'Ce que vous souhaitez savoir',
            self::CANCELLED => "Dire pourquoi, si vous le souhaitez (facultatif)",
        };
    }
}
