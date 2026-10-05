<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Core\Service\DateInput;

/**
 * What the renter has to do next — or that they have nothing to do — in
 * their own words (#708, IT-15): the « Et maintenant ? » block of every
 * e-mail they receive, and the sentence under the status on their
 * tracking page.
 *
 * **One source.** Read off the booking's next step (`BookingJourney::next()`)
 * and the status, the derivation the manager's dashboard and « À traiter »
 * already read — never written by hand in each e-mail, so the page, the
 * list and the e-mails cannot say three different things. A step ticked by
 * hand is done (IT-14), so the sentence moves on to the next one by itself.
 *
 * No « jalon », no « statut », no table name: every sentence is the
 * renter's, and says what, where and by when when there is a date.
 * `RenterNextStepTest` walks every step key and every status.
 *
 * Pure: the booking, the journey, the payment status and the clock are
 * handed in.
 */
final class RenterNextStep
{
    public function __construct(
        public readonly string $sentence,
        /** Whether what the renter has to do is done on their tracking page. */
        public readonly bool $onTrackingPage = false
    ) {
    }

    /**
     * @param array<string, mixed> $payment `RentalPaymentService::statusFor()`
     */
    public static function of(
        RentalBooking $booking,
        BookingJourney $journey,
        \DateTimeImmutable $now,
        array $payment = []
    ): self {
        $byStatus = self::forStatus($booking, $now);
        if ($byStatus !== null) {
            return $byStatus;
        }

        $next = $journey->next();
        if ($next === null) {
            return new self('Rien à faire de votre côté : tout est en ordre.');
        }

        return self::forStep($next->key, $booking, $now, $payment);
    }

    /**
     * The statuses that say it themselves, whatever the next step is: an
     * answer the renter owes, or a file that will not move again. Null for
     * the others, whose next step speaks.
     */
    public static function forStatus(RentalBooking $booking, \DateTimeImmutable $now): ?self
    {
        return match ($booking->status) {
            BookingStatus::INFO_REQUESTED => new self(
                'À vous : répondez à notre question depuis votre page de suivi.' . self::hold($booking, $now),
                true
            ),
            BookingStatus::PROPOSED => new self(
                'À vous : acceptez ou refusez notre proposition depuis votre page de suivi.' . self::hold($booking, $now),
                true
            ),
            BookingStatus::REFUSED => new self("Rien à faire de votre côté : votre demande n'a pas pu être acceptée."),
            BookingStatus::CANCELLED => new self('Rien à faire de votre côté : cette réservation est annulée.'),
            BookingStatus::EXPIRED => new self(
                "Rien à faire de votre côté : cette demande a expiré. Vous pouvez en faire une nouvelle à tout moment."
            ),
            BookingStatus::CLOSED => new self('Rien à faire de votre côté : votre location est terminée. Merci !'),
            BookingStatus::RECEIVED, BookingStatus::CONTRACT_SENT, BookingStatus::CONFIRMED => null,
        };
    }

