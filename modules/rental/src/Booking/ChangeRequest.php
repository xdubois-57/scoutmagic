<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Modules\Rental\Pricing\PriceQuote;

/**
 * A pending or decided change to a booking, from either side (§6.16).
 *
 * `$message` arrives decrypted, like every other renter-written field —
 * `Repository\RentalChangeRequestRepository` is the only place it is ever a
 * ciphertext.
 */
final class ChangeRequest
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly ChangeRequestOrigin $origin,
        public readonly ChangeRequestKind $kind,
        public readonly ChangeRequestStatus $status,
        public readonly ?string $proposedArrivalDate,
        public readonly ?string $proposedDepartureDate,
        public readonly ?int $proposedUnits,
        public readonly ?int $proposedPersons,
        public readonly ?PriceQuote $proposedPrice,
        public readonly ?int $proposedTotalCents,
        public readonly ?string $message,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?int $decidedByMemberId
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === ChangeRequestStatus::PENDING;
    }

    /**
     * Whether $origin is the side allowed to decide this one.
     *
     * Nobody decides their own request — a manager who could accept their
     * own proposal would be changing a booking unilaterally, which is
     * exactly what §6.16 forbids.
     */
    public function isDecidableBy(ChangeRequestOrigin $side): bool
    {
        return $this->isPending() && $side === $this->origin->decidedBy();
    }

    /** A one-line summary for the history, carrying no personal data. */
    public function summary(): string
    {
        return match ($this->kind) {
            ChangeRequestKind::DATES => sprintf(
                'du %s au %s',
                self::frenchDate($this->proposedArrivalDate),
                self::frenchDate($this->proposedDepartureDate)
            ),
            ChangeRequestKind::PERSONS => ($this->proposedPersons ?? 0) . ' participants',
            ChangeRequestKind::CANCELLATION => 'annulation demandée',
        };
    }

    private static function frenchDate(?string $isoDate): string
    {
        if ($isoDate === null) {
            return '—';
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);

        return $parsed !== false ? $parsed->format('d/m/Y') : $isoDate;
    }
}
