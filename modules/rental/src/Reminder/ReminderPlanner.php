<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Reminder;

use Core\Service\DateInput;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Compliance\ComplianceItem;
use Modules\Rental\Repository\RentalAsset;

/**
 * What is due today, and nothing else (§6.29).
 *
 * **Pure**: every input is a parameter, no repository, no clock, no I/O.
 * That is what makes "does a reminder fire on the right day" a question a
 * test can answer directly rather than through a database and a scheduler
 * — and these are exactly the rules that are easy to get subtly wrong and
 * hard to notice, because the failure is a message nobody receives.
 *
 * Deciding *what* is due is deliberately separate from deciding *who* hears
 * about it and *how* (`RentalReminderService`): the audience rule — a
 * renter has no `user_account`, so their reminder is an email — belongs
 * with the dispatching, and mixing the two is how a renter's reminder ends
 * up in a notification centre nobody reads.
 */
class ReminderPlanner
{
    /**
     * Every delay used below now comes from the asset's own schedule
     * (`ReminderSchedule`), which resolves the shipped value, the unit's
     * default and the asset's override in that order. The constants that
     * used to sit here are `ReminderKind::defaultDays()`, where the other
     * two levels can find them.
     *
     * A reminder an asset has switched off never becomes due at all — it is
     * not emitted and then filtered later, because "due but suppressed" is a
     * state nothing needs and one more thing a reader has to hold.
     */

    /**
     * Everything due for one booking today.
     *
     * @param array<string, mixed> $payment the shape RentalPaymentService::statusFor() returns
     * @param array{arrival: bool, departure: bool} $inventory whether each inventory has been recorded
     * @return DueReminder[]
     */
    public function forBooking(
        RentalBooking $booking,
        RentalAsset $asset,
        array $payment,
        array $inventory,
        bool $hasContract,
        bool $hasSettlement,
        \DateTimeImmutable $today,
        ?ReminderSchedule $schedule = null
    ): array {
        $schedule ??= ReminderSchedule::shipped();
        $due = [];
        $arrival = DateInput::requireFromStorage($booking->arrivalDate, 'rental_bookings.arrival_date');
        $departure = DateInput::requireFromStorage($booking->departureDate, 'rental_bookings.departure_date');
        $midnight = $today->setTime(0, 0);

        // ── While the request is still being handled ──────────────────
        $unansweredDays = $schedule->daysFor(ReminderKind::UNANSWERED_REQUEST);
        if ($booking->status === BookingStatus::RECEIVED
            && $schedule->isActive(ReminderKind::UNANSWERED_REQUEST)
            && $booking->receivedAt <= $today->modify('-' . $unansweredDays . ' days')
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::UNANSWERED_REQUEST,
                sprintf(
                    'La demande %s attend une réponse depuis %d jours.',
                    $booking->reference,
                    $unansweredDays
                )
            );
        }

