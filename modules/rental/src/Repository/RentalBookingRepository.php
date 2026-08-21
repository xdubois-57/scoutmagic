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
                tracking_token_hash, created_at, updated_at
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
            password_hash($trackingToken, PASSWORD_DEFAULT),
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
     * Verifies a presented tracking token against a booking's stored hash.
     *
     * `password_verify()` against a hash, exactly like the registration
     * module's tracking token: the raw token is never stored, so a database
     * copy does not hand over every renter's booking. Constant-time by
     * construction, which is what stops the token being guessed one
     * character at a time.
     *
     * Returns false for an unknown id rather than distinguishing "no such
     * booking" from "wrong token" — the caller must not be able to probe
     * which references exist.
     */
    public function verifyTrackingToken(int $id, string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT tracking_token_hash FROM rental_bookings WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();

        return $hash !== false && password_verify($token, (string) $hash);
    }

    /**
     * Mints a fresh tracking token, invalidating the previous one.
     *
     * The raw token is returned and stored only as a hash — the caller has
     * one chance to deliver it. Revocability is what makes a capability
     * token acceptable at all: a renter who forwarded their link to the
     * wrong person can be given a new one.
     */
    public function regenerateTrackingToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('UPDATE rental_bookings SET tracking_token_hash = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([password_hash($token, PASSWORD_DEFAULT), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);

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
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RentalBooking
    {
        $snapshot = $row['estimated_price_snapshot'] ?? null;
        $decodedSnapshot = null;
        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            if (is_array($decoded)) {
                $decodedSnapshot = PriceQuote::fromArray($decoded);
            }
        }

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
            conditionsVersion: $row['conditions_version'] !== null ? (string) $row['conditions_version'] : null,
            conditionsHash: $row['conditions_hash'] !== null ? (string) $row['conditions_hash'] : null,
            conditionsAcceptedAt: $row['conditions_accepted_at'] !== null ? new \DateTimeImmutable((string) $row['conditions_accepted_at']) : null,
            privacyVersion: $row['privacy_version'] !== null ? (string) $row['privacy_version'] : null,
            privacyHash: $row['privacy_hash'] !== null ? (string) $row['privacy_hash'] : null,
            privacyAcknowledgedAt: $row['privacy_acknowledged_at'] !== null ? new \DateTimeImmutable((string) $row['privacy_acknowledged_at']) : null
        );
    }
}
