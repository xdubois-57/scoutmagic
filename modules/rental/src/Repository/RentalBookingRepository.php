<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\PriceQuote;

/**
 * The only place a renter's identity is ever encrypted or decrypted
 * (SECURITY.md §5). No Service, Controller or journal entry sees either the
 * ciphertext or the plaintext by accident: they receive a hydrated
 * `Booking\RentalBooking` and are responsible for keeping its fields out of
 * logs.
 *
 * A booking is about a **non-member** — someone with no account, no Desk
 * record and no other trace in this installation — which is why every
 * identity column is a BLOB rather than a plain string.
 *
 * Every timestamp is computed in PHP and bound as a parameter, never MySQL's
 * `NOW()`, so this repository and its tests run unmodified against the
 * SQLite test database.
 */
class RentalBookingRepository
{
    private const CTX_NAME = 'rental_bookings.renter_name';
    private const CTX_EMAIL = 'rental_bookings.renter_email';
    private const CTX_PHONE = 'rental_bookings.renter_phone';
    private const CTX_ORGANISATION = 'rental_bookings.renter_organisation';
    private const CTX_PURPOSE = 'rental_bookings.purpose';
    private const CTX_COMMENT = 'rental_bookings.renter_comment';
    private const CTX_BILLING_NAME = 'rental_bookings.billing_name';
    private const CTX_BILLING_ADDRESS = 'rental_bookings.billing_address';
    private const CTX_BILLING_VAT = 'rental_bookings.billing_vat_number';
    private const CTX_BILLING_ENTERPRISE = 'rental_bookings.billing_enterprise_number';
    private const CTX_BILLING_EMAIL = 'rental_bookings.billing_email';
    private const CTX_BILLING_REFERENCE = 'rental_bookings.billing_reference';
    private const CTX_TRACKING_TOKEN = 'rental_bookings.tracking_token';

    /**
     * Blind-index purpose. Shared with nothing else: an index computed under
     * one purpose must never collide with the same address indexed for
     * another feature, or the two become linkable.
     */
    public const BLIND_INDEX_PURPOSE = 'rental_renter_email';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Normalised form the blind index is computed over. Lower-cased and
     * trimmed so "Jean@Example.ORG " and "jean@example.org" index alike —
     * otherwise a renter's own tracking page would not find their booking
     * because they capitalised their address differently when logging in.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Creates a booking and returns its id together with the **raw** tracking
     * token, which exists in memory for exactly this call: only its hash is
     * stored, so this is the one and only chance to put it in the email
     * (§13 of the conventions).
     *
     * @param array{name: string, email: string, phone: ?string, organisation: ?string, purpose: ?string, comment: ?string} $renter
     * @return array{id: int, tracking_token: string}
     */
    public function create(
        int $assetId,
        string $reference,
        string $arrivalDate,
        string $departureDate,
        int $units,
        ?int $estimatedPersons,
        ?int $renterCategoryId,
        array $renter,
        ?PriceQuote $estimatedPrice,
        ?\DateTimeImmutable $holdUntil,
        ?HoldOrigin $holdOrigin,
        ?string $conditionsVersion,
        ?string $conditionsHash,
        ?string $privacyVersion,
        ?string $privacyHash,
        \DateTimeImmutable $now
    ): array {
        $trackingToken = bin2hex(random_bytes(32));
        $timestamp = $now->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_bookings (
                asset_id, reference, arrival_date, departure_date, units,
                estimated_persons, renter_category_id,
                renter_name_encrypted, renter_email_encrypted, renter_email_blind_index,
                renter_phone_encrypted, renter_organisation_encrypted,
                purpose_encrypted, renter_comment_encrypted,
                status, received_at, hold_until, hold_origin,
                estimated_price_snapshot, estimated_total_cents,
                conditions_version, conditions_hash, conditions_accepted_at,
                privacy_version, privacy_hash, privacy_acknowledged_at,
                tracking_token_encrypted, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $assetId,
            $reference,
            $arrivalDate,
            $departureDate,
            $units,
            $estimatedPersons,
            $renterCategoryId,
            $this->encryption->encrypt($renter['name'], self::CTX_NAME),
            $this->encryption->encrypt($renter['email'], self::CTX_EMAIL),
            $this->encryption->blindIndex(self::normalizeEmail($renter['email']), self::BLIND_INDEX_PURPOSE),
            $this->encryptOptional($renter['phone'] ?? null, self::CTX_PHONE),
            $this->encryptOptional($renter['organisation'] ?? null, self::CTX_ORGANISATION),
            $this->encryptOptional($renter['purpose'] ?? null, self::CTX_PURPOSE),
            $this->encryptOptional($renter['comment'] ?? null, self::CTX_COMMENT),
            BookingStatus::RECEIVED->value,
            $timestamp,
            $holdUntil?->format('Y-m-d H:i:s'),
            $holdOrigin?->value,
            $estimatedPrice !== null ? json_encode($estimatedPrice->toArray()) : null,
            $estimatedPrice?->totalCents,
            $conditionsVersion,
            $conditionsHash,
            $conditionsVersion !== null ? $timestamp : null,
            $privacyVersion,
            $privacyHash,
            $privacyVersion !== null ? $timestamp : null,
            $this->encryption->encrypt($trackingToken, self::CTX_TRACKING_TOKEN),
            $timestamp,
            $timestamp,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'tracking_token' => $trackingToken];
    }

