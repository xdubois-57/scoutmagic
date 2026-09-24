<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Core\Service\DateInput;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\Settlement;

/**
 * What the booking's own records say about the milestones BookingMilestones
 * cannot derive from the booking row alone (§6.15).
 *
 * BookingMilestones has always taken these as an `$extras` map, with a key's
 * ABSENCE meaning "not applicable, render greyed" — written that way while
 * the contract, the payments, the meters, the inventories and the settlement
 * were each still an unlanded iteration. They have all landed since, and
 * nothing ever filled the map in: the checklist was called with no extras at
 * all, so ten of its fourteen lines were permanently greyed. Sending the
 * contract, cashing the deposit, finishing an inventory — none of it moved a
 * single box, which is exactly the drift the "derived, never stored" design
 * exists to prevent.
 *
 * Pure, and deliberately fed rather than self-loading: the booking page has
 * already loaded every one of these for its own panels, and a service that
 * re-queried them would put the checklist one round trip behind the page
 * showing the same facts.
 *
 * A `null` collection means the feature is not available on this
 * installation (the module it belongs to is off), which is the one case
 * that still renders greyed. An EMPTY collection is a different answer —
 * "this asset has no meters", "no inventory template" — and also renders
 * greyed, because a checklist line nobody can ever tick is noise either way.
 */
final class MilestoneEvidence
{
    /**
     * @param array<string, bool> $done keyed by BookingMilestones' constants;
     *   a key absent from this map is "not applicable"
     * @param array<string, string> $details the small grey suffix each line
     *   may carry (a date, a version) — same keying
     * @param list<string> $offsite the keys ticked by hand on this booking,
     *   because the site keeps nothing they could be derived from
     */
    private function __construct(
        public readonly array $done,
        public readonly array $details,
        public readonly array $offsite = []
    ) {
    }

    /**
     * @param RentalDocument[]|null $documents null when documents are unavailable
     * @param array<string, mixed> $payment RentalManagementController::paymentStatus()'s shape
     * @param array<int, array{arrival_state: InventoryState, departure_state: InventoryState}>|null $inventory
     *   the booking's inventory snapshot; null when the stay module is unavailable
     * @param MeterConsumption[]|null $consumptions null when the stay module is unavailable
     * @param bool $assetKeepsInventory whether the asset has an inventory the
     *   stay page walks line by line — false when it has no template at all
     * @param array<string, array{at: \DateTimeImmutable, by: ?string}> $marks
     *   the lines a manager ticked by hand, keyed by milestone
     * @param ?\DateTimeImmutable $today what a due date is measured against;
     *   null leaves the payment lines without one
     */
    public static function collect(
        RentalBooking $booking,
        ?array $documents,
        array $payment,
        ?array $inventory,
        ?array $consumptions,
        ?Settlement $settlement,
        bool $assetKeepsInventory = true,
        array $marks = [],
        ?\DateTimeImmutable $today = null
    ): self {
        $done = [];
        $details = [];
        $offsite = [];

        $record = static function (string $key, bool $isDone, ?string $detail = null) use (&$done, &$details): void {
            $done[$key] = $isDone;
            if ($detail !== null && $detail !== '') {
                $details[$key] = $detail;
            }
        };

        if ($documents !== null) {
            $sentContract = self::lastSent($documents, DocumentType::CONTRACT);
            $record(
                BookingMilestones::CONTRACT_SENT,
                $sentContract !== null,
                $sentContract?->sentAt?->format('d/m/Y')
            );

            // "Accepté" is the signed copy coming back, not the manager
            // pressing send: the conditions the renter ticked on the public
            // form are the other half of the same line, and they are what
            // the detail shows while the contract itself is still out.
            $signed = self::firstOfType($documents, DocumentType::SIGNED_CONTRACT);
            $record(
                BookingMilestones::CONTRACT_ACCEPTED,
                $signed !== null,
                $signed !== null
                    ? $signed->createdAt->format('d/m/Y')
                    : ($booking->conditionsAcceptedAt !== null
                        ? 'conditions acceptées le ' . $booking->conditionsAcceptedAt->format('d/m/Y')
                        : null)
            );
        }

        if (($payment['enabled'] ?? false) === true) {
            $receivedCents = is_int($payment['received_cents'] ?? null) ? $payment['received_cents'] : 0;
            $depositCents = $payment['deposit_cents'] ?? null;
            if (is_int($depositCents) && $depositCents > 0) {
                $isReceived = ($payment['deposit_received'] ?? false) === true;
                $record(
                    BookingMilestones::DEPOSIT_RECEIVED,
                    $isReceived,
                    $isReceived ? null : self::owed($depositCents - $receivedCents, $payment['deposit_due_date'] ?? null, $today)
                );
            }

            $totalCents = $payment['total_cents'] ?? null;
            if (is_int($totalCents) && $totalCents > 0) {
                $isPaid = ($payment['fully_paid'] ?? false) === true;
                $record(
                    BookingMilestones::BALANCE_RECEIVED,
                    $isPaid,
                    $isPaid ? null : self::owed($totalCents - $receivedCents, $payment['balance_due_date'] ?? null, $today)
                );
            }
        }

        $security = is_array($payment['security_deposit'] ?? null) ? $payment['security_deposit'] : [];
        $securityCents = $security['amount_cents'] ?? null;
        if (is_int($securityCents) && $securityCents > 0) {
            $status = $security['status'] ?? SecurityDepositStatus::NONE;
            $isSettled = $status instanceof SecurityDepositStatus && $status->isSettled();
            $isHeld = $isSettled || ($status instanceof SecurityDepositStatus && $status->isHeld());
            $securityReceived = is_int($security['received_cents'] ?? null) ? $security['received_cents'] : 0;
            $record(
                BookingMilestones::SECURITY_DEPOSIT_RECEIVED,
                $isHeld,
                $isHeld ? null : self::owed($securityCents - $securityReceived, $security['due_date'] ?? null, $today)
            );
            $record(
                BookingMilestones::SECURITY_DEPOSIT_RETURNED,
                $isSettled,
                self::frenchDate($security['returned_at'] ?? null)
            );
        }

        // The walk-throughs happen whether or not the site keeps an
        // inventory. Where it keeps none — the stay module is off, or the
        // asset has no inventory template — nothing here can derive them,
        // so they are the lines a manager ticks by hand (issue #462, D5);
        // where it keeps one, the stay page's lines decide, and a hand tick
        // beside them would be a second truth.
        if ($inventory === null || !$assetKeepsInventory) {
            foreach ([BookingMilestones::ARRIVAL_INVENTORY, BookingMilestones::DEPARTURE_INVENTORY] as $key) {
                $offsite[] = $key;
                $mark = $marks[$key] ?? null;
                $record(
                    $key,
                    $mark !== null,
                    $mark === null
                        ? null
                        : 'fait le ' . $mark['at']->format('d/m/Y') . ($mark['by'] !== null ? ' par ' . $mark['by'] : '')
                );
            }
        } elseif ($inventory !== []) {
            $record(BookingMilestones::ARRIVAL_INVENTORY, self::allChecked($inventory, 'arrival_state'));
            $record(BookingMilestones::DEPARTURE_INVENTORY, self::allChecked($inventory, 'departure_state'));
        }

        if ($consumptions !== null && $consumptions !== []) {
            $record(BookingMilestones::METER_READINGS, self::allRead($consumptions));
        }

        // The settlement line is applicable as soon as the stay module can
        // produce one — unlike the meters and the inventory, every booking
        // ends with a reckoning even when there is nothing metered.
        if ($inventory !== null) {
            $record(
                BookingMilestones::FINAL_SETTLEMENT,
                $settlement !== null && $settlement->isValidated,
                $settlement !== null ? 'v' . $settlement->version : null
            );
        }

        return new self($done, $details, $offsite);
    }

