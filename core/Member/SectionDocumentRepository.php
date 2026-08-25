<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Service\DateInput;

class SectionDocumentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?SectionDocument
    {
        $stmt = $this->pdo->prepare('SELECT * FROM section_documents WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return SectionDocument[]
     */
    public function findBySectionAndYear(int $sectionId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM section_documents WHERE section_id = ? AND scout_year_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$sectionId, $scoutYearId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The section each of these documents belongs to, keyed by document
     * id. Keyed rather than a flat list because the caller is an
     * authorization check (Core\Http\Controller\SectionDocumentController)
     * and it has to be able to see that an id is MISSING — a flat list of
     * section ids cannot express "one of these documents doesn't exist".
     *
     * @param int[] $ids
     * @return array<int, int> document id => section id
     */
    public function findSectionIdsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, section_id FROM section_documents WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = (int) $row['section_id'];
        }

        return $result;
    }

    /**
     * Every scout year id that has at least one document for this
     * section — Staffs page accordion (module addendum: "there will be
     * many years over time — do not render every year's contents
     * eagerly"), used together with the current scout year to build the
     * accordion's year list without walking every scout year ever
     * created.
     *
     * @return int[]
     */
    public function findScoutYearIdsWithDocuments(int $sectionId): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT scout_year_id FROM section_documents WHERE section_id = ?');
        $stmt->execute([$sectionId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every (section_id, scout_year_id) pair that has at least one
     * document — the member page walks this to know which of a member's
     * past section/year combinations are worth rendering at all.
     *
     * @param array<int, array{section_id: int, scout_year_id: int}> $pairs
     * @return array<int, array{section_id: int, scout_year_id: int}>
     */
    public function filterPairsWithDocuments(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($pairs as $pair) {
            $conditions[] = '(section_id = ? AND scout_year_id = ?)';
            $params[] = $pair['section_id'];
            $params[] = $pair['scout_year_id'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT section_id, scout_year_id FROM section_documents WHERE ' . implode(' OR ', $conditions)
        );
        $stmt->execute($params);

        return array_map(fn(array $row) => [
            'section_id' => (int) $row['section_id'],
            'scout_year_id' => (int) $row['scout_year_id'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(int $sectionId, int $scoutYearId, int $fileId, string $title, ?string $description, int $sizeBeforeBytes, ?int $createdBy): int
    {
        $sortOrder = $this->nextSortOrder($sectionId, $scoutYearId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO section_documents (section_id, scout_year_id, file_id, title, description, sort_order, compression_status, size_before_bytes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sectionId, $scoutYearId, $fileId, $title, $description, $sortOrder, SectionDocument::COMPRESSION_PENDING, $sizeBeforeBytes, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateTitleAndDescription(int $id, string $title, ?string $description): void
    {
        $stmt = $this->pdo->prepare('UPDATE section_documents SET title = ?, description = ? WHERE id = ?');
        $stmt->execute([$title, $description, $id]);
    }

    /**
     * @param int[] $orderedIds
     */
    public function reorder(int $sectionId, int $scoutYearId, array $orderedIds): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE section_documents SET sort_order = ? WHERE id = ? AND section_id = ? AND scout_year_id = ?'
        );
        foreach ($orderedIds as $index => $id) {
            $stmt->execute([$index, $id, $sectionId, $scoutYearId]);
        }
    }

    public function markCompressed(int $id, int $sizeAfterBytes): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE section_documents SET compression_status = 'compressed', size_after_bytes = ? WHERE id = ?"
        );
        $stmt->execute([$sizeAfterBytes, $id]);
    }

    public function markSkipped(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE section_documents SET compression_status = 'skipped' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM section_documents WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function nextSortOrder(int $sectionId, int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM section_documents WHERE section_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$sectionId, $scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SectionDocument
    {
        return new SectionDocument(
            id: (int) $row['id'],
            sectionId: (int) $row['section_id'],
            scoutYearId: (int) $row['scout_year_id'],
            fileId: (int) $row['file_id'],
            title: (string) $row['title'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            sortOrder: (int) $row['sort_order'],
            compressionStatus: (string) $row['compression_status'],
            sizeBeforeBytes: $row['size_before_bytes'] !== null ? (int) $row['size_before_bytes'] : null,
            sizeAfterBytes: $row['size_after_bytes'] !== null ? (int) $row['size_after_bytes'] : null,
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at')
        );
    }
}
