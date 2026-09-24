<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Repository;

use Core\File\AttachedFileRepository;
use Modules\Documents\Service\DocumentVisibility;

/**
 * The `documents` table, joined to the `files` row of each document's
 * current version for what the screens show about it.
 *
 * Timestamps are written from PHP, never left to the column default
 * (docs/module-development.md § Timestamps).
 */
class DocumentRepository implements AttachedFileRepository
{
    private const SELECT = 'SELECT d.id, d.slug, d.slug_is_random, d.title, d.description, d.visibility,'
        . ' d.file_id, d.sort_order, d.updated_at, f.mime_type, f.size_bytes, f.original_name,'
        . ' (SELECT COALESCE(MAX(v.version_number), 0) + 1 FROM document_versions v'
        . ' WHERE v.document_id = d.id) AS version_number'
        . ' FROM documents d JOIN files f ON f.id = d.file_id';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every document, in the order the chef d'unité set.
     *
     * @return list<Document>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(self::SELECT . ' ORDER BY d.sort_order, d.id');
        $documents = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $documents[] = $this->hydrate($row);
        }
        return $documents;
    }

    public function findById(int $id): ?Document
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Document
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM documents WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(
        string $slug,
        string $title,
        ?string $description,
        DocumentVisibility $visibility,
        int $fileId,
        ?int $createdBy,
        string $now
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO documents (slug, slug_is_random, title, description, visibility, file_id, sort_order,'
                . ' created_by, created_at, updated_by, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $slug,
            // DocumentService::uniqueSlug() adds the random segment exactly
            // when a document is created unlisted.
            $visibility === DocumentVisibility::DIRECT_LINK ? 1 : 0,
            $title,
            $description,
            $visibility->value,
            $fileId,
            $this->nextSortOrder(),
            $createdBy,
            $now,
            $createdBy,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * One edit, in ONE statement: title, description, visibility and —
     * when one was uploaded — the current file. Never the slug, which is
     * frozen. A single UPDATE is what keeps the row from ever carrying the
     * new visibility with the old file, or the reverse, if the write fails
     * half-way: files.role_min is derived from this row (DocumentService).
     */
    public function applyEdit(
        int $id,
        string $title,
        ?string $description,
        DocumentVisibility $visibility,
        ?int $newFileId,
        ?int $updatedBy,
        string $now
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE documents SET title = ?, description = ?, visibility = ?, file_id = COALESCE(?, file_id),'
                . ' updated_by = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, $description, $visibility->value, $newFileId, $updatedBy, $now, $id]);
    }

    /**
     * Rewrites sort_order from the given order (the list editor always
     * sends the whole list). Ids that are not documents update nothing.
     *
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        $stmt = $this->pdo->prepare('UPDATE documents SET sort_order = ? WHERE id = ?');
        foreach ($ids as $position => $id) {
            $stmt->execute([$position, $id]);
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM documents WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function isFileReferencedElsewhere(int $fileId, int $exceptDocumentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM documents WHERE file_id = ? AND id <> ?');
        $stmt->execute([$fileId, $exceptDocumentId]);
        return $stmt->fetchColumn() !== false;
    }

    private function nextSortOrder(): int
    {
        $stmt = $this->pdo->query('SELECT MAX(sort_order) FROM documents');
        $max = $stmt === false ? null : $stmt->fetchColumn();
        return $max === null || $max === false ? 0 : ((int) $max) + 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Document
    {
        return new Document(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['title'],
            $row['description'] !== null && $row['description'] !== '' ? (string) $row['description'] : null,
            DocumentVisibility::tryFrom((string) $row['visibility']) ?? DocumentVisibility::ADMIN,
            (int) $row['file_id'],
            (int) $row['sort_order'],
            (string) $row['updated_at'],
            (string) $row['mime_type'],
            (int) $row['size_bytes'],
            (string) $row['original_name'],
            (int) $row['slug_is_random'] === 1,
            (int) $row['version_number']
        );
    }
}
