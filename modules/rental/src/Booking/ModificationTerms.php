<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Modules\Rental\Pricing\PriceQuote;

/**
 * The terms a modification asks for (#927, chantier §2.3): dates,
 * participants, units, and the price that goes with them — the tariff's
 * lines for those dates and participants, and one discount at most.
 *
 * Built by `Service\RentalModificationService::termsFor()`, which is the
 * one place the price is computed from them: a caller never hands in a
 * price of its own.
 */
final class ModificationTerms
{
    public function __construct(
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $units,
        public readonly ?int $persons,
        public readonly PriceQuote $price,
        public readonly ?string $discountLabel = null,
        public readonly ?int $discountCents = null
    ) {
    }

    /** Whether these are exactly what the booking already says. */
    public function sameAs(RentalBooking $booking): bool
    {
        return $this->arrivalDate === $booking->arrivalDate
            && $this->departureDate === $booking->departureDate
            && $this->units === $booking->units
            && $this->persons === $booking->estimatedPersons
            && $this->price->totalCents === $booking->effectiveTotalCents()
            && $this->discountCents === self::discountOf($booking->effectivePrice());
    }

    /** Whether they ask the asset for other dates, or more of its stock. */
    public function movesTheOccupation(RentalBooking $booking): bool
    {
        return $this->arrivalDate !== $booking->arrivalDate
            || $this->departureDate !== $booking->departureDate
            || $this->units > $booking->units;
    }

    /**
     * The discount a price carries, or null — the one line a manager
     * types (`Pricing\PriceLine::RULE_DISCOUNT`).
     */
    public static function discountOf(?PriceQuote $price): ?int
    {
        return \Modules\Rental\Pricing\QuoteEditor::discountLine($price)?->amountCents;
    }
}
