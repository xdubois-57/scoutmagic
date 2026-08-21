<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Booking\ChangeRequest;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\ChangeRequestStatus;
use Modules\Rental\Pricing\PriceQuote;

/**
 * Change requests and proposals (§6.16), from both sides through one table.
 *
 * The renter's message is encrypted like every other renter-written field:
 * "nous serons finalement 12 et mon fils est allergique" is personal data
 * whether it arrived through the request form or through a change request.
 */
class RentalChangeRequestRepository
{
    private const CTX_MESSAGE = 'rental_change_requests.message';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(
        int $bookingId,
        ChangeRequestOrigin $origin,
        ChangeRequestKind $kind,
        ?string $arrivalDate,
        ?string $departureDate,
        ?int $units,
        ?int $persons,
        ?PriceQuote $price,
        ?string $message
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_change_requests
                (booking_id, origin, kind, status, proposed_arrival_date, proposed_departure_date,
                 proposed_units, proposed_persons, proposed_price_snapshot, proposed_total_cents,
                 message_encrypted, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $origin->value,
            $kind->value,
            ChangeRequestStatus::PENDING->value,
            $arrivalDate,
            $departureDate,
            $units,
            $persons,
            $price !== null ? json_encode($price->toArray(), JSON_UNESCAPED_UNICODE) : null,
            $price?->totalCents,
            $message !== null && trim($message) !== ''
                ? $this->encryption->encrypt(trim($message), self::CTX_MESSAGE)
                : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?ChangeRequest
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_change_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return ChangeRequest[]
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_change_requests WHERE booking_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$bookingId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return ChangeRequest[]
     */
    public function findPendingForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_change_requests WHERE booking_id = ? AND status = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$bookingId, ChangeRequestStatus::PENDING->value]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Records a decision, but only on a request still pending.
     *
     * The `status = 'pending'` in the WHERE clause is the guard against two
     * managers deciding the same request from two screens: the second
     * update matches no row and the caller is told nothing happened,
     * instead of the second decision silently overwriting the first.
     */
    public function decide(int $id, ChangeRequestStatus $status, ?int $decidedByMemberId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_change_requests
             SET status = ?, decided_at = ?, decided_by_member_id = ?
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            $status->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $decidedByMemberId,
            $id,
            ChangeRequestStatus::PENDING->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ChangeRequest
    {
        $snapshot = null;
        if ($row['proposed_price_snapshot'] !== null && (string) $row['proposed_price_snapshot'] !== '') {
            $decoded = json_decode((string) $row['proposed_price_snapshot'], true);
            if (is_array($decoded)) {
                $snapshot = PriceQuote::fromArray($decoded);
            }
        }

        return new ChangeRequest(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            origin: ChangeRequestOrigin::from((string) $row['origin']),
            kind: ChangeRequestKind::from((string) $row['kind']),
            status: ChangeRequestStatus::from((string) $row['status']),
            proposedArrivalDate: $row['proposed_arrival_date'] !== null ? (string) $row['proposed_arrival_date'] : null,
            proposedDepartureDate: $row['proposed_departure_date'] !== null ? (string) $row['proposed_departure_date'] : null,
            proposedUnits: $row['proposed_units'] !== null ? (int) $row['proposed_units'] : null,
            proposedPersons: $row['proposed_persons'] !== null ? (int) $row['proposed_persons'] : null,
            proposedPrice: $snapshot,
            proposedTotalCents: $row['proposed_total_cents'] !== null ? (int) $row['proposed_total_cents'] : null,
            message: $row['message_encrypted'] !== null && (string) $row['message_encrypted'] !== ''
                ? $this->encryption->decrypt((string) $row['message_encrypted'], self::CTX_MESSAGE)
                : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            decidedAt: $row['decided_at'] !== null ? new \DateTimeImmutable((string) $row['decided_at']) : null,
            decidedByMemberId: $row['decided_by_member_id'] !== null ? (int) $row['decided_by_member_id'] : null
        );
    }
}
