<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

use Core\Security\EncryptionService;

/**
 * The offers of seats. The driver's name, phone and note are encrypted and
 * decrypted HERE and nowhere else (AGENTS.md § Security checklist, point
 * 3) — `Tests\Modules\Covoiturage\PhoneStaysInTheRepositoryTest` fails on
 * any other file of the module that names the column.
 */
class OfferRepository
{
    public function __construct(private \PDO $pdo, private EncryptionService $encryption)
    {
    }

    public function create(
        int $carpoolId,
        string $direction,
        string $departureTime,
        string $endpoint,
        int $seats,
        int $driverAccountId,
        string $driverName,
        string $phone,
        ?string $note
    ): int {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO carpool_offers (carpool_id, direction, departure_time, endpoint, seats,
                                         driver_user_account_id, driver_name_encrypted, phone_encrypted,
                                         note_encrypted, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $carpoolId,
            $direction,
            $departureTime,
            $endpoint,
            $seats,
            $driverAccountId,
            $this->encryption->encrypt($driverName, 'carpool_offers.driver_name'),
            $this->encryption->encrypt($phone, 'carpool_offers.phone'),
            $note !== null ? $this->encryption->encrypt($note, 'carpool_offers.note') : null,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $departureTime,
        string $endpoint,
        int $seats,
        string $driverName,
        string $phone,
        ?string $note
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE carpool_offers
                SET departure_time = ?, endpoint = ?, seats = ?, driver_name_encrypted = ?, phone_encrypted = ?,
                    note_encrypted = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $departureTime,
            $endpoint,
            $seats,
            $this->encryption->encrypt($driverName, 'carpool_offers.driver_name'),
            $this->encryption->encrypt($phone, 'carpool_offers.phone'),
            $note !== null ? $this->encryption->encrypt($note, 'carpool_offers.note') : null,
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM carpool_offers WHERE id = ?')->execute([$id]);
    }

    public function findById(int $id): ?Offer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carpool_offers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every offer of $carpoolIds, keyed by carpool, earliest departure first
     * within each direction — one query however many carpools.
     *
     * @param list<int> $carpoolIds
     * @return array<int, list<Offer>>
     */
    public function findByCarpools(array $carpoolIds): array
    {
        if ($carpoolIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($carpoolIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carpool_offers WHERE carpool_id IN ({$placeholders})
              ORDER BY direction = 'return', departure_time, id"
        );
        $stmt->execute($carpoolIds);

        $offers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $offer = $this->hydrate($row);
            $offers[$offer->carpoolId][] = $offer;
        }

        return $offers;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Offer
    {
        $note = $row['note_encrypted'] !== null
            ? $this->encryption->decrypt((string) $row['note_encrypted'], 'carpool_offers.note')
            : null;

        return new Offer(
            (int) $row['id'],
            (int) $row['carpool_id'],
            (string) $row['direction'],
            substr((string) $row['departure_time'], 0, 5),
            (string) $row['endpoint'],
            (int) $row['seats'],
            (int) $row['driver_user_account_id'],
            $this->encryption->decrypt((string) $row['driver_name_encrypted'], 'carpool_offers.driver_name'),
            $this->encryption->decrypt((string) $row['phone_encrypted'], 'carpool_offers.phone'),
            $note !== null && $note !== '' ? (string) $note : null
        );
    }
}
