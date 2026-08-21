<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Pricing\PriceQuote;

/**
 * One rental, from a public request to a closed stay.
 *
 * Every identity field here is **decrypted** — hydrating this object is the
 * one place that happens (Repository\RentalBookingRepository). Nothing that
 * receives a `RentalBooking` may put those fields in a log, a journal entry
 * or an error message (SECURITY.md §5).
 */
final class RentalBooking
{
    public function __construct(
        public readonly int $id,
        public readonly int $assetId,
        /** `LOC-YYYY-NNNN` — stable, quotable, never reused. */
        public readonly string $reference,
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $units,
        public readonly ?int $estimatedPersons,
        public readonly ?int $renterCategoryId,
        public readonly string $renterName,
        public readonly string $renterEmail,
        public readonly ?string $renterPhone,
        public readonly ?string $renterOrganisation,
        public readonly ?string $purpose,
        public readonly ?string $renterComment,
        public readonly BookingStatus $status,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly ?\DateTimeImmutable $finalAt,
        public readonly ?\DateTimeImmutable $holdUntil,
        public readonly ?HoldOrigin $holdOrigin,
        public readonly ?PriceQuote $estimatedPrice,
        public readonly ?int $estimatedTotalCents,
        public readonly ?string $conditionsVersion,
        /**
         * A hash of the exact text accepted, so what was agreed stays
         * provable after the wording is reworked — a version string alone
         * is worthless the first time somebody edits the conditions without
         * bumping it. Read back so a manager's view can flag a booking
         * whose conditions have since changed
         * (RentalBookingService::acceptedTextStillMatches()).
         */
        public readonly ?string $conditionsHash,
        public readonly ?\DateTimeImmutable $conditionsAcceptedAt,
        public readonly ?string $privacyVersion,
        public readonly ?string $privacyHash,
        public readonly ?\DateTimeImmutable $privacyAcknowledgedAt
    ) {
    }

    /**
     * Whether a temporary hold is still running at $now.
     *
     * A lapsed hold is simply one whose deadline has passed — the expiry
     * task tidies up the status, but availability must not depend on that
     * task having run yet, or an asset would stay falsely busy between the
     * deadline and the next cron.
     */
    public function holdIsActive(\DateTimeImmutable $now): bool
    {
        return $this->holdUntil !== null && $this->holdUntil > $now;
    }

    /**
     * Whether this booking holds its dates against everyone else at $now.
     *
     * Two independent reasons: the status itself (confirmed, under review…)
     * or a live temporary hold. A booking whose hold has lapsed still holds
     * the dates if its status says so.
     */
    public function occupiesTheAsset(\DateTimeImmutable $now): bool
    {
        return $this->status->occupiesTheAsset();
    }

    /**
     * This booking as an availability `Occupancy`.
     *
     * Deliberately drops everything about who the renter is and why the
     * asset is held: the reference goes in only so a *manager's* calendar
     * can link back, and every public surface takes the same object with no
     * variant to leak (§6.7).
     */
    public function toOccupancy(): Occupancy
    {
        return new Occupancy(
            arrivalDate: $this->arrivalDate,
            departureDate: $this->departureDate,
            units: max(1, $this->units),
            reference: $this->reference
        );
    }

    /**
     * Whether the stay is happening right now — derived from the dates,
     * never stored, so it can never disagree with the calendar (§6.15).
     */
    public function isInProgress(\DateTimeImmutable $now): bool
    {
        if ($this->status !== BookingStatus::CONFIRMED) {
            return false;
        }

        $today = $now->format('Y-m-d');

        return $today >= $this->arrivalDate && $today <= $this->departureDate;
    }

    /**
     * The renter's own display of the deadline, or null when nothing is
     * being held.
     */
    public function holdLabel(): ?string
    {
        return $this->holdOrigin?->label();
    }
}
