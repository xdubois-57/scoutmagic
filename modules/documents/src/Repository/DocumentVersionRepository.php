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
     * Files the outgoing file of $documentId as its version
     * $versionNumber. When and by whom that file became current is read
     * from its own files row, the one place that recorded it.
     */
    public function archive(int $documentId, int $versionNumber, int $fileId, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO document_versions'
            . ' (document_id, version_number, file_id, size_bytes, uploaded_at, uploaded_by, archived_at)'
            . ' SELECT ?, ?, f.id, f.size_bytes, f.created_at, f.created_by, ? FROM files f WHERE f.id = ?'
        );
        $stmt->execute([$documentId, $versionNumber, $now, $fileId]);
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
