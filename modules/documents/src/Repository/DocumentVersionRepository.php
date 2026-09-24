<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Repository;

/**
 * The versions a document used to have. The current one is not a row
 * here: it is documents.file_id (schema.sql).
 */
class DocumentVersionRepository
{
    private const SELECT = 'SELECT id, document_id, version_number, file_id, size_bytes,'
        . ' uploaded_at, uploaded_by, archived_at FROM document_versions';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Files $fileId as the newest past version of $documentId and returns
     * the number it was given. The number is computed by the INSERT itself
     * (the highest kept, plus one), not handed in from a row read earlier:
     * two edits racing see the same document, never the same MAX once the
     * first has written. When and by whom the file became current is read
     * from its own files row, the one place that recorded it.
     *
     * Idempotent: a file already archived (the other side of that race) is
     * not archived twice — its existing number is returned. A collision
     * the unique indexes still catch is the caller's to resolve.
     */
    public function archive(int $documentId, int $fileId, string $now): int
    {
        $existing = $this->versionNumberOf($fileId);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO document_versions'
            . ' (document_id, version_number, file_id, size_bytes, uploaded_at, uploaded_by, archived_at)'
            . ' SELECT ?, next.n, f.id, f.size_bytes, f.created_at, f.created_by, ?'
            . ' FROM files f, (SELECT COALESCE(MAX(version_number), 0) + 1 AS n'
            . ' FROM document_versions WHERE document_id = ?) next'
            . ' WHERE f.id = ?'
        );
        $stmt->execute([$documentId, $now, $documentId, $fileId]);

        $number = $this->versionNumberOf($fileId);
        \assert($number !== null);
        return $number;
    }

    /**
     * The version number $fileId was archived under, or null when it is
     * not a kept version.
     */
    public function versionNumberOf(int $fileId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT version_number FROM document_versions WHERE file_id = ?');
        $stmt->execute([$fileId]);
        $number = $stmt->fetchColumn();
        return $number === false ? null : (int) $number;
    }

    /**
     * A document's kept versions, newest first.
     *
     * @return list<DocumentVersion>
     */
    public function findByDocument(int $documentId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE document_id = ? ORDER BY version_number DESC');
        $stmt->execute([$documentId]);
        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every kept version, grouped by document, newest first — one query
     * for the whole management list.
     *
     * @return array<int, list<DocumentVersion>>
     */
    public function findAllByDocument(): array
    {
        $stmt = $this->pdo->query(self::SELECT . ' ORDER BY document_id, version_number DESC');
        $grouped = [];
        foreach ($this->hydrateAll($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC)) as $version) {
            $grouped[$version->documentId][] = $version;
        }
        return $grouped;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM document_versions WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<DocumentVersion>
     */
    private function hydrateAll(array $rows): array
    {
        $versions = [];
        foreach ($rows as $row) {
            \assert(is_array($row));
            $versions[] = new DocumentVersion(
                (int) $row['id'],
                (int) $row['document_id'],
                (int) $row['version_number'],
                (int) $row['file_id'],
                (int) $row['size_bytes'],
                (string) $row['uploaded_at'],
                $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
                (string) $row['archived_at']
            );
        }
        return $versions;
    }
}
