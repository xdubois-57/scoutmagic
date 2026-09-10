<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * Most recent backups, newest first.
     *
     * The count is the caller's business, not this query's: retention is
     * enforced per family by {@see BackupRetention} after each creation,
     * so what this returns is simply "the newest N rows, whatever they
     * are".
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
     * The same rows, with what each one occupies on disk — the list on
     * Configuration › Maintenance, which prints a size on every line and
     * repeats it in the deletion confirmation.
     *
     * Two LEFT JOINs rather than a second query per row: a backup carries
     * up to two files (the archive and the database dump), both of which
     * count, and a list of a dozen rows must not become twenty-five
     * queries. LEFT, because a row can legitimately have neither — one
     * that failed, or one whose files a previous purge already took.
     *
     * @return Backup[]
     */
    public function findForList(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, COALESCE(a.size_bytes, 0) + COALESCE(d.size_bytes, 0) AS size_bytes
             FROM backups b
             LEFT JOIN files a ON a.id = b.file_id
             LEFT JOIN files d ON d.id = b.db_dump_file_id
             ORDER BY b.created_at DESC, b.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every backup, newest first. Fetches the lot rather than paging:
     * retention keeps this table to a handful of rows by construction, and
     * "OFFSET N, unlimited" has no portable spelling anyway (MySQL rejects
     * `LIMIT -1`; SQLite spells it its own way).
     *
     * @return Backup[]
     */
    public function findAllNewestFirst(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM backups ORDER BY created_at DESC, id DESC');

        return array_map([$this, 'hydrate'], $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []);
    }

    /**
     * When the last SUCCESSFUL backup completed, or null when there has
     * never been one.
     *
     * `completed`, not merely "the most recent row": a run that failed is
     * exactly the situation this answer exists to reveal, so counting it
     * would make the reading say the opposite of the truth. And
     * `completed_at` rather than `created_at`, because a backup protects
     * the data as it stood when it finished.
     *
     * Read by `Core\Alert\Check\BackupAgeCheck`.
     */
    public function lastSuccessfulCompletedAt(): ?string
    {
        $stmt = $this->pdo->query(
            "SELECT completed_at FROM backups WHERE status = 'completed' AND completed_at IS NOT NULL "
            . 'ORDER BY completed_at DESC LIMIT 1'
        );
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return is_string($value) && $value !== '' ? $value : null;
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
            completedAt: $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            sizeBytes: isset($row['size_bytes']) ? (int) $row['size_bytes'] : null
        );
    }
}
