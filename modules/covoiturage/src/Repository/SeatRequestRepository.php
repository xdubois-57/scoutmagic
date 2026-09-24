<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

use Core\Security\EncryptionService;

/**
 * The requests for seats. The requester's name, the passengers' names and
 * the phone are encrypted and decrypted HERE and nowhere else.
 */
class SeatRequestRepository
{
    public function __construct(private \PDO $pdo, private EncryptionService $encryption)
    {
    }

    /**
     * @param list<string> $passengerNames
     */
    public function create(
        int $offerId,
        int $requesterAccountId,
        string $requesterName,
        array $passengerNames,
        string $phone
    ): int {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO carpool_requests (offer_id, requester_user_account_id, requester_name_encrypted,
                                           passenger_names_encrypted, passenger_count, phone_encrypted, status,
                                           created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $offerId,
            $requesterAccountId,
            $this->encryption->encrypt($requesterName, 'carpool_requests.requester_name'),
            $this->encryption->encrypt(
                (string) json_encode($passengerNames, JSON_UNESCAPED_UNICODE),
                'carpool_requests.passenger_names'
            ),
            count($passengerNames),
            $this->encryption->encrypt($phone, 'carpool_requests.phone'),
            SeatRequest::PENDING,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?SeatRequest
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carpool_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every request on $offerIds, keyed by offer, oldest first.
     *
     * @param list<int> $offerIds
     * @return array<int, list<SeatRequest>>
     */
    public function findByOffers(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($offerIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carpool_requests WHERE offer_id IN ({$placeholders}) ORDER BY created_at, id"
        );
        $stmt->execute($offerIds);

        $requests = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $request = $this->hydrate($row);
            $requests[$request->offerId][] = $request;
        }

        return $requests;
    }

    /** Seats already granted on an offer, counted in people. */
    public function acceptedSeats(int $offerId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(passenger_count), 0) FROM carpool_requests WHERE offer_id = ? AND status = 'accepted'"
        );
        $stmt->execute([$offerId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Holds the offer's row until the surrounding transaction ends, so two
     * decisions on the same car count its seats one after the other. A
     * plain read takes no lock under REPEATABLE READ: both would see the
     * same free seats and both would accept. SQLite, used by the unit
     * tests, locks the whole database on write and has no FOR UPDATE.
     */
    public function lockOffer(int $offerId): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM carpool_offers WHERE id = ? FOR UPDATE');
        $stmt->execute([$offerId]);
        $stmt->fetchAll();
    }

    /**
     * Moves a request from one status to another, and only from that one:
     * false when somebody else decided first (another tab, a double click).
     */
    public function transition(int $id, string $from, string $to): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE carpool_requests SET status = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([$to, $now, $now, $id, $from]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Pending requests asked before $askedBefore whose driver was not
     * reminded since $remindedBefore — the reminder's queue, oldest first.
     *
     * @return list<SeatRequest>
     */
    public function findPendingToRemind(string $askedBefore, string $remindedBefore): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carpool_requests
              WHERE status = 'pending' AND created_at <= ? AND (reminded_at IS NULL OR reminded_at <= ?)
              ORDER BY created_at, id"
        );
        $stmt->execute([$askedBefore, $remindedBefore]);

        return array_map(fn(array $row): SeatRequest => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function markReminded(int $id, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare('UPDATE carpool_requests SET reminded_at = ? WHERE id = ?')
            ->execute([$at->format('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM carpool_requests WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SeatRequest
    {
        $names = json_decode(
            $this->encryption->decrypt((string) $row['passenger_names_encrypted'], 'carpool_requests.passenger_names'),
            true
        );

        return new SeatRequest(
            (int) $row['id'],
            (int) $row['offer_id'],
            (int) $row['requester_user_account_id'],
            $this->encryption->decrypt((string) $row['requester_name_encrypted'], 'carpool_requests.requester_name'),
            is_array($names) ? array_values(array_map('strval', $names)) : [],
            (int) $row['passenger_count'],
            $this->encryption->decrypt((string) $row['phone_encrypted'], 'carpool_requests.phone'),
            (string) $row['status'],
            (string) $row['created_at']
        );
    }
}
