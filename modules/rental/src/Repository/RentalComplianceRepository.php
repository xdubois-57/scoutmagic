<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Service\DateInput;
use Modules\Rental\Compliance\ComplianceItem;

/**
 * The asset paperwork register (§6.33).
 *
 * Nothing here is encrypted: an entry is about a building and a piece of
 * paper, never about a person — the same rule and the same interface
 * responsibility as `rental_blocks.reason`.
 *
 * Every timestamp is computed in PHP and bound as a parameter, never
 * MySQL's `NOW()` — same convention as the rest of the codebase, so this
 * repository and its tests run unmodified against SQLite too.
 */
class RentalComplianceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return ComplianceItem[]
     */
    public function findForAsset(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_compliance_items
              WHERE asset_id = ?
           ORDER BY (expires_on IS NULL), expires_on ASC, label ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(
            static fn(array $row) => self::hydrate($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function findById(int $id): ?ComplianceItem
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_compliance_items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Entries whose date falls inside the next $days days — the question a
     * reminder run asks, expressed once here rather than by loading every
     * asset's register and filtering in PHP.
     *
     * Already-expired entries are included: a certificate that ran out
     * three weeks ago while nobody was looking is exactly what a reminder
     * is for, and dropping it would make the register quietest precisely
     * when it matters most.
     *
     * @return ComplianceItem[]
     */
    public function findExpiringBy(string $limitDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_compliance_items
              WHERE expires_on IS NOT NULL AND expires_on <= ?
           ORDER BY expires_on ASC'
        );
        $stmt->execute([$limitDate]);

        return array_map(
            static fn(array $row) => self::hydrate($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function create(
        int $assetId,
        string $label,
        ?int $fileId,
        ?string $expiresOn,
        ?string $remark
    ): int {
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_compliance_items (asset_id, label, file_id, expires_on, remark, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$assetId, $label, $fileId, $expiresOn, $remark, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $label, ?string $expiresOn, ?string $remark): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_compliance_items SET label = ?, expires_on = ?, remark = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$label, $expiresOn, $remark, self::now(), $id]);
    }

    /**
     * Attach or replace the entry's file.
     *
     * Replacing does not delete the old one here — the caller owns the file
     * lifecycle, because it is the only side that knows whether the old
     * file is still referenced by anything else.
     */
    public function setFile(int $id, ?int $fileId): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_compliance_items SET file_id = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$fileId, self::now(), $id]);
    }

    /**
     * Stamp that a reminder went out today, so tomorrow's run stays quiet.
     *
     * Cleared by `update()`? Deliberately not: a manager who pushes the
     * date out a year has fixed the thing, and the entry simply stops being
     * within the reminder window — no stamp to clear. One that edits the
     * label alone has changed nothing about the expiry, and re-warning them
     * the next morning would be noise.
     */
    public function markReminded(int $id, string $on): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_compliance_items SET reminded_on = ? WHERE id = ?');
        $stmt->execute([$on, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_compliance_items WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Which asset a file belongs to, for the ownership checker — the one
     * question `Core\File\FileAccessGuard` has to answer before serving a
     * compliance document.
     */
    public function findAssetIdForFile(int $fileId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT asset_id FROM rental_compliance_items WHERE file_id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $assetId = $stmt->fetchColumn();

        return $assetId === false ? null : (int) $assetId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ComplianceItem
    {
        return new ComplianceItem(
            id: (int) $row['id'],
            assetId: (int) $row['asset_id'],
            label: (string) $row['label'],
            fileId: $row['file_id'] !== null ? (int) $row['file_id'] : null,
            expiresOn: $row['expires_on'] !== null ? (string) $row['expires_on'] : null,
            remark: $row['remark'] !== null && (string) $row['remark'] !== '' ? (string) $row['remark'] : null,
            remindedOn: $row['reminded_on'] !== null ? (string) $row['reminded_on'] : null,
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            updatedAt: DateInput::requireFromStorage((string) $row['updated_at'], 'updated_at')
        );
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