    /**
     * What is still owed on a payment line, and against which date: the
     * sentence the journey's heading repeats when this line is the one
     * holding the booking up.
     */
    private static function owed(int $cents, mixed $dueDate, ?\DateTimeImmutable $today): ?string
    {
        if ($cents <= 0) {
            return null;
        }

        $amount = number_format($cents / 100, 2, ',', ' ') . ' €';
        $due = $dueDate instanceof \DateTimeImmutable
            ? $dueDate
            : (is_string($dueDate) && trim($dueDate) !== '' ? DateInput::fromStorage($dueDate) : null);

        if ($due === null || $today === null) {
            return $amount . ' attendus';
        }

        $late = (int) $due->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');
        if ($late > 0) {
            return $amount . ' attendus — échéance dépassée de ' . $late . ' jour' . ($late > 1 ? 's' : '');
        }

        return $amount . ' attendus pour le ' . $due->format('d/m/Y');
    }

    /**
     * @param RentalDocument[] $documents
     */
    private static function lastSent(array $documents, DocumentType $type): ?RentalDocument
    {
        $found = null;
        foreach ($documents as $document) {
            if ($document->type !== $type || $document->sentAt === null) {
                continue;
            }
            if ($found === null || $document->sentAt > $found->sentAt) {
                $found = $document;
            }
        }

        return $found;
    }

    /**
     * @param RentalDocument[] $documents
     */
    private static function firstOfType(array $documents, DocumentType $type): ?RentalDocument
    {
        foreach ($documents as $document) {
            if ($document->type === $type) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Whether every line of the snapshot has actually been looked at for
     * this phase. `NOT_CHECKED` is a real state, never a placeholder
     * (Stay\InventoryState): an inventory nobody finished must not read as
     * a finished one, and a line found broken or missing IS a completed
     * observation.
     *
     * @param array<int, array<string, mixed>> $inventory
     */
    private static function allChecked(array $inventory, string $column): bool
    {
        foreach ($inventory as $line) {
            if (($line[$column] ?? InventoryState::NOT_CHECKED) === InventoryState::NOT_CHECKED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param MeterConsumption[] $consumptions
     */
    private static function allRead(array $consumptions): bool
    {
        foreach ($consumptions as $consumption) {
            if ($consumption->arrival === null || $consumption->departure === null) {
                return false;
            }
        }

        return true;
    }

    private static function frenchDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->format('d/m/Y');
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return DateInput::fromStorage($value)?->format('d/m/Y');
    }
}
