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
        /** `LOC-XXXXXX` ({@see BookingReference}) — stable, quotable, never reused. */
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
        public readonly ?\DateTimeImmutable $privacyAcknowledgedAt,
        /**
         * When an automatic hold lapsed while the request was still waiting
         * (#708, IT-01) — kept after the expiry task clears `$holdUntil`, so
         * the page can still say since when the dates are free again.
         */
        public readonly ?\DateTimeImmutable $holdLapsedAt = null,
        /**
         * When the renter's signed copy of the contract in force arrived —
         * the earliest one not refused — or null while there is none
         * (#927, chantier §2.1). **Derived at read, never stored**: the
         * repository computes it from `rental_documents` with the booking,
         * so it can never disagree with the copies themselves. A copy of a
         * contract the booking has outgrown (`superseded_at`) counts for
         * nothing, like the contract it was signed from.
         */
        public readonly ?\DateTimeImmutable $signedCopyReceivedAt = null
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
     * Whether this booking holds its dates **firmly** — against everyone,
     * with no deadline (#927, chantier §2.1).
     *
     * Two ways, both derived and never stored: the booking is confirmed or
     * closed, or the renter has sent a signed copy of the contract in force
     * that the unit has not refused. The second is the renter's own
     * commitment: once they have signed, their dates no longer lapse while
     * the unit checks the copy, countersigns, or waits for the deposit.
     *
     * **It confirms nothing.** The status stays where it is and confirming
     * stays a manager's decision (specifications.md §22.5); this answers
     * "may anybody else take these dates", and nothing more. A refused,
     * cancelled or expired booking holds nothing, whatever it once received.
     */
    public function holdsFirmly(): bool
    {
        if (!$this->status->occupiesTheAsset()) {
            return false;
        }

        return $this->status->firmlyOccupiesTheAsset() || $this->signedCopyReceivedAt !== null;
    }

    /**
     * Whether this booking holds its dates against everyone else at $now.
     *
     * Three cases, and the middle one is the whole reason this takes a
     * clock:
     *
     * - **Refused, cancelled, expired** — never. The period is released the
     *   moment the decision is taken.
     * - **Held firmly** (`holdsFirmly()`: confirmed, closed, or a signed
     *   copy not refused) — always. No deadline enters into it.
     * - **Anything else still provisional** (received, information
     *   requested, an option proposed, a contract sent and not yet signed)
     *   — **only while a hold is running**. A request holds the dates for a
     *   configurable period so two visitors cannot both be told yes, and
     *   when that period lapses the dates are free again while the request
     *   itself stays waiting (spec §22.5) — even with a contract out.
     *
     * **This is the one predicate.** Every surface that asks whether a
     * booking takes its dates — the availability check under the lock, the
     * public calendar and form, the calendar publication — reads it, at the
     * real instant and never at midnight. Availability is computed, never
     * stored (spec §22.2): a hold that lapsed a minute ago frees its dates
     * on this page load, not on the next run of the task.
     */
    public function occupiesTheAsset(\DateTimeImmutable $now): bool
    {
        if (!$this->status->occupiesTheAsset()) {
            return false;
        }

        if ($this->holdsFirmly()) {
            return true;
        }

        return $this->holdIsActive($now);
    }

    /**
     * Since when the dates are no longer held, for a request still
     * waiting on the unit whose automatic hold ran out (#708, IT-01) —
     * null while a hold runs, or once the booking is confirmed or final.
     *
     * Read from the hold itself before the expiry task has run, and from
     * `$holdLapsedAt` after it. An option whose lapse ends the booking is
     * not this — see optionLapsedSince().
     */
    public function holdLapsedSince(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($this->lapseEndsTheBooking() || !$this->stillWaitsOnAHold($now)) {
            return null;
        }

        return $this->holdUntil ?? $this->holdLapsedAt;
    }

    /**
     * Since when a manager's option has run out on a booking the expiry
     * task has not yet expired — null otherwise.
     *
     * The window is short (until the next run of the task), but the page
     * must not tell the manager to « poser une option » on a booking whose
     * option has just lapsed and is about to end it (specifications.md
     * §22.5).
     */
    public function optionLapsedSince(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (!$this->lapseEndsTheBooking() || !$this->stillWaitsOnAHold($now)) {
            return null;
        }

        return $this->holdUntil;
    }

    /**
     * Whether this booking's hold, once lapsed, ends it — the one rule the
     * expiry task and the booking page both read.
     *
     * A manager's option does (specifications.md §22.5): a promise with a
     * deadline, not taken up. Except once the contract has gone out
     * (#708, IT-13): the unit has answered, the renter is signing, and a
     * lapse frees the dates while the booking stays « Contrat envoyé ».
     * The option stays an option — its origin is kept — but the answer
     * already given outweighs its deadline.
     */
    public function lapseEndsTheBooking(): bool
    {
        return $this->holdOrigin?->expiryEndsTheBooking() === true
            && $this->status !== BookingStatus::CONTRACT_SENT;
    }

    private function stillWaitsOnAHold(\DateTimeImmutable $now): bool
    {
        return !$this->holdsFirmly() && !$this->status->isFinal() && !$this->holdIsActive($now);
    }

    /**
     * This booking as an availability `Occupancy`.
     *
     * Deliberately drops everything about who the renter is and why the
     * asset is held: the reference goes in only so a *manager's* calendar
     * can link back and so the booking can be left out of its own
     * conflicts, and every public surface takes the same object with no
     * variant to leak (specifications.md §22.2). Firm or provisional makes
     * no difference here either: once a booking occupies the asset, it
     * stops everybody else the same way (#927, chantier §2.1).
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
