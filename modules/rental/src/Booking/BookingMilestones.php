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
     * @param array<string, bool> $extras
     * @param array<string, string> $details the grey suffix an extra line may
     *   carry — a send date, a settlement version (Booking\MilestoneEvidence)
     * @return list<BookingMilestone>
     */
    public static function for(
        RentalBooking $booking,
        \DateTimeImmutable $now,
        array $extras = [],
        array $details = []
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

        // The two holds are one line, because to the manager they are one
        // fact — "the dates are held until X" — and the wording is what
        // says which kind it is (§6.14).
        $milestones[] = new BookingMilestone(
            'hold',
            $booking->holdOrigin?->managerLabel() ?? 'Dates bloquées',
            $booking->holdIsActive($now),
            $booking->holdUntil !== null,
            $booking->holdUntil?->format('d/m/Y à H\hi')
        );

        $milestones[] = self::extra($extras, self::CONTRACT_SENT, 'Contrat envoyé', $details);
        $milestones[] = self::extra($extras, self::CONTRACT_ACCEPTED, 'Conditions et contrat acceptés', $details);
        $milestones[] = self::extra($extras, self::DEPOSIT_RECEIVED, 'Acompte reçu', $details);

        $milestones[] = new BookingMilestone(
            'confirmed',
            'Réservation confirmée',
            $booking->status === BookingStatus::CONFIRMED
                || $booking->status === BookingStatus::CLOSED,
            // A refused, cancelled or expired booking is never going to be
            // confirmed; showing the box at all would suggest otherwise.
            !in_array(
                $booking->status,
                [BookingStatus::REFUSED, BookingStatus::CANCELLED, BookingStatus::EXPIRED],
                true
            )
        );

        $milestones[] = self::extra($extras, self::BALANCE_RECEIVED, 'Solde reçu', $details);
        $milestones[] = self::extra($extras, self::SECURITY_DEPOSIT_RECEIVED, 'Caution reçue', $details);
        $milestones[] = self::extra($extras, self::ARRIVAL_INVENTORY, "État des lieux d'entrée", $details);
        $milestones[] = self::extra($extras, self::METER_READINGS, 'Relevés de compteurs', $details);
        $milestones[] = self::extra($extras, self::DEPARTURE_INVENTORY, 'État des lieux de sortie', $details);
        $milestones[] = self::extra($extras, self::FINAL_SETTLEMENT, 'Décompte final réglé', $details);
        $milestones[] = self::extra($extras, self::SECURITY_DEPOSIT_RETURNED, 'Caution restituée', $details);

        $milestones[] = new BookingMilestone(
            'closed',
            'Location clôturée',
            $booking->status === BookingStatus::CLOSED,
            $booking->status !== BookingStatus::REFUSED
                && $booking->status !== BookingStatus::CANCELLED
                && $booking->status !== BookingStatus::EXPIRED
        );

        return $milestones;
    }

    /**
     * @param array<string, bool> $extras
     * @param array<string, string> $details
     */
    private static function extra(array $extras, string $key, string $label, array $details = []): BookingMilestone
    {
        return new BookingMilestone(
            $key,
            $label,
            $extras[$key] ?? false,
            array_key_exists($key, $extras),
            $details[$key] ?? null
        );
    }
}
