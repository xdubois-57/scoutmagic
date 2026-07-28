<?php

declare(strict_types=1);

namespace Core\Maintenance;

class BackupRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $type, ?int $requestedBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backups (type, status, requested_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$type, 'pending', $requestedBy]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Backup
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Most recent backups, newest first — Configuration > Maintenance's
     * "recent backups" list is capped to 5 (module spec), enforced by
     * MaintenanceController/CreateBackupHandler calling deleteOldestBeyond()
     * after each new backup, not by this query.
     *
     * @return Backup[]
     */
    public function findRecent(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backups ORDER BY created_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * All backups beyond the $keep most recent, oldest first — what
     * MaintenanceController/CreateBackupHandler delete (file + row) after
     * creating a new one, to enforce the 5-backup cap. Fetches everything
     * and slices in PHP rather than LIMIT/OFFSET — the row count is always
     * tiny (a handful at most) and "OFFSET N, unlimited" isn't portable
     * between MySQL and the SQLite test database (MySQL rejects
     * `LIMIT -1`, SQLite's own "no limit" spelling).
     *
     * @return Backup[]
     */
    public function findBeyond(int $keep): array
    {
        $stmt = $this->pdo->query('SELECT * FROM backups ORDER BY created_at DESC, id DESC');
        $all = array_map([$this, 'hydrate'], $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []);
        return array_slice($all, $keep);
    }

    public function markInProgress(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE backups SET status = 'in_progress' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function markCompleted(int $id, ?int $fileId, ?int $dbDumpFileId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE backups SET status = 'completed', file_id = ?, db_dump_file_id = ?, completed_at = ? WHERE id = ?"
        );
        $stmt->execute([$fileId, $dbDumpFileId, $now, $id]);
    }

    public function markFailed(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("UPDATE backups SET status = 'failed', error_message = ? WHERE id = ?");
        $stmt->execute([$errorMessage, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM backups WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Backup
    {
        return new Backup(
            id: (int) $row['id'],
            type: (string) $row['type'],
            fileId: $row['file_id'] !== null ? (int) $row['file_id'] : null,
            dbDumpFileId: $row['db_dump_file_id'] !== null ? (int) $row['db_dump_file_id'] : null,
            status: (string) $row['status'],
            requestedBy: $row['requested_by'] !== null ? (int) $row['requested_by'] : null,
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            createdAt: (string) $row['created_at'],
            completedAt: $row['completed_at'] !== null ? (string) $row['completed_at'] : null
        );
    }
}
