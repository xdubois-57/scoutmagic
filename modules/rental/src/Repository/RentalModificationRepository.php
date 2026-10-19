<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Service\DateInput;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Availability\OccupancyProvider;
use Modules\Rental\Booking\BookingModification;
use Modules\Rental\Booking\ModificationStatus;
use Modules\Rental\Booking\ModificationTerms;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\PriceQuote;

/**
 * A booking's modifications (#927, chantier §2.3), and the third
 * `OccupancyProvider` after the bookings and the manual blocks: a project
 * in progress holds its new dates, without a deadline, under the booking's
 * reference.
 *
 * **One project in progress per booking, and the database says so.**
 * `active_booking_id` carries the booking's id while a row is 'draft' or
 * 'sent' and NULL otherwise, under a UNIQUE key — so every statement here
 * that moves `status` writes it in the same UPDATE, and nothing else writes
 * it at all. The service checks first, under the asset's lock, for a
 * sentence in French; the key is what holds when two managers press at the
 * same instant.
 *
 * Every transition is a compare-and-set on the status it leaves, so a
 * double click, or two managers, apply or cancel a project once.
 *
 * Every timestamp is computed in PHP, never MySQL's `NOW()`, so this runs
 * unmodified against the SQLite test database.
 */
class RentalModificationRepository implements OccupancyProvider
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Opens a project in preparation for a booking.
     *
     * @throws \PDOException on the unique key when the booking already has
     *   a project in progress — the caller asked first, under the lock
     */
    public function createDraft(
        RentalBooking $booking,
        ModificationTerms $terms,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): int {
        return $this->insert($booking, $terms, ModificationStatus::DRAFT, $actorMemberId, $now);
    }

    /**
     * Records a change applied straight to the booking, before any contract
     * went out (chantier §2.3) — already closed, for the history. To be
     * called with the booking as it was BEFORE the change.
     */
    public function recordDirect(
        RentalBooking $before,
        ModificationTerms $terms,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): int {
        return $this->insert($before, $terms, ModificationStatus::APPLIED_DIRECTLY, $actorMemberId, $now);
    }

    /**
     * Rewrites a project's terms while it is in preparation. Returns
     * whether the project is still in preparation — false when its contract
     * went out, or it ended, in the meantime: nothing was written then.
     *
     * Decided by reading the row back, never by `rowCount()`: on MySQL an
     * UPDATE that writes the same values reports zero rows changed, and a
     * double click on « Enregistrer » would read as a refusal.
     */
    public function updateDraft(int $id, ModificationTerms $terms, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rental_booking_modifications
             SET arrival_date = ?, departure_date = ?, units = ?, persons = ?, price_snapshot = ?,
                 total_cents = ?, discount_label = ?, discount_cents = ?, updated_at = ?
             WHERE id = ? AND status = 'draft'"
        );
        $stmt->execute([
            ...$this->termsValues($terms),
            $now->format('Y-m-d H:i:s'),
            $id,
        ]);

        return $this->findById($id)?->status === ModificationStatus::DRAFT;
    }

    /**
     * The replacement contract went out: the terms are frozen from here.
     * Returns whether this call is the one that sent it.
     */
    public function markSent(int $id, int $contractDocumentId, ?int $actorMemberId, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rental_booking_modifications
             SET status = 'sent', contract_document_id = ?, sent_at = ?, sent_by_member_id = ?, updated_at = ?
             WHERE id = ? AND status = 'draft'"
        );
        $at = $now->format('Y-m-d H:i:s');
        $stmt->execute([$contractDocumentId, $at, $actorMemberId, $at, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Countersigned: the project's terms are the booking's now. Returns
     * whether this call is the one that applied it — the countersignature
     * is idempotent, two clicks apply it once (chantier §4).
     */
    public function markApplied(int $id, ?int $actorMemberId, \DateTimeImmutable $now): bool
    {
        return $this->close($id, ModificationStatus::APPLIED, ["'sent'"], $actorMemberId, $now);
    }

    /**
     * Abandons a project in progress. Returns whether this call cancelled it.
     */
    public function markCancelled(int $id, ?int $actorMemberId, \DateTimeImmutable $now): bool
    {
        return $this->close($id, ModificationStatus::CANCELLED, ["'draft'", "'sent'"], $actorMemberId, $now);
    }

    /**
     * Cancels the booking's project in progress, if it has one, together
     * with its documents — what the booking's own end does (chantier §2.3:
     * closed, cancelled, refused or expired, its project goes with it, in
     * the same transaction). Returns the project cancelled, or null.
     */
    public function cancelActiveFor(int $bookingId, ?int $actorMemberId, \DateTimeImmutable $now): ?BookingModification
    {
        $active = $this->findActiveFor($bookingId);
        if ($active === null || !$this->markCancelled($active->id, $actorMemberId, $now)) {
            return null;
        }

        $this->pdo->prepare(
            'UPDATE rental_documents SET superseded_at = ? WHERE modification_id = ? AND superseded_at IS NULL'
        )->execute([$now->format('Y-m-d H:i:s'), $active->id]);

        return $active;
    }

    public function findById(int $id): ?BookingModification
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_booking_modifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** The booking's project in progress, or null. */
    public function findActiveFor(int $bookingId): ?BookingModification
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_booking_modifications WHERE active_booking_id = ?');
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every modification of a booking, the newest first — its history.
     *
     * @return list<BookingModification>
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_booking_modifications WHERE booking_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$bookingId]);

        return array_values(array_map(
            fn(array $row): BookingModification => $this->hydrate($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        ));
    }

    /**
     * The projects in progress of several bookings, in one query, keyed by
     * booking id — for a list of bookings that must not ask once each.
     *
     * @param int[] $bookingIds
     * @return array<int, BookingModification>
     */
    public function findActiveForBookings(array $bookingIds): array
    {
        $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
        if ($bookingIds === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_booking_modifications WHERE active_booking_id IN ('
            . implode(',', array_fill(0, count($bookingIds), '?')) . ')'
        );
        $stmt->execute($bookingIds);

        $active = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $modification = $this->hydrate($row);
            $active[$modification->bookingId] = $modification;
        }

        return $active;
    }

    // ── OccupancyProvider ───────────────────────────────────────────────

    /**
     * The new dates of every project in progress on the asset over the
     * window — when they differ from the booking's — each carrying its
     * booking's reference, so the booking and its project are left out of
     * their own conflicts and of nobody else's (chantier §2.1). No deadline:
     * a project holds them until it is applied or cancelled, so `$now` is
     * accepted and unused.
     *
     * @return Occupancy[]
     */
    public function findOccupancies(
        int $assetId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, b.reference AS booking_reference
             FROM rental_booking_modifications m
             JOIN rental_bookings b ON b.id = m.booking_id
             WHERE b.asset_id = ? AND m.active_booking_id IS NOT NULL
               AND m.arrival_date <= ? AND m.departure_date >= ?'
        );
        $stmt->execute([$assetId, $to->format('Y-m-d'), $from->format('Y-m-d')]);

        $occupancies = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $occupancy = $this->hydrate($row)->toOccupancy((string) $row['booking_reference']);
            if ($occupancy !== null) {
                $occupancies[] = $occupancy;
            }
        }

        return $occupancies;
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function insert(
        RentalBooking $booking,
        ModificationTerms $terms,
        ModificationStatus $status,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): int {
        $at = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_booking_modifications
                (booking_id, status, active_booking_id,
                 arrival_date, departure_date, units, persons, price_snapshot, total_cents,
                 discount_label, discount_cents,
                 previous_arrival_date, previous_departure_date, previous_units, previous_persons,
                 previous_total_cents, created_by_member_id, created_at, updated_at, closed_at,
                 closed_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $booking->id,
            $status->value,
            $status->isActive() ? $booking->id : null,
            ...$this->termsValues($terms),
            $booking->arrivalDate,
            $booking->departureDate,
            max(1, $booking->units),
            $booking->estimatedPersons,
            $booking->effectiveTotalCents(),
            $actorMemberId,
            $at,
            $at,
            $status->isActive() ? null : $at,
            $status->isActive() ? null : $actorMemberId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $fromStatuses quoted SQL literals, never input
     */
    private function close(
        int $id,
        ModificationStatus $to,
        array $fromStatuses,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): bool {
        $at = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE rental_booking_modifications
             SET status = ?, active_booking_id = NULL, closed_at = ?, closed_by_member_id = ?, updated_at = ?
             WHERE id = ? AND status IN (' . implode(', ', $fromStatuses) . ')'
        );
        $stmt->execute([$to->value, $at, $actorMemberId, $at, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The eight columns of the terms, in the order every statement here
     * writes them.
     *
     * @return list<mixed>
     */
    private function termsValues(ModificationTerms $terms): array
    {
        return [
            $terms->arrivalDate,
            $terms->departureDate,
            max(1, $terms->units),
            $terms->persons,
            json_encode($terms->price->toArray(), JSON_UNESCAPED_UNICODE),
            max(0, $terms->price->totalCents),
            $terms->discountLabel,
            $terms->discountCents,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BookingModification
    {
        $snapshot = is_string($row['price_snapshot'] ?? null) && $row['price_snapshot'] !== ''
            ? json_decode((string) $row['price_snapshot'], true)
            : null;

        return new BookingModification(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            status: ModificationStatus::tryFrom((string) $row['status']) ?? ModificationStatus::CANCELLED,
            arrivalDate: (string) $row['arrival_date'],
            departureDate: (string) $row['departure_date'],
            units: (int) $row['units'],
            persons: $row['persons'] !== null ? (int) $row['persons'] : null,
            price: is_array($snapshot) ? PriceQuote::fromArray($snapshot) : null,
            totalCents: $row['total_cents'] !== null ? (int) $row['total_cents'] : null,
            discountLabel: $row['discount_label'] !== null ? (string) $row['discount_label'] : null,
            discountCents: $row['discount_cents'] !== null ? (int) $row['discount_cents'] : null,
            previousArrivalDate: (string) $row['previous_arrival_date'],
            previousDepartureDate: (string) $row['previous_departure_date'],
            previousUnits: (int) $row['previous_units'],
            previousPersons: $row['previous_persons'] !== null ? (int) $row['previous_persons'] : null,
            previousTotalCents: $row['previous_total_cents'] !== null ? (int) $row['previous_total_cents'] : null,
            contractDocumentId: $row['contract_document_id'] !== null ? (int) $row['contract_document_id'] : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            sentAt: DateInput::fromStorage($row['sent_at'] !== null ? (string) $row['sent_at'] : null),
            closedAt: DateInput::fromStorage($row['closed_at'] !== null ? (string) $row['closed_at'] : null),
            closedByMemberId: $row['closed_by_member_id'] !== null ? (int) $row['closed_by_member_id'] : null
        );
    }
}
