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
        /**
         * What the visitor was shown at submission. Frozen: a later
         * negotiation writes to $agreedPrice, never here (§6.11).
         */
        public readonly ?PriceQuote $estimatedPrice,
        public readonly ?int $estimatedTotalCents,
        /**
         * The manager's working copy, and what the renter is actually asked
         * to pay. Null until somebody touches the price, which is why
         * `effectivePrice()` exists rather than every caller writing the
         * same `?:`.
         */
        public readonly ?PriceQuote $agreedPrice,
        public readonly ?int $agreedTotalCents,
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
     * Three cases, and the middle one is the whole reason this takes a
     * clock:
     *
     * - **Refused, cancelled, expired** — never. The period is released the
     *   moment the decision is taken.
     * - **Confirmed or closed** — always. It is a commitment; no deadline
     *   enters into it.
     * - **Anything still provisional** (received, under review, information
     *   requested, an option proposed) — **only while a hold is running**.
     *   A request holds the dates for a configurable period so two visitors
     *   cannot both be told yes, and when that period lapses the dates are
     *   free again while the request itself stays waiting (spec §22.5). The
     *   `automatic_hold_hours` setting says so in as many words, down to
     *   what 0 means.
     *
     * Reading it off the status alone made the hold decorative: an abandoned
     * request blocked its dates against every visitor for ever, and the
     * expiry task clearing `hold_until` made it worse rather than better.
     * Availability is computed, never stored (spec §22.2) — so a hold that
     * lapsed a minute ago frees its dates on this page load, not on the next
     * run of the task.
     */
    public function occupiesTheAsset(\DateTimeImmutable $now): bool
    {
        if (!$this->status->occupiesTheAsset()) {
            return false;
        }

        if ($this->status->firmlyOccupiesTheAsset()) {
            return true;
        }

        return $this->holdIsActive($now);
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
            reference: $this->reference,
            // A request still being weighed holds the dates against the
            // public but is not a commitment, so it must not stop a manager
            // confirming a competing request for the same week.
            isFirm: $this->status->firmlyOccupiesTheAsset()
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
     * The price that is actually in force: the agreed one once it exists,
     * the estimate until then.
     *
     * The renter's tracking page and the manager's view both read this, so
     * the two can never show different figures — which matters because
     * §6.12 makes a price change visible to the renter immediately.
     */
    public function effectivePrice(): ?PriceQuote
    {
        return $this->agreedPrice ?? $this->estimatedPrice;
    }

    public function effectiveTotalCents(): ?int
    {
        return $this->agreedTotalCents ?? $this->estimatedTotalCents;
    }

    /** Whether the price has been negotiated away from the estimate. */
    public function priceHasBeenAgreed(): bool
    {
        return $this->agreedPrice !== null;
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
