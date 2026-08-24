<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Availability\OccupancyProvider;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalBookingRepository;

/**
 * Creating and moving bookings through their life.
 *
 * Also **the occupancy source** the availability calculator has been waiting
 * for since iteration 3: implementing `OccupancyProvider` here is the whole
 * of the wiring, and every calendar, estimate and validation starts
 * accounting for real bookings without a line of the calculator changing.
 *
 * Never reads `$_POST`/`$_SESSION` (ARCHITECTURE.md §13), and never puts a
 * renter's name, email, phone or comment in a journal entry (SECURITY.md §5)
 * — bookings are journaled by id and reference only.
 */
class RentalBookingService implements OccupancyProvider
{
    /**
     * How long the automatic hold lasts by default (§6.14). Short on
     * purpose: it is told to the renter as "we have 48 hours to reply", and
     * a long automatic hold would silently make an asset look busy for
     * requests nobody has looked at.
     */
    public const DEFAULT_AUTOMATIC_HOLD_HOURS = 48;

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private JournalService $journal
    ) {
    }

    /**
     * Records a public request: allocates its reference, snapshots the price
     * it was quoted, and places the automatic hold.
     *
     * The caller has already validated the range and verified `HumanCheck` —
     * this method is about persisting a decision, not about deciding.
     *
     * @param array{name: string, email: string, phone: ?string, organisation: ?string, purpose: ?string, comment: ?string} $renter
     * @param array{conditions_version: ?string, conditions_text: ?string, privacy_version: ?string, privacy_text: ?string} $acceptances
     * @return array{booking: RentalBooking, tracking_token: string}
     * @throws RentalException
     */
    public function createFromPublicRequest(
        int $assetId,
        string $arrivalDate,
        string $departureDate,
        int $units,
        ?int $estimatedPersons,
        ?int $renterCategoryId,
        array $renter,
        ?PriceQuote $estimatedPrice,
        array $acceptances,
        \DateTimeImmutable $now,
        int $automaticHoldHours = self::DEFAULT_AUTOMATIC_HOLD_HOURS
    ): array {
        $name = trim($renter['name']);
        $email = trim($renter['email']);

        if ($name === '') {
            throw new RentalException('Votre nom est obligatoire.');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RentalException('Une adresse email valide est obligatoire.');
        }

        // Both boxes are required, and the second is deliberately NOT framed
        // as consent: the processing is necessary to the booking, and calling
        // it consent would misrepresent the legal basis (§6.13).
        if (($acceptances['conditions_version'] ?? null) === null) {
            throw new RentalException('Vous devez accepter les conditions de location.');
        }

        if (($acceptances['privacy_version'] ?? null) === null) {
            throw new RentalException('Vous devez confirmer avoir pris connaissance de la politique de confidentialité.');
        }

        /** @return array{id: int, tracking_token: string} */
        $write = fn(string $reference): array => $this->bookingRepository->create(
            $assetId,
            $reference,
            $arrivalDate,
            $departureDate,
            max(1, $units),
            $estimatedPersons,
            $renterCategoryId,
            [
                'name' => $name,
                'email' => $email,
                'phone' => $renter['phone'] ?? null,
                'organisation' => $renter['organisation'] ?? null,
                'purpose' => $renter['purpose'] ?? null,
                'comment' => $renter['comment'] ?? null,
            ],
            $estimatedPrice,
            $automaticHoldHours > 0 ? $now->modify('+' . $automaticHoldHours . ' hours') : null,
            $automaticHoldHours > 0 ? HoldOrigin::AUTOMATIC : null,
            $acceptances['conditions_version'],
            self::hashAcceptedText($acceptances['conditions_text'] ?? ''),
            $acceptances['privacy_version'],
            self::hashAcceptedText($acceptances['privacy_text'] ?? ''),
            $now
        );

        $created = $this->writeWithFreshReference($write, $now);

        $booking = $this->bookingRepository->findById($created['id']);
        if ($booking === null) {
            throw new RentalException("La demande n'a pas pu être enregistrée.");
        }

        // Reference and ids only. A renter's name or email in the journal
        // would make it a second, unprotected copy of the personal data the
        // booking table encrypts (SECURITY.md §5). The tracking token is
        // never logged either (§13 of the conventions).
        $this->journal->log(
            'rental',
            'rental_booking_received',
            'info',
            'Demande de location reçue : ' . $booking->reference,
            ['booking_id' => $booking->id, 'asset_id' => $assetId]
        );

        return ['booking' => $booking, 'tracking_token' => $created['tracking_token']];
    }

    /**
     * Writes the booking, retrying **once** with a freshly claimed
     * reference if the first one collided.
     *
     * `claimNextReferenceSequence()` takes a row lock, so the collision
     * this covers is the narrow one it cannot: a reference already spent
     * by a row the counter does not know about (a restored backup, a
     * hand-inserted booking, a counter reset). One retry, not a loop — a
     * second failure is a broken counter rather than contention, and
     * hammering the table would only make it worse.
     *
     * **No `PDOException` may reach the visitor.** This runs on the public
     * request form, where a driver-level message would be both a 500 and a
     * leak of the schema to an anonymous stranger. Whatever happens, the
     * visitor gets one French sentence and the detail goes to the journal
     * through `$previous`.
     *
     * @param callable(string): array{id: int, tracking_token: string} $write
     * @return array{id: int, tracking_token: string}
     * @throws RentalException
     */
    private function writeWithFreshReference(callable $write, \DateTimeImmutable $now): array
    {
        try {
            return $write($this->allocateReference($now));
        } catch (\PDOException $first) {
            if (!self::isDuplicateKey($first)) {
                throw new RentalException(self::SUBMISSION_FAILED, 0, $first);
            }
        }

        try {
            return $write($this->allocateReference($now));
        } catch (\PDOException $second) {
            throw new RentalException(self::SUBMISSION_FAILED, 0, $second);
        }
    }

    /**
     * What the visitor is told when the write cannot be completed. One
     * sentence, French, naming nothing internal — AGENTS.md § Exception
     * messages that reach a visitor.
     */
    private const SUBMISSION_FAILED =
        "Votre demande n'a pas pu être enregistrée. Réessayez dans un instant ; "
        . "si le problème persiste, contactez l'unité.";

    /**
     * Whether a driver error is a unique-constraint violation.
     *
     * SQLSTATE 23000 covers it on MySQL/MariaDB and on SQLite alike, which
     * is what lets the retry be exercised by the test database rather than
     * only in production.
     */
    private static function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->getCode() === '23000' || $e->getCode() === 23000)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /**
     * Claims the next `LOC-YYYY-NNNN` for the year of $now.
     *
     * The number comes from a forward-only counter, never from a MAX() over
     * the surviving bookings: a deleted or purged booking must not free its
     * number, or two rentals end up quoting the same reference to two
     * renters. The year is when the request was *made*, so a reference stays
     * stable even for a stay in a later year.
     *
     * The read takes a row lock, so two concurrent submissions queue rather
     * than race; the `UNIQUE` constraint on the column remains the backstop
     * against a number spent by a row the counter never saw, and
     * `writeWithFreshReference()` is what retries on it.
     */
    public function allocateReference(\DateTimeImmutable $now): string
    {
        $year = (int) $now->format('Y');

        return sprintf('LOC-%04d-%04d', $year, $this->bookingRepository->claimNextReferenceSequence($year));
    }

    /**
     * A hash of the exact text a renter accepted (§6.13).
     *
     * Stored alongside the version so what was agreed can be proven later,
     * even after the wording is reworked — a version string alone would be
     * worthless the first time somebody edits the conditions without
     * bumping it. Never the text itself: the text is not personal data, but
     * copying it onto every booking would bloat the table for nothing.
     */
    public static function hashAcceptedText(string $text): string
    {
        return hash('sha256', trim($text));
    }

    /**
     * Whether the text shown today still matches what this booking's renter
     * accepted. Used by the manager's view to flag a booking whose
     * conditions have since changed.
     */
    public function acceptedTextStillMatches(?string $storedHash, string $currentText): bool
    {
        return $storedHash !== null && hash_equals($storedHash, self::hashAcceptedText($currentText));
    }

    // ── OccupancyProvider ───────────────────────────────────────────────

    /**
     * Every booking holding $assetId over the window, as availability
     * occupancies.
     *
     * One bounded query, and the mapping deliberately discards everything
     * about the renter — a booking, a hold and (later) a manual block all
     * become the same shape, which is what makes them indistinguishable to
     * every public surface (§6.7/§6.14).
     *
     * The filter on `occupiesTheAsset($now)` is what makes a lapsed hold
     * release its dates on this very request rather than on the next run of
     * the expiry task. The SQL prefilter cannot do it alone: the repository
     * query is also what the manager's calendar lists, and a manager must
     * still see a request whose hold has run out.
     *
     * @return Occupancy[]
     */
    public function findOccupancies(
        int $assetId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now
    ): array {
        $occupying = array_filter(
            $this->bookingRepository->findOccupyingBetween(
                $assetId,
                $from->format('Y-m-d'),
                $to->format('Y-m-d')
            ),
            static fn(RentalBooking $booking) => $booking->occupiesTheAsset($now)
        );

        return array_values(array_map(
            static fn(RentalBooking $booking) => $booking->toOccupancy(),
            $occupying
        ));
    }

    // ── Tracking ────────────────────────────────────────────────────────

    /**
     * The booking a tracking token unlocks, or null.
     *
     * Verifies possession before returning anything, and returns null for
     * both "no such booking" and "wrong token" so the caller cannot probe
     * which references exist (§13 of the conventions).
     */
    public function findByTrackingToken(int $bookingId, string $token): ?RentalBooking
    {
        if ($token === '' || !$this->bookingRepository->verifyTrackingToken($bookingId, $token)) {
            return null;
        }

        return $this->bookingRepository->findById($bookingId);
    }

    /**
     * A booking's tracking token, for putting into an email to the renter
     * and nowhere else.
     *
     * Null when there is none to read. The caller decides what that means:
     * a decision email skips its link rather than failing the decision —
     * a booking that got confirmed but whose email carried no link is a
     * problem; a confirmation that did not happen because of it is worse.
     */
    public function trackingTokenFor(int $bookingId): ?string
    {
        return $this->bookingRepository->trackingTokenOf($bookingId);
    }

    /**
     * Mints a new tracking token, invalidating the old one. The previous
     * link stops working immediately.
     */
    public function regenerateTrackingToken(int $bookingId): string
    {
        $token = $this->bookingRepository->regenerateTrackingToken($bookingId);

        $this->journal->log(
            'rental',
            'rental_tracking_token_regenerated',
            'security',
            'Lien de suivi régénéré pour une réservation',
            ['booking_id' => $bookingId]
        );

        return $token;
    }

    // ── Hold expiry (§6.14) ─────────────────────────────────────────────

    /**
     * Processes every lapsed temporary hold.
     *
     * The two origins mean different things, which is the whole reason the
     * origin is stored:
     *
     * - `automatic` — nobody promised anything, so the request just goes
     *   back to waiting and the dates are released. Its status is untouched.
     * - `manager` — an option was promised with a deadline and not taken up,
     *   so the booking becomes **expired** and releases the dates.
     *
     * A booking already confirmed keeps its dates whatever its hold said: it
     * is held by its status now, and clearing the hold is just tidying up.
     *
     * @return array{released: int, expired: int}
     */
    public function expireLapsedHolds(\DateTimeImmutable $now): array
    {
        $released = 0;
        $expired = 0;

        foreach ($this->bookingRepository->findWithLapsedHold($now) as $booking) {
            if ($booking->status->isFinal() || $booking->status === BookingStatus::CONFIRMED) {
                // Nothing to decide: just drop the stale deadline.
                $this->bookingRepository->clearHold($booking->id);
                continue;
            }

            if ($booking->holdOrigin?->expiryEndsTheBooking() === true) {
                $this->bookingRepository->setStatus($booking->id, BookingStatus::EXPIRED, $now);
                $this->bookingRepository->clearHold($booking->id);
                $expired++;

                $this->journal->log(
                    'rental',
                    'rental_booking_expired',
                    'info',
                    'Option de location expirée : ' . $booking->reference,
                    ['booking_id' => $booking->id]
                );
                continue;
            }

            $this->bookingRepository->clearHold($booking->id);
            $released++;
        }

        return ['released' => $released, 'expired' => $expired];
    }
}
