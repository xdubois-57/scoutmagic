<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Availability\OccupancyProvider;

/**
 * Manual blocks (§6.18), and the second `OccupancyProvider` after bookings.
 *
 * A repository implementing the provider interface directly is deliberate:
 * a block has no rules, no lifecycle and nothing to decide, so a service
 * between this and the availability calculator would forward calls and
 * nothing else.
 *
 * **Nothing here refuses to overlap.** A block on an already-booked period
 * is a legitimate, common thing to do — a caretaker away during a rental
 * still has to be recorded — and the spec is explicit that the two must
 * coexist rather than one failing or overwriting the other. Availability
 * adds them up; the private calendar shows both.
 *
 * Every timestamp is computed in PHP, never MySQL's `NOW()`, so this runs
 * unmodified against the SQLite test database.
 */
class RentalBlockRepository implements OccupancyProvider
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(
        int $assetId,
        string $startDate,
        string $endDate,
        int $units,
        ?string $reason,
        ?int $createdByMemberId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_blocks (asset_id, start_date, end_date, units, reason, created_by_member_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $startDate,
            $endDate,
            max(1, $units),
            $reason,
            $createdByMemberId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?RentalBlock
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_blocks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return RentalBlock[]
     */
    public function findBetween(int $assetId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_blocks
             WHERE asset_id = ? AND start_date <= ? AND end_date >= ?
             ORDER BY start_date ASC'
        );
        $stmt->execute([$assetId, $to, $from]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every block on $assetId from $from onwards — the private calendar's
     * own list, which needs the reason and the id that the availability
     * view deliberately throws away.
     *
     * @return RentalBlock[]
     */
    public function findUpcoming(int $assetId, string $from): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_blocks WHERE asset_id = ? AND end_date >= ? ORDER BY start_date ASC'
        );
        $stmt->execute([$assetId, $from]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Blocks on **several** assets overlapping a window, in ONE query —
     * the calendar provider's entry point, same reasoning as
     * `RentalBookingRepository::findForAssetsBetween()` (§6.31).
     *
     * @param int[] $assetIds
     * @return RentalBlock[]
     */
    public function findForAssetsBetween(array $assetIds, string $from, string $to): array
    {
        if ($assetIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM rental_blocks
             WHERE asset_id IN ({$placeholders}) AND start_date <= ? AND end_date >= ?
             ORDER BY start_date ASC, id ASC"
        );
        $stmt->execute([...array_values($assetIds), $to, $from]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_blocks WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ── OccupancyProvider ───────────────────────────────────────────────

    /**
     * A manual block has no deadline — a manager put the asset off the
     * market and only a manager takes it back — so `$now` is accepted to
     * satisfy the interface and deliberately unused.
     *
     * @return Occupancy[]
     */
    public function findOccupancies(
        int $assetId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now
    ): array {
        return array_map(
            static fn(RentalBlock $block) => $block->toOccupancy(),
            $this->findBetween($assetId, $from->format('Y-m-d'), $to->format('Y-m-d'))
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RentalBlock
    {
        return new RentalBlock(
            id: (int) $row['id'],
            assetId: (int) $row['asset_id'],
            startDate: (string) $row['start_date'],
            endDate: (string) $row['end_date'],
            units: (int) $row['units'],
            reason: $row['reason'] !== null ? (string) $row['reason'] : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at'])
        );
    }
}