    public function findById(int $id): ?RentalBooking
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByReference(string $reference): ?RentalBooking
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_bookings WHERE reference = ?');
        $stmt->execute([$reference]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Verifies a presented tracking token against a booking's stored one.
     *
     * `hash_equals()` against the decrypted token — constant-time, which is
     * what stops the token being guessed one character at a time. See the
     * note in schema.sql for why this is encrypted rather than hashed: a
     * hash can answer "is this the token?" and nothing else, and every
     * email a manager's decision sends needs the answer to "what is this
     * booking's link?".
     *
     * Returns false for an unknown id rather than distinguishing "no such
     * booking" from "wrong token" — the caller must not be able to probe
     * which references exist. A token that fails to decrypt (a key rotated
     * without re-encrypting, a corrupted row) is the same false: the renter
     * gets a fresh link from a manager, which is what regeneration is for.
     */
    public function verifyTrackingToken(int $id, string $token): bool
    {
        $stored = $this->trackingTokenOf($id);

        return $stored !== null && $token !== '' && hash_equals($stored, $token);
    }

    /**
     * A booking's tracking token in the clear, or null if there is none to
     * read.
     *
     * The one call that hands a credential back out, and it exists for one
     * reason: an email to a renter is worth nothing without their link, and
     * the renter has no account to log into instead. Callers must treat
     * what comes back the way `RentalBookingMailService` does — into the
     * URL of a message addressed to the renter, never into a journal entry,
     * a flash message, a template variable a manager can see, or a log.
     */
    public function trackingTokenOf(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT tracking_token_encrypted FROM rental_bookings WHERE id = ?');
        $stmt->execute([$id]);
        $stored = $stmt->fetchColumn();

        if ($stored === false || $stored === null || $stored === '') {
            return null;
        }

        try {
            return $this->encryption->decrypt((string) $stored, self::CTX_TRACKING_TOKEN);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mints a fresh tracking token, invalidating the previous one.
     *
     * Revocability is what makes a capability token acceptable at all: a
     * renter who forwarded their link to the wrong person can be given a
     * new one, and the old link stops working the moment this returns.
     */
    public function regenerateTrackingToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('UPDATE rental_bookings SET tracking_token_encrypted = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([
            $this->encryption->encrypt($token, self::CTX_TRACKING_TOKEN),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]);

        return $token;
    }

    /**
     * Bookings holding an asset over a window — the availability query.
     *
     * One bounded query for the whole window (see `OccupancyProvider`), and
     * it deliberately returns bookings whose status *occupies* the asset,
     * refused/cancelled/expired ones included in neither the result nor the
     * calendar.
     *
     * @return RentalBooking[]
     */
    public function findOccupyingBetween(int $assetId, string $from, string $to): array
    {
        $occupying = array_values(array_filter(
            BookingStatus::cases(),
            static fn(BookingStatus $status) => $status->occupiesTheAsset()
        ));
        $placeholders = implode(',', array_fill(0, count($occupying), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT * FROM rental_bookings
             WHERE asset_id = ?
               AND status IN ({$placeholders})
               AND arrival_date <= ?
               AND departure_date >= ?
             ORDER BY arrival_date ASC"
        );
        $stmt->execute(array_merge(
            [$assetId],
            array_map(static fn(BookingStatus $s) => $s->value, $occupying),
            [$to, $from]
        ));

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every booking of **several** assets overlapping a window, in ONE
     * query.
     *
     * The calendar provider's entry point (§6.31): a month view or an ICS
     * feed asks once for the whole range across every publishing asset.
     * One query per asset would turn a unit with six lettable things into
     * six queries per calendar render, and the ICS feed into far more.
     *
     * Cancelled, refused and expired bookings are **included on purpose**:
     * a subscriber who already has the event has to be told it is off
     * (§6.32), and the caller decides what to do with it. Filtering them
     * out here would make that impossible.
     *
     * @param int[] $assetIds
     * @return RentalBooking[]
     */
    public function findForAssetsBetween(array $assetIds, string $from, string $to): array
    {
        if ($assetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM rental_bookings
             WHERE asset_id IN ({$placeholders})
               AND arrival_date <= ?
               AND departure_date >= ?
             ORDER BY arrival_date ASC, id ASC"
        );
        $stmt->execute([...array_values($assetIds), $to, $from]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $assetIds
     * @return RentalBooking[]
     */
    public function findAllForAssets(array $assetIds, ?BookingStatus $status = null): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        if ($assetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $sql = "SELECT * FROM rental_bookings WHERE asset_id IN ({$placeholders})";
        $params = $assetIds;

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status->value;
        }

        $sql .= ' ORDER BY arrival_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Bookings whose temporary hold has lapsed — the expiry sweep's input.
     *
     * @return RentalBooking[]
     */
    public function findWithLapsedHold(\DateTimeImmutable $now, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_bookings
             WHERE hold_until IS NOT NULL AND hold_until <= ?
             ORDER BY hold_until ASC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$now->format('Y-m-d H:i:s')]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Clears the temporary hold without touching the status — what an
     * `automatic` hold lapsing means: the request simply goes back to
     * waiting, because nobody ever promised anything (§6.14).
     */
    public function clearHold(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET hold_until = NULL, hold_origin = NULL, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function setStatus(int $id, BookingStatus $status, \DateTimeImmutable $now): void
    {
        $timestamp = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET status = ?, final_at = ?, updated_at = ? WHERE id = ?'
        );
        // final_at is set only when entering a final state, and starts the
        // retention clock — never `received_at`, or a request that sat open
        // for a year would be purged the moment it closes.
        $stmt->execute([$status->value, $status->isFinal() ? $timestamp : null, $timestamp, $id]);
    }

    public function setHold(int $id, ?\DateTimeImmutable $until, ?HoldOrigin $origin): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET hold_until = ?, hold_origin = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $until?->format('Y-m-d H:i:s'),
            $origin?->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Moves the status, but **only from an expected one**.
     *
     * The `status = ?` in the WHERE clause is the whole point: it is the
     * compare-and-set that stops two managers on two screens both
     * confirming the same booking, and stops a manager confirming one that
     * was cancelled while their page sat open. The second call matches no
     * row, returns false, and the caller says so — instead of the later
     * click silently winning.
     *
     * `setStatus()` remains for the cases with genuinely nothing to race
     * against (the expiry task, which already selected on the deadline).
     */
    public function compareAndSetStatus(
        int $id,
        BookingStatus $expected,
        BookingStatus $status,
        \DateTimeImmutable $now
    ): bool {
        $timestamp = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET status = ?, final_at = ?, updated_at = ?
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            $status->value,
            $status->isFinal() ? $timestamp : null,
            $timestamp,
            $id,
            $expected->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Replaces the agreed price — the manager's working copy of the quote.
     *
     * Never touches `estimated_price_snapshot`: that one is frozen as the
     * record of what the visitor was shown at submission, and the two
     * columns exist precisely so a negotiated price cannot rewrite history
     * (§6.11).
     */
    public function setAgreedPrice(int $id, ?PriceQuote $quote): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET agreed_price_snapshot = ?, agreed_total_cents = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $quote !== null ? json_encode($quote->toArray(), JSON_UNESCAPED_UNICODE) : null,
            $quote?->totalCents,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Moves the stay: dates, stock units and head count.
     *
     * Availability is the caller's business, not this method's — a
     * repository that re-checked would be deciding, and the check has to
     * happen inside the same transaction as the write anyway.
     */
    public function setStay(
        int $id,
        string $arrivalDate,
        string $departureDate,
        int $units,
        ?int $estimatedPersons
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings
             SET arrival_date = ?, departure_date = ?, units = ?, estimated_persons = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $arrivalDate,
            $departureDate,
            max(1, $units),
            $estimatedPersons,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Every booking on $assetId that holds its dates over the window,
     * **excluding one** — the "is this range still free if I move THIS
     * booking into it?" question, which must not count the booking against
     * itself.
     *
     * @return RentalBooking[]
     */
    public function findOccupyingBetweenExcluding(int $assetId, string $from, string $to, int $excludedId): array
    {
        return array_values(array_filter(
            $this->findOccupyingBetween($assetId, $from, $to),
            static fn(RentalBooking $booking) => $booking->id !== $excludedId
        ));
    }

    /**
     * Runs $work with the booking rows for $assetId locked, so an
     * availability check and the write that depends on it cannot be
     * interleaved with another confirmation.
     *
     * `FOR UPDATE` is issued only on MySQL/MariaDB: SQLite has no row locks
     * and does not need them — its transactions are whole-database — so the
     * test database gets the same guarantee through a different mechanism
     * rather than a weaker one.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function withAssetLocked(int $assetId, callable $work): mixed
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lock = $this->pdo->prepare('SELECT id FROM rental_bookings WHERE asset_id = ? FOR UPDATE');
                $lock->execute([$assetId]);
                $lock->fetchAll();
            }

            $result = $work();

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The renter's billing identity (§6.27), decrypted.
     *
     * Its own read rather than part of `hydrate()`: these seven columns are
     * touched by the invoice screen alone, and decrypting them on every
     * booking load — including the availability path, which reads bookings
     * by the hundred — would cost the whole module for one screen's
     * benefit.
     *
     * @return array{name: ?string, address: ?string, country: ?string, vat_number: ?string, enterprise_number: ?string, email: ?string, reference: ?string}
     */
    public function findBillingIdentity(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT billing_name_encrypted, billing_address_encrypted, billing_country,
                    billing_vat_number_encrypted, billing_enterprise_number_encrypted,
                    billing_email_encrypted, billing_reference_encrypted
             FROM rental_bookings WHERE id = ?'
        );
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'name' => null, 'address' => null, 'country' => null, 'vat_number' => null,
                'enterprise_number' => null, 'email' => null, 'reference' => null,
            ];
        }

        return [
            'name' => $this->decryptOptional($row['billing_name_encrypted'] ?? null, self::CTX_BILLING_NAME),
            'address' => $this->decryptOptional($row['billing_address_encrypted'] ?? null, self::CTX_BILLING_ADDRESS),
            'country' => $row['billing_country'] !== null ? (string) $row['billing_country'] : null,
            'vat_number' => $this->decryptOptional($row['billing_vat_number_encrypted'] ?? null, self::CTX_BILLING_VAT),
            'enterprise_number' => $this->decryptOptional(
                $row['billing_enterprise_number_encrypted'] ?? null,
                self::CTX_BILLING_ENTERPRISE
            ),
            'email' => $this->decryptOptional($row['billing_email_encrypted'] ?? null, self::CTX_BILLING_EMAIL),
            'reference' => $this->decryptOptional(
                $row['billing_reference_encrypted'] ?? null,
                self::CTX_BILLING_REFERENCE
            ),
        ];
    }

    /**
     * @param array{name?: ?string, address?: ?string, country?: ?string, vat_number?: ?string, enterprise_number?: ?string, email?: ?string, reference?: ?string} $identity
     */
    public function saveBillingIdentity(int $bookingId, array $identity): void
    {
        $country = isset($identity['country']) ? strtoupper(trim((string) $identity['country'])) : '';

        $stmt = $this->pdo->prepare(
            'UPDATE rental_bookings SET
                billing_name_encrypted = ?, billing_address_encrypted = ?, billing_country = ?,
                billing_vat_number_encrypted = ?, billing_enterprise_number_encrypted = ?,
                billing_email_encrypted = ?, billing_reference_encrypted = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $this->encryptOptional($identity['name'] ?? null, self::CTX_BILLING_NAME),
            $this->encryptOptional($identity['address'] ?? null, self::CTX_BILLING_ADDRESS),
            // Two letters or nothing: a country field holding "Belgique" in
            // one row and "BE" in another is what makes a later e-invoice
            // export a guessing game.
            preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            $this->encryptOptional($identity['vat_number'] ?? null, self::CTX_BILLING_VAT),
            $this->encryptOptional($identity['enterprise_number'] ?? null, self::CTX_BILLING_ENTERPRISE),
            $this->encryptOptional($identity['email'] ?? null, self::CTX_BILLING_EMAIL),
            $this->encryptOptional($identity['reference'] ?? null, self::CTX_BILLING_REFERENCE),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $bookingId,
        ]);
    }

    /**
     * Claims and returns the next sequence number for a year.
     *
     * Deliberately a **counter that only moves forward**, not a MAX() over
     * the surviving bookings: a booking deleted by mistake — or, once the
     * retention policy lands, purged on schedule — would otherwise free its
     * number, and two different rentals would end up quoting the same
     * reference to two different renters. A number is spent the moment it is
     * handed out and never comes back.
     *
     * Written as select-then-write inside a transaction so it behaves the
     * same on MySQL and on the SQLite test database. Two concurrent
     * submissions can still race here; the `UNIQUE` constraint on
     * `rental_bookings.reference` is the real backstop, and the caller
     * retries.
     */
    public function claimNextReferenceSequence(int $year): int
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare('SELECT last_sequence FROM rental_reference_sequences WHERE year = ?');
            $stmt->execute([$year]);
            $current = $stmt->fetchColumn();

            $next = $current === false ? 1 : ((int) $current) + 1;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            if ($current === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rental_reference_sequences (year, last_sequence, updated_at) VALUES (?, ?, ?)'
                );
                $insert->execute([$year, $next, $now]);
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE rental_reference_sequences SET last_sequence = ?, updated_at = ? WHERE year = ?'
                );
                $update->execute([$next, $now, $year]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $next;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Bookings whose renter used this email address — the "your bookings"
     * lookup for an identified visitor, resolved through the blind index so
     * nothing has to be decrypted in a loop.
     *
     * @return RentalBooking[]
     */
    public function findByRenterEmail(string $email): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_bookings WHERE renter_email_blind_index = ? ORDER BY arrival_date DESC');
        $stmt->execute([$this->encryption->blindIndex(self::normalizeEmail($email), self::BLIND_INDEX_PURPOSE)]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every booking whose stay ended on or before $date — the purge's
     * question (§6.35).
     *
     * Every status, deliberately: a refused request holds the same
     * enquirer's name and address as a completed stay, and there is no
     * reason to keep one longer than the other once the retention is up.
     *
     * @return RentalBooking[]
     */
    public function findEndedOnOrBefore(string $date): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_bookings WHERE departure_date <= ? ORDER BY id ASC'
        );
        $stmt->execute([$date]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_bookings WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function encryptOptional(?string $value, string $context): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $this->encryption->encrypt($value, $context) : null;
    }

    private function decryptOptional(mixed $value, string $context): ?string
    {
        return $value !== null && $value !== ''
            ? $this->encryption->decrypt((string) $value, $context)
            : null;
    }

    /**
     * A stored `PriceQuote` snapshot, or null.
     *
     * A snapshot that fails to decode comes back as null rather than
     * throwing: a booking whose price JSON was corrupted must still be
     * readable — losing the breakdown is recoverable, losing access to the
     * renter's dates and identity is not.
     */
    private static function decodeSnapshot(mixed $raw): ?PriceQuote
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? PriceQuote::fromArray($decoded) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RentalBooking
    {
        $decodedSnapshot = self::decodeSnapshot($row['estimated_price_snapshot'] ?? null);
        $decodedAgreed = self::decodeSnapshot($row['agreed_price_snapshot'] ?? null);

        return new RentalBooking(
            id: (int) $row['id'],
            assetId: (int) $row['asset_id'],
            reference: (string) $row['reference'],
            arrivalDate: (string) $row['arrival_date'],
            departureDate: (string) $row['departure_date'],
            units: (int) $row['units'],
            estimatedPersons: $row['estimated_persons'] !== null ? (int) $row['estimated_persons'] : null,
            renterCategoryId: $row['renter_category_id'] !== null ? (int) $row['renter_category_id'] : null,
            renterName: $this->encryption->decrypt((string) $row['renter_name_encrypted'], self::CTX_NAME),
            renterEmail: $this->encryption->decrypt((string) $row['renter_email_encrypted'], self::CTX_EMAIL),
            renterPhone: $this->decryptOptional($row['renter_phone_encrypted'] ?? null, self::CTX_PHONE),
            renterOrganisation: $this->decryptOptional($row['renter_organisation_encrypted'] ?? null, self::CTX_ORGANISATION),
            purpose: $this->decryptOptional($row['purpose_encrypted'] ?? null, self::CTX_PURPOSE),
            renterComment: $this->decryptOptional($row['renter_comment_encrypted'] ?? null, self::CTX_COMMENT),
            status: BookingStatus::tryFrom((string) $row['status']) ?? BookingStatus::RECEIVED,
            receivedAt: new \DateTimeImmutable((string) $row['received_at']),
            finalAt: $row['final_at'] !== null ? new \DateTimeImmutable((string) $row['final_at']) : null,
            holdUntil: $row['hold_until'] !== null ? new \DateTimeImmutable((string) $row['hold_until']) : null,
            holdOrigin: $row['hold_origin'] !== null ? HoldOrigin::tryFrom((string) $row['hold_origin']) : null,
            estimatedPrice: $decodedSnapshot,
            estimatedTotalCents: $row['estimated_total_cents'] !== null ? (int) $row['estimated_total_cents'] : null,
            agreedPrice: $decodedAgreed,
            agreedTotalCents: isset($row['agreed_total_cents']) ? (int) $row['agreed_total_cents'] : null,
            conditionsVersion: $row['conditions_version'] !== null ? (string) $row['conditions_version'] : null,
            conditionsHash: $row['conditions_hash'] !== null ? (string) $row['conditions_hash'] : null,
            conditionsAcceptedAt: $row['conditions_accepted_at'] !== null ? new \DateTimeImmutable((string) $row['conditions_accepted_at']) : null,
            privacyVersion: $row['privacy_version'] !== null ? (string) $row['privacy_version'] : null,
            privacyHash: $row['privacy_hash'] !== null ? (string) $row['privacy_hash'] : null,
            privacyAcknowledgedAt: $row['privacy_acknowledged_at'] !== null ? new \DateTimeImmutable((string) $row['privacy_acknowledged_at']) : null
        );
    }
}
