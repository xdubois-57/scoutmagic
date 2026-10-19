<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Core\Service\DateInput;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Support;

/**
 * One modification of a booking (#927, chantier §2.3): the new terms as a
 * snapshot — dates, participants, units, the price and its one discount —
 * beside what the booking said when it was saved.
 *
 * A project (`ModificationStatus::DRAFT`, `SENT`) changes nothing on the
 * booking: the dates, the price and the contract shown everywhere else
 * stay the booking's own until the replacement contract is countersigned.
 * What it does do is hold its new dates, without a deadline, for as long
 * as it is active (`toOccupancy()`).
 */
final class BookingModification
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly ModificationStatus $status,
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $units,
        public readonly ?int $persons,
        public readonly ?PriceQuote $price,
        public readonly ?int $totalCents,
        public readonly ?string $discountLabel,
        public readonly ?int $discountCents,
        public readonly string $previousArrivalDate,
        public readonly string $previousDepartureDate,
        public readonly int $previousUnits,
        public readonly ?int $previousPersons,
        public readonly ?int $previousTotalCents,
        /** The replacement contract, once it has gone out. */
        public readonly ?int $contractDocumentId,
        public readonly ?int $createdByMemberId,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $sentAt = null,
        public readonly ?\DateTimeImmutable $closedAt = null,
        public readonly ?int $closedByMemberId = null
    ) {
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Whether its terms may still be edited: a project whose contract has not gone. */
    public function isEditable(): bool
    {
        return $this->status === ModificationStatus::DRAFT;
    }

    /**
     * Whether it asks the asset for something the booking does not hold
     * already: other dates, or more of a countable stock.
     */
    public function movesTheOccupation(): bool
    {
        return $this->arrivalDate !== $this->previousArrivalDate
            || $this->departureDate !== $this->previousDepartureDate
            || $this->units > $this->previousUnits;
    }

    /**
     * Its new dates as an occupancy of the asset, carrying the booking's
     * reference so the booking — and this project — are left out of their
     * own conflicts and of nobody else's — or null when it holds nothing:
     * not active, or nothing moved (chantier §2.3).
     */
    public function toOccupancy(string $bookingReference): ?Occupancy
    {
        if (!$this->isActive() || !$this->movesTheOccupation()) {
            return null;
        }

        return new Occupancy(
            arrivalDate: $this->arrivalDate,
            departureDate: $this->departureDate,
            units: max(1, $this->units),
            reference: $bookingReference
        );
    }

    /**
     * What it changes, in a manager's words: « Dates du 12/03/2027 au
     * 14/03/2027 → du 12/03/2027 au 15/03/2027 · 25 → 32 participants ·
     * 540,00 € → 668,00 € ». Only what differs; « Aucun changement » when
     * nothing does.
     */
    public function summary(): string
    {
        $parts = [];
        if ($this->arrivalDate !== $this->previousArrivalDate
            || $this->departureDate !== $this->previousDepartureDate
        ) {
            $parts[] = 'Dates ' . self::range($this->previousArrivalDate, $this->previousDepartureDate)
                . ' → ' . self::range($this->arrivalDate, $this->departureDate);
        }
        if ($this->units !== $this->previousUnits) {
            $parts[] = $this->previousUnits . ' → ' . $this->units . ' unités';
        }
        if ($this->persons !== $this->previousPersons) {
            $parts[] = ($this->previousPersons ?? '—') . ' → ' . ($this->persons ?? '—') . ' participants';
        }
        if ($this->totalCents !== $this->previousTotalCents) {
            $parts[] = ($this->previousTotalCents !== null ? Support::euros($this->previousTotalCents) : '—')
                . ' → ' . ($this->totalCents !== null ? Support::euros($this->totalCents) : '—');
        }

        return $parts === [] ? 'Aucun changement' : implode(' · ', $parts);
    }

    /** « du 12/03/2027 au 14/03/2027 ». */
    private static function range(string $arrival, string $departure): string
    {
        return 'du ' . self::french($arrival) . ' au ' . self::french($departure);
    }

    private static function french(string $isoDate): string
    {
        return DateInput::iso($isoDate)?->format('d/m/Y') ?? $isoDate;
    }
}