        // A hold about to lapse: the dates are about to become free again
        // for everybody, which is a decision the unit should make rather
        // than discover.
        if ($booking->holdIsActive($today)
            && $schedule->isActive(ReminderKind::HOLD_EXPIRING)
            && $booking->holdUntil !== null
            && $booking->holdUntil <= $today->modify(
                '+' . $schedule->daysFor(ReminderKind::HOLD_EXPIRING) . ' days'
            )
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::HOLD_EXPIRING,
                sprintf(
                    'Le blocage des dates de %s expire bientôt : les dates redeviendront libres.',
                    $booking->reference
                )
            );
        }

        if ($booking->status !== BookingStatus::CONFIRMED && $booking->status !== BookingStatus::CLOSED) {
            // Nothing below concerns a stay that is not going ahead.
            return $due;
        }

        // ── Money ────────────────────────────────────────────────────
        $due = array_merge($due, $this->paymentReminders($booking, $asset, $payment, $midnight, $schedule, $arrival));

        // ── Paperwork and the stay itself ────────────────────────────
        if (!$hasContract
            && $schedule->isActive(ReminderKind::CONTRACT_MISSING)
            && $arrival <= $midnight->modify(
                '+' . $schedule->daysFor(ReminderKind::CONTRACT_MISSING) . ' days'
            )
            && $arrival >= $midnight
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::CONTRACT_MISSING,
                sprintf(
                    "Aucun contrat n'a encore été établi pour %s, dont le séjour approche.",
                    $booking->reference
                )
            );
        }

        // The renter's own reminder — email, never the notification centre
        // (§6.29). Sent from the day it comes into range rather than
        // exactly on J-7, so a scheduler that missed a day still sends it.
        if ($schedule->isActive(ReminderKind::PRACTICAL_INFO)
            && $arrival <= $midnight->modify('+' . $schedule->daysFor(ReminderKind::PRACTICAL_INFO) . ' days')
            && $arrival >= $midnight
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::PRACTICAL_INFO,
                sprintf(
                    'Votre séjour à %s approche.',
                    $asset->name
                )
            );
        }

        if (!$inventory['arrival']
            && $schedule->isActive(ReminderKind::ARRIVAL_INVENTORY)
            && $midnight >= $arrival->modify('+' . $schedule->daysFor(ReminderKind::ARRIVAL_INVENTORY) . ' days')
            && $midnight <= $departure
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::ARRIVAL_INVENTORY,
                sprintf(
                    "L'état des lieux d'entrée de %s n'a pas été enregistré.",
                    $booking->reference
                )
            );
        }

        if (!$inventory['departure']
            && $schedule->isActive(ReminderKind::DEPARTURE_INVENTORY)
            && $midnight > $departure->modify('+' . $schedule->daysFor(ReminderKind::DEPARTURE_INVENTORY) . ' days')
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::DEPARTURE_INVENTORY,
                sprintf(
                    "L'état des lieux de sortie de %s n'a pas été enregistré.",
                    $booking->reference
                )
            );
        }

        if (!$hasSettlement
            && $schedule->isActive(ReminderKind::SETTLEMENT_DUE)
            && $midnight >= $departure->modify('+' . $schedule->daysFor(ReminderKind::SETTLEMENT_DUE) . ' days')
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::SETTLEMENT_DUE,
                sprintf(
                    'Le décompte final de %s reste à établir.',
                    $booking->reference
                )
            );
        }

        return $due;
    }

    /**
     * @param array<string, mixed> $payment
     * @return DueReminder[]
     */
    private function paymentReminders(
        RentalBooking $booking,
        RentalAsset $asset,
        array $payment,
        \DateTimeImmutable $midnight,
        ReminderSchedule $schedule,
        \DateTimeImmutable $arrival
    ): array {
        if (($payment['enabled'] ?? false) !== true) {
            // Money is not tracked for this asset; there is nothing
            // truthful to say about it.
            return [];
        }

        $due = [];

        // **They stop at arrival.** A weekly chase about an unpaid deposit
        // is worth saying while it can still change something; the same
        // sentence repeated after the renters have moved in is a channel
        // teaching the unit to ignore it (§6.29).
        $stillWorthAsking = $midnight <= $arrival;

        $depositDue = self::dateOrNull($payment['deposit_due_date'] ?? null);
        if ($depositDue !== null
            && $stillWorthAsking
            && $schedule->isActive(ReminderKind::DEPOSIT_MISSING)
            && $depositDue->modify('+' . $schedule->daysFor(ReminderKind::DEPOSIT_MISSING) . ' days') < $midnight
            && ($payment['deposit_received'] ?? false) !== true
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::DEPOSIT_MISSING,
                sprintf(
                    "L'acompte de %s n'a pas été reçu à la date prévue.",
                    $booking->reference
                )
            );
        }

        $balanceDue = self::dateOrNull($payment['balance_due_date'] ?? null);
        if ($balanceDue !== null
            && $stillWorthAsking
            && $schedule->isActive(ReminderKind::BALANCE_MISSING)
            && $balanceDue->modify('+' . $schedule->daysFor(ReminderKind::BALANCE_MISSING) . ' days') < $midnight
            && ($payment['fully_paid'] ?? false) !== true
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::BALANCE_MISSING,
                sprintf(
                    "Le solde de %s n'a pas été reçu à la date prévue.",
                    $booking->reference
                )
            );
        }

        $security = is_array($payment['security_deposit'] ?? null) ? $payment['security_deposit'] : [];
        $securityDue = self::dateOrNull($security['due_date'] ?? null);
        $securityAmount = $security['amount_cents'] ?? null;
        $securityReceived = (int) ($security['received_cents'] ?? 0);

        if ($securityDue !== null
            && $stillWorthAsking
            && $schedule->isActive(ReminderKind::SECURITY_DEPOSIT_MISSING)
            && $securityDue->modify(
                '+' . $schedule->daysFor(ReminderKind::SECURITY_DEPOSIT_MISSING) . ' days'
            ) < $midnight
            && $securityAmount !== null
            && $securityReceived < (int) $securityAmount
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::SECURITY_DEPOSIT_MISSING,
                sprintf(
                    "La caution de %s n'a pas été reçue à la date prévue.",
                    $booking->reference
                )
            );
        }

        // A security deposit still held some time after the stay. The
        // renter is owed their money back, and nobody but the unit knows
        // it is sitting there.
        $departure = DateInput::requireFromStorage($booking->departureDate, 'rental_bookings.departure_date');
        if ($securityReceived > 0
            && $schedule->isActive(ReminderKind::SECURITY_DEPOSIT_TO_RETURN)
            && ($security['returned_at'] ?? null) === null
            && $midnight >= $departure->modify(
                '+' . $schedule->daysFor(ReminderKind::SECURITY_DEPOSIT_TO_RETURN) . ' days'
            )
        ) {
            $due[] = $this->booking(
                $booking,
                $asset,
                ReminderKind::SECURITY_DEPOSIT_TO_RETURN,
                sprintf(
                    'La caution de %s est toujours détenue par l\'unité.',
                    $booking->reference
                )
            );
        }

        return $due;
    }

    /**
     * A register entry that has expired or is about to (§6.33).
     *
     * Phrased as a fact about a date, never as a compliance verdict: the
     * module does not know whether the hall may be let without that paper,
     * and saying so would be a legal opinion.
     */
    public function forComplianceItem(
        ComplianceItem $item,
        RentalAsset $asset,
        \DateTimeImmutable $today,
        ?ReminderSchedule $schedule = null
    ): ?DueReminder {
        if ($item->expiresOn === null) {
            return null;
        }

        if (!($schedule ?? ReminderSchedule::shipped())->isActive(ReminderKind::COMPLIANCE_EXPIRING)) {
            return null;
        }

        $days = $item->daysUntilExpiry($today);
        if ($days === null) {
            return null;
        }

        $body = $days < 0
            ? sprintf('« %s » (%s) a expiré le %s.', $item->label, $asset->name, self::displayDate($item->expiresOn))
            : sprintf('« %s » (%s) expire le %s.', $item->label, $asset->name, self::displayDate($item->expiresOn));

        return new DueReminder(
            kind: ReminderKind::COMPLIANCE_EXPIRING,
            subjectId: $item->id,
            title: ReminderKind::COMPLIANCE_EXPIRING->label(),
            body: $body,
            url: '/mes-locations/' . $asset->slug . '/conformite',
            assetId: $asset->id
        );
    }

    private function booking(
        RentalBooking $booking,
        RentalAsset $asset,
        ReminderKind $kind,
        string $body
    ): DueReminder {
        return new DueReminder(
            kind: $kind,
            subjectId: $booking->id,
            title: $kind->label(),
            body: $body,
            url: '/mes-locations/' . $asset->slug . '/reservations/' . $booking->id,
            assetId: $asset->id
        );
    }

    private static function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return DateInput::fromStorage($value)?->setTime(0, 0);
    }

    /**
     * $date is a compliance entry's expires_on, and it reaches here only
     * after ComplianceItem has read it as a date — so an unreadable one
     * would be a bug upstream. The reminder still says something rather
     * than nothing: the raw value beats a blank in a message whose whole
     * point is a deadline.
     */
    private static function displayDate(string $date): string
    {
        return DateInput::fromStorage($date)?->format('d/m/Y') ?? $date;
    }
}
