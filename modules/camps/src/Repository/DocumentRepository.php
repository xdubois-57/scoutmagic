<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/**
 * camp_documents. No encryption: a document's TITLE is what a chief typed
 * to find it again, and the bytes themselves are guarded by
 * Core\File\FileAccessGuard through Service\CampFileOwnershipChecker.
 */
class DocumentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Document
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_documents WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Document[]
     */
    public function findByCamp(int $campId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM camp_documents WHERE camp_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$campId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByCamp(int $campId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM camp_documents WHERE camp_id = ?');
        $stmt->execute([$campId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param int[] $campIds
     * @return array<int, int> camp id => document count
     */
    public function countByCamps(array $campIds): array
    {
        $campIds = array_values(array_unique(array_map('intval', $campIds)));
        if ($campIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($campIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT camp_id, COUNT(*) AS n FROM camp_documents WHERE camp_id IN ({$placeholders}) GROUP BY camp_id"
        );
        $stmt->execute($campIds);

        $counts = array_fill_keys($campIds, 0);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['camp_id']] = (int) $row['n'];
        }

        return $counts;
    }

    public function create(
        int $campId,
        string $title,
        int $fileId,
        string $source = Document::SOURCE_MANUAL,
        ?string $sourceReference = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_documents (camp_id, title, file_id, sort_order, source, source_reference, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $campId, $title, $fileId, $this->nextSortOrder($campId), $source, $sourceReference,
            date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $title): void
    {
        $this->pdo->prepare('UPDATE camp_documents SET title = ? WHERE id = ?')->execute([$title, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM camp_documents WHERE id = ?')->execute([$id]);
    }

    /**
     * Whether any OTHER document row still points at this file — asked
     * before deleting the bytes, because the same inbound attachment can
     * legitimately be linked to two stays.
     */
    public function isFileReferencedElsewhere(int $fileId, int $exceptDocumentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM camp_documents WHERE file_id = ? AND id <> ?');
        $stmt->execute([$fileId, $exceptDocumentId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function moveCamp(int $fromCampId, int $toCampId): int
    {
        $stmt = $this->pdo->prepare('UPDATE camp_documents SET camp_id = ? WHERE camp_id = ?');
        $stmt->execute([$toCampId, $fromCampId]);

        return $stmt->rowCount();
    }

    /**
     * MAX(sort_order) + 1, never COUNT(*): after a deletion the two
     * disagree and a count-derived rank collides with an existing row.
     */
    private function nextSortOrder(int $campId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(sort_order) FROM camp_documents WHERE camp_id = ?');
        $stmt->execute([$campId]);
        $max = $stmt->fetchColumn();

        return $max !== null && $max !== false ? ((int) $max) + 1 : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Document
    {
        return new Document(
            id: (int) $row['id'],
            campId: (int) $row['camp_id'],
            title: (string) $row['title'],
            fileId: (int) $row['file_id'],
            sortOrder: (int) $row['sort_order'],
            source: (string) $row['source'],
            sourceReference: $row['source_reference'] !== null ? (string) $row['source_reference'] : null,
            createdAt: (string) $row['created_at'],
        );
    }
}
