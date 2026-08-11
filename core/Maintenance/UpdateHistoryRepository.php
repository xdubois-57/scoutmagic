<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

class UpdateHistoryRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $versionFrom, string $versionTo, bool $dependenciesChanged, ?int $requestedBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO update_history (version_from, version_to, dependencies_changed, requested_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$versionFrom, $versionTo, $dependenciesChanged ? 1 : 0, $requestedBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?UpdateHistory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM update_history WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return UpdateHistory[]
     */
    public function findRecent(int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM update_history ORDER BY started_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute();

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public function setBackupId(int $id, int $backupId): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET backup_id = ? WHERE id = ?');
        $stmt->execute([$backupId, $id]);
    }

    public function markCompleted(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE update_history SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function markFailed(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("UPDATE update_history SET status = 'failed', error_message = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([substr($errorMessage, 0, 500), $id]);
    }

    public function markRolledBack(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("UPDATE update_history SET status = 'rolled_back', error_message = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([substr($errorMessage, 0, 500), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UpdateHistory
    {
        return new UpdateHistory(
            id: (int) $row['id'],
            versionFrom: (string) $row['version_from'],
            versionTo: (string) $row['version_to'],
            status: (string) $row['status'],
            dependenciesChanged: (bool) $row['dependencies_changed'],
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            backupId: $row['backup_id'] !== null ? (int) $row['backup_id'] : null,
            requestedBy: $row['requested_by'] !== null ? (int) $row['requested_by'] : null,
            startedAt: (string) $row['started_at'],
            completedAt: $row['completed_at'] !== null ? (string) $row['completed_at'] : null
        );
    }
}
