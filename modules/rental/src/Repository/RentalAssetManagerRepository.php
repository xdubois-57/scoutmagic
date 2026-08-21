<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * Who manages which asset. Stores nothing but ids and flags — a manager's
 * name, email and phone are resolved at render time from the core member
 * tables through Core\Member\MemberService, exactly as modules/groups does,
 * so no personal data lives in this module's schema.
 *
 * The `member_id` column points at `members.id`, the **persistent**
 * identity, never `member_years.id`. Same choice as `files.owner_member_id`
 * and for the same reason: a manager must not silently lose their assets
 * every September when the new scout year creates a fresh `member_years`
 * row for them.
 */
class RentalAssetManagerRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Grants management of $assetId to $memberId, or re-activates an
     * existing grant. Written as select-then-write rather than an upsert so
     * it behaves identically on MySQL and on the SQLite test database
     * (`INSERT ... ON DUPLICATE KEY UPDATE` is MySQL-only).
     */
    public function grant(int $assetId, int $memberId, bool $isRenterContact): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->findByAssetAndMember($assetId, $memberId);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_asset_managers
                 SET is_renter_contact = ?, is_active = 1, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([$isRenterContact ? 1 : 0, $now, $existing->id]);

            return $existing->id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_asset_managers
                (asset_id, member_id, is_renter_contact, is_active, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$assetId, $memberId, $isRenterContact ? 1 : 0, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByAssetAndMember(int $assetId, int $memberId): ?RentalAssetManager
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_asset_managers WHERE asset_id = ? AND member_id = ?'
        );
        $stmt->execute([$assetId, $memberId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return RentalAssetManager[]
     */
    public function findAllByAsset(int $assetId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM rental_asset_managers WHERE asset_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$assetId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Asset ids this member actively manages — the single query behind
     * every per-asset authorization check, so it stays one indexed lookup
     * (idx_rental_asset_managers_member) rather than a per-asset loop.
     *
     * Only ACTIVE grants count: a member dropped by the last Desk import
     * keeps their row (it is the record of who was responsible) but loses
     * access until they reappear.
     *
     * @return int[]
     */
    public function findActiveAssetIdsForMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT asset_id FROM rental_asset_managers WHERE member_id = ? AND is_active = 1'
        );
        $stmt->execute([$memberId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $memberIds
     * @return int[]
     */
    public function findActiveAssetIdsForMembers(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT asset_id FROM rental_asset_managers
             WHERE member_id IN ({$placeholders}) AND is_active = 1"
        );
        $stmt->execute($memberIds);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The managers whose contact details a renter may see on their own
     * tracking page. Never a public audience — see the column's own
     * comment in schema.sql.
     *
     * @return RentalAssetManager[]
     */
    public function findRenterContactsByAsset(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_asset_managers
             WHERE asset_id = ? AND is_active = 1 AND is_renter_contact = 1
             ORDER BY id ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every distinct member id holding at least one active grant — the
     * input to the post-Desk-import reconciliation, which needs to know
     * who to check without loading every asset.
     *
     * @return int[]
     */
    public function findAllActiveMemberIds(): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT member_id FROM rental_asset_managers WHERE is_active = 1'
        );

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int[]
     */
    public function findAllInactiveMemberIds(): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT member_id FROM rental_asset_managers WHERE is_active = 0'
        );

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Deactivates every grant held by these members. Never deletes: the
     * grant is the record of who was responsible for an asset, and a member
     * missing from one import (a data-entry slip, a late registration) must
     * be able to come back without a chief re-granting anything.
     *
     * @param int[] $memberIds
     * @return int Rows actually changed — the count the journal reports.
     */
    public function deactivateForMembers(array $memberIds): int
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE rental_asset_managers SET is_active = 0, updated_at = ?
             WHERE is_active = 1 AND member_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([(new \DateTimeImmutable())->format('Y-m-d H:i:s')], $memberIds));

        return $stmt->rowCount();
    }

    /**
     * @param int[] $memberIds
     * @return int Rows actually changed.
     */
    public function reactivateForMembers(array $memberIds): int
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE rental_asset_managers SET is_active = 1, updated_at = ?
             WHERE is_active = 0 AND member_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([(new \DateTimeImmutable())->format('Y-m-d H:i:s')], $memberIds));

        return $stmt->rowCount();
    }

    /**
     * Revokes a grant outright — the explicit "remove this manager" action
     * on the configuration page, as opposed to the import-driven
     * deactivation above. A chief deliberately removing someone is a real
     * deletion; an import not mentioning them is not.
     */
    public function revoke(int $assetId, int $memberId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rental_asset_managers WHERE asset_id = ? AND member_id = ?'
        );
        $stmt->execute([$assetId, $memberId]);
    }

    public function setRenterContact(int $assetId, int $memberId, bool $isRenterContact): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_asset_managers SET is_renter_contact = ?, updated_at = ?
             WHERE asset_id = ? AND member_id = ?'
        );
        $stmt->execute([
            $isRenterContact ? 1 : 0,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $assetId,
            $memberId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RentalAssetManager
    {
        return new RentalAssetManager(
            id: (int) $row['id'],
            assetId: (int) $row['asset_id'],
            memberId: (int) $row['member_id'],
            isRenterContact: (bool) $row['is_renter_contact'],
            isActive: (bool) $row['is_active']
        );
    }
}
