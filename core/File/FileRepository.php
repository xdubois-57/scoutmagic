<?php

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
            'SELECT id, relative_path, original_name, mime_type, size_bytes, role_min, custom_resolver, encrypted
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
            encrypted: (bool) $row['encrypted']
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
        bool $encrypted = false
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id, created_by, encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$relativePath, $originalName, $mimeType, $sizeBytes, $roleMin, $moduleId, $createdBy, $encrypted ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM files WHERE id = ?');
        $stmt->execute([$id]);
    }
}
