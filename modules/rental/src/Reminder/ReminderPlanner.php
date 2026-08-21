<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Reminder;

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
    /** A request nobody has answered after this long is chased. */
    public const UNANSWERED_AFTER_DAYS = 3;

    /** A hold lapsing inside this window is worth mentioning. */
    public const HOLD_EXPIRING_WITHIN_HOURS = 24;

    /** The practical-info email goes out this far ahead of arrival. */
    public const PRACTICAL_INFO_DAYS_BEFORE = 7;

    /** A confirmed stay this close with no contract is chased. */
    public const CONTRACT_MISSING_DAYS_BEFORE = 14;

    /** After the stay: how long before the settlement is chased. */
    public const SETTLEMENT_AFTER_DAYS = 7;

    /** And how long a security deposit may sit before somebody is nudged. */
    public const DEPOSIT_RETURN_AFTER_DAYS = 14;

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
        \DateTimeImmutable $today
    ): array {
        $due = [];
        $arrival = new \DateTimeImmutable($booking->arrivalDate);
        $departure = new \DateTimeImmutable($booking->departureDate);
        $midnight = $today->setTime(0, 0);

        // ── While the request is still being handled ──────────────────
        if ($booking->status === BookingStatus::RECEIVED) {
            if ($booking->receivedAt <= $today->modify('-' . self::UNANSWERED_AFTER_DAYS . ' days')) {
                $due[] = $this->booking($booking, $asset, ReminderKind::UNANSWERED_REQUEST, sprintf(
                    'La demande %s attend une réponse depuis %d jours.',
                    $booking->reference,
                    self::UNANSWERED_AFTER_DAYS
                ));
            }
        }

        // A hold about to lapse: the dates are about to become free again
        // for everybody, which is a decision the unit should make rather
        // than discover.
        if ($booking->holdIsActive($today)
            && $booking->holdUntil !== null
            && $booking->holdUntil <= $today->modify('+' . self::HOLD_EXPIRING_WITHIN_HOURS . ' hours')
        ) {
            $due[] = $this->booking($booking, $asset, ReminderKind::HOLD_EXPIRING, sprintf(
                'Le blocage des dates de %s expire bientôt : les dates redeviendront libres.',
                $booking->reference
            ));
        }

        if ($booking->status !== BookingStatus::CONFIRMED && $booking->status !== BookingStatus::CLOSED) {
            // Nothing below concerns a stay that is not going ahead.
            return $due;
        }

        // ── Money ────────────────────────────────────────────────────
        $due = array_merge($due, $this->paymentReminders($booking, $asset, $payment, $midnight));

        // ── Paperwork and the stay itself ────────────────────────────
        if (!$hasContract
            && $arrival <= $midnight->modify('+' . self::CONTRACT_MISSING_DAYS_BEFORE . ' days')
            && $arrival >= $midnight
        ) {
            $due[] = $this->booking($booking, $asset, ReminderKind::CONTRACT_MISSING, sprintf(
                "Aucun contrat n'a encore été établi pour %s, dont le séjour approche.",
                $booking->reference
            ));
        }

        // The renter's own reminder — email, never the notification centre
        // (§6.29). Sent from the day it comes into range rather than
        // exactly on J-7, so a scheduler that missed a day still sends it.
        if ($arrival <= $midnight->modify('+' . self::PRACTICAL_INFO_DAYS_BEFORE . ' days') && $arrival >= $midnight) {
            $due[] = $this->booking($booking, $asset, ReminderKind::PRACTICAL_INFO, sprintf(
                'Votre séjour à %s approche.',
                $asset->name
            ));
        }

        if (!$inventory['arrival'] && $midnight >= $arrival && $midnight <= $departure) {
            $due[] = $this->booking($booking, $asset, ReminderKind::ARRIVAL_INVENTORY, sprintf(
                "L'état des lieux d'entrée de %s n'a pas été enregistré.",
                $booking->reference
            ));
        }

        if (!$inventory['departure'] && $midnight > $departure) {
            $due[] = $this->booking($booking, $asset, ReminderKind::DEPARTURE_INVENTORY, sprintf(
                "L'état des lieux de sortie de %s n'a pas été enregistré.",
                $booking->reference
            ));
        }

        if (!$hasSettlement && $midnight >= $departure->modify('+' . self::SETTLEMENT_AFTER_DAYS . ' days')) {
            $due[] = $this->booking($booking, $asset, ReminderKind::SETTLEMENT_DUE, sprintf(
                'Le décompte final de %s reste à établir.',
                $booking->reference
            ));
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
        \DateTimeImmutable $midnight
    ): array {
        if (($payment['enabled'] ?? false) !== true) {
            // Money is not tracked for this asset; there is nothing
            // truthful to say about it.
            return [];
        }

        $due = [];

        $depositDue = self::dateOrNull($payment['deposit_due_date'] ?? null);
        if ($depositDue !== null && $depositDue < $midnight && ($payment['deposit_received'] ?? false) !== true) {
            $due[] = $this->booking($booking, $asset, ReminderKind::DEPOSIT_MISSING, sprintf(
                "L'acompte de %s n'a pas été reçu à la date prévue.",
                $booking->reference
            ));
        }

        $balanceDue = self::dateOrNull($payment['balance_due_date'] ?? null);
        if ($balanceDue !== null && $balanceDue < $midnight && ($payment['fully_paid'] ?? false) !== true) {
            $due[] = $this->booking($booking, $asset, ReminderKind::BALANCE_MISSING, sprintf(
                "Le solde de %s n'a pas été reçu à la date prévue.",
                $booking->reference
            ));
        }

        $security = is_array($payment['security_deposit'] ?? null) ? $payment['security_deposit'] : [];
        $securityDue = self::dateOrNull($security['due_date'] ?? null);
        $securityAmount = $security['amount_cents'] ?? null;
        $securityReceived = (int) ($security['received_cents'] ?? 0);

        if ($securityDue !== null
            && $securityDue < $midnight
            && $securityAmount !== null
            && $securityReceived < (int) $securityAmount
        ) {
            $due[] = $this->booking($booking, $asset, ReminderKind::SECURITY_DEPOSIT_MISSING, sprintf(
                "La caution de %s n'a pas été reçue à la date prévue.",
                $booking->reference
            ));
        }

        // A security deposit still held some time after the stay. The
        // renter is owed their money back, and nobody but the unit knows
        // it is sitting there.
        $departure = new \DateTimeImmutable($booking->departureDate);
        if ($securityReceived > 0
            && ($security['returned_at'] ?? null) === null
            && $midnight >= $departure->modify('+' . self::DEPOSIT_RETURN_AFTER_DAYS . ' days')
        ) {
            $due[] = $this->booking($booking, $asset, ReminderKind::SECURITY_DEPOSIT_TO_RETURN, sprintf(
                'La caution de %s est toujours détenue par l\'unité.',
                $booking->reference
            ));
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
        \DateTimeImmutable $today
    ): ?DueReminder {
        if ($item->expiresOn === null) {
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

        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private static function displayDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d/m/Y');
    }
}