    /**
     * One sentence per step, for the step that is next.
     *
     * @param array<string, mixed> $payment
     * @throws \LogicException for a step nobody wrote a sentence for —
     *   `RenterNextStepTest` fails first
     */
    public static function forStep(
        string $key,
        RentalBooking $booking,
        \DateTimeImmutable $now,
        array $payment = []
    ): self {
        $waiting = "Rien à faire de votre côté pour l'instant : ";
        $securityDeposit = is_array($payment['security_deposit'] ?? null) ? $payment['security_deposit'] : [];

        return match ($key) {
            'request_received', 'hold', BookingMilestones::CONTRACT_GENERATED, BookingMilestones::CONTRACT_SENT
                => new self(
                    $waiting . ($booking->status === BookingStatus::RECEIVED
                        ? 'nous étudions votre demande et vous enverrons le contrat.'
                        : 'nous préparons votre contrat et vous l\'enverrons.')
                    . self::hold($booking, $now)
                ),
            BookingMilestones::SIGNED_COPY_RECEIVED => self::signContract($booking, $now),
            BookingMilestones::CONTRACT_COUNTERSIGNED => new self(
                $waiting . 'nous vérifions votre copie signée et vous renverrons le contrat signé par les deux parties.'
            ),
            BookingMilestones::DEPOSIT_RECEIVED => self::pay(
                "l'acompte",
                $payment['deposit_due_date'] ?? null,
                $payment
            ),
            'confirmed' => new self(
                $waiting . ($booking->status === BookingStatus::RECEIVED
                    ? 'nous étudions votre demande et vous écrirons dès qu\'elle est confirmée.'
                    : 'nous confirmons votre réservation et vous écrirons dès que c\'est fait.')
                . self::hold($booking, $now)
            ),
            BookingMilestones::BALANCE_RECEIVED => self::pay(
                'le solde',
                $payment['balance_due_date'] ?? null,
                $payment
            ),
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED => self::pay(
                'la caution',
                $securityDeposit['due_date'] ?? null,
                $securityDeposit
            ),
            BookingMilestones::ARRIVAL_INVENTORY => new self(
                "Rien à faire de votre côté avant votre arrivée : "
                . "l'état des lieux d'entrée se fera avec vous sur place."
            ),
            BookingMilestones::DEPARTURE_INVENTORY => new self(
                "Rien à faire de votre côté : l'état des lieux de sortie se fera avec vous à votre départ."
            ),
            BookingMilestones::FINAL_SETTLEMENT => new self(
                $waiting . 'nous établissons le décompte final et vous enverrons la facture.'
            ),
            BookingMilestones::SECURITY_DEPOSIT_RETURNED => new self(
                $waiting . 'nous vous restituons la caution.'
            ),
            'closed' => new self($waiting . 'nous clôturons votre dossier.'),
            default => throw new \LogicException("No renter sentence for the step '{$key}'."),
        };
    }

    /**
     * The contract on its way, or waiting for their copy: sign it, send
     * the copy back from the tracking page, before the dates are released.
     * The contract's own e-mail passes the hold it is about to set: the
     * booking still carries the one before.
     */
    public static function signContract(
        RentalBooking $booking,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $holdUntil = null
    ): self
    {
        $hold = $holdUntil !== null
            ? " Les dates vous sont réservées jusqu'au " . $holdUntil->format('d/m/Y') . '.'
            : self::hold($booking, $now);

        return new self(
            'À vous : signez le contrat et déposez votre copie signée sur votre page de suivi.' . $hold,
            true
        );
    }

    /** An invoice calls for its payment, whatever the journey says next. */
    public static function payInvoice(): self
    {
        return new self('À vous : réglez la facture selon les modalités qu\'elle indique.');
    }

    /**
     * @param array<string, mixed> $payment the part carrying `communication`
     */
    private static function pay(string $what, mixed $dueDate, array $payment): self
    {
        $due = is_string($dueDate) ? DateInput::iso($dueDate) : null;
        $communication = is_string($payment['communication'] ?? null) && $payment['communication'] !== ''
            ? ' en indiquant la communication ' . $payment['communication']
            : '';

        return new self(
            'À vous : versez ' . $what . $communication
            . ($due !== null ? ' avant le ' . $due->format('d/m/Y') : '') . '.'
        );
    }

    /**
     * « Les dates vous sont réservées jusqu'au … » while they are held, and
     * not a day longer: a lapsed hold must not be promised to the renter
     * while the manager's « Cycle de vie » says the dates are free (IT-01).
     */
    private static function hold(RentalBooking $booking, \DateTimeImmutable $now): string
    {
        return $booking->holdIsActive($now) && $booking->status !== BookingStatus::CONFIRMED
            ? " Les dates vous sont réservées jusqu'au " . $booking->holdUntil->format('d/m/Y') . '.'
            : '';
    }
}
