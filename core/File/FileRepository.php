<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

class FileRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?FileRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, relative_path, original_name, mime_type, size_bytes, role_min, custom_resolver, encrypted, owner_member_id, owner_type, owner_id
             FROM files WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new FileRecord(
            id: (int) $row['id'],
            relativePath: $row['relative_path'],
            originalName: $row['original_name'],
            mimeType: $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            roleMin: $row['role_min'],
            customResolver: $row['custom_resolver'],
            encrypted: (bool) $row['encrypted'],
            ownerMemberId: $row['owner_member_id'] !== null ? (int) $row['owner_member_id'] : null,
            ownerType: $row['owner_type'] !== null ? (string) $row['owner_type'] : null,
            ownerId: $row['owner_id'] !== null ? (int) $row['owner_id'] : null
        );
    }

    public function create(
        string $relativePath,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        string $roleMin,
        ?string $moduleId,
        ?int $createdBy,
        bool $encrypted = false,
        ?int $ownerMemberId = null,
        ?string $ownerType = null,
        ?int $ownerId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id, created_by, encrypted, owner_member_id, owner_type, owner_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$relativePath, $originalName, $mimeType, $sizeBytes, $roleMin, $moduleId, $createdBy, $encrypted ? 1 : 0, $ownerMemberId, $ownerType, $ownerId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM files WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Lets a module re-sync a file's access floor after the fact — e.g.
     * Modules\Finance\Controller\ConfigAccountController keeping a
     * receipt's file in sync with its account's role_min_view whenever
     * that changes.
     */
    public function updateRoleMin(int $id, string $roleMin): void
    {
        $stmt = $this->pdo->prepare('UPDATE files SET role_min = ? WHERE id = ?');
        $stmt->execute([$roleMin, $id]);
    }

    /**
     * Sets the generic owner_type/owner_id after the fact — needed when
     * the owning row's own id doesn't exist yet at store() time (e.g.
     * Core\Member\SectionDocumentService: the file must exist before
     * section_documents.file_id can reference it, but owner_id needs to
     * be that same section_documents row's id).
     */
    public function updateOwner(int $id, string $ownerType, int $ownerId): void
    {
        $stmt = $this->pdo->prepare('UPDATE files SET owner_type = ?, owner_id = ? WHERE id = ?');
        $stmt->execute([$ownerType, $ownerId, $id]);
    }

    /**
     * Reflects an in-place content swap (Core\File\EncryptedFileStorageService
     * ::replace(), used by the PDF compression background task — same
     * file id/relative_path, smaller content).
     */
    public function updateSizeBytes(int $id, int $sizeBytes): void
    {
        $stmt = $this->pdo->prepare('UPDATE files SET size_bytes = ? WHERE id = ?');
        $stmt->execute([$sizeBytes, $id]);
    }
}
