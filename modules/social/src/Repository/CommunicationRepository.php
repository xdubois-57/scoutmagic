<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

use Core\Service\DateInput;

class CommunicationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $id): ?Communication
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_communications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function create(string $title, string $body, ?int $createdBy, \DateTimeImmutable $now): int
    {
        $stamp = $now->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO social_communications (title, body, created_by, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?)'
        )->execute([$title, $body, $createdBy, $stamp, $stamp]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateText(int $id, string $title, string $body, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE social_communications SET title = ?, body = ?, updated_at = ? WHERE id = ?')
            ->execute([$title, $body, $now->format('Y-m-d H:i:s'), $id]);
    }

    /** One image or the other: choosing a gallery photo forgets the uploaded file, and back. */
    public function useGalleryPhoto(int $id, int $mediaId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE social_communications SET gallery_media_id = ?, file_id = NULL, updated_at = ? WHERE id = ?'
        )->execute([$mediaId, $now->format('Y-m-d H:i:s'), $id]);
    }

    public function useUploadedFile(int $id, int $fileId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE social_communications SET file_id = ?, gallery_media_id = NULL, updated_at = ? WHERE id = ?'
        )->execute([$fileId, $now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Communication
    {
        return new Communication(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            $row['gallery_media_id'] === null ? null : (int) $row['gallery_media_id'],
            $row['file_id'] === null ? null : (int) $row['file_id'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            DateInput::fromStorage((string) $row['created_at']) ?? new \DateTimeImmutable()
        );
    }
}
