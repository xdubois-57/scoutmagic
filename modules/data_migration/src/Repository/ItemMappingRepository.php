<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Repository;

/**
 * `data_migration_items` — the correspondence of D12: which object of this
 * site an object of the old site became. The reference of deduplication: an
 * item already here is « déjà présent » and never written twice.
 */
class ItemMappingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function exists(string $installationId, string $format, string $kind, string $sourceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM data_migration_items
              WHERE installation_id = ? AND format = ? AND source_kind = ? AND source_id = ?'
        );
        $stmt->execute([$installationId, $format, $kind, $sourceId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array{target_type: string, target_id: string}|null
     */
    public function findTarget(string $installationId, string $format, string $kind, string $sourceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT target_type, target_id FROM data_migration_items
              WHERE installation_id = ? AND format = ? AND source_kind = ? AND source_id = ?'
        );
        $stmt->execute([$installationId, $format, $kind, $sourceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ['target_type' => (string) $row['target_type'], 'target_id' => (string) $row['target_id']];
    }

    /**
     * Records one correspondence. A duplicate key throws — inside the
     * item's transaction, which then rolls the written object back with it.
     */
    public function insert(
        string $installationId,
        string $format,
        string $kind,
        string $sourceId,
        string $targetType,
        string $targetId,
        int $runId,
        string $now
    ): void {
        $this->pdo->prepare(
            'INSERT INTO data_migration_items
                (installation_id, format, source_kind, source_id, target_type, target_id, run_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$installationId, $format, $kind, $sourceId, $targetType, $targetId, $runId, $now]);
    }

    public function countForRun(int $runId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM data_migration_items WHERE run_id = ?');
        $stmt->execute([$runId]);

        return (int) $stmt->fetchColumn();
    }
}
