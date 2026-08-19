<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

class MediaRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Media
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gallery_media WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Media[]
     */
    public function findByAlbumId(int $albumId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gallery_media WHERE album_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$albumId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByAlbumId(int $albumId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM gallery_media WHERE album_id = ?');
        $stmt->execute([$albumId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The rank a newly uploaded media should take to land last in the album.
     * Deliberately MAX(sort_order) + 1 rather than COUNT(*): after any
     * deletion the two disagree, and a count-derived rank then collides with
     * an existing row (so did five parallel uploads all reading the same
     * count), silently scrambling the order a chief had arranged.
     */
    public function nextSortOrder(int $albumId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(sort_order) FROM gallery_media WHERE album_id = ?');
        $stmt->execute([$albumId]);
        $max = $stmt->fetchColumn();

        return $max !== null && $max !== false ? ((int) $max) + 1 : 0;
    }

    public function create(
        int $albumId,
        string $mediaType,
        int $fileId,
        int $sortOrder,
        ?string $originalFilename
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gallery_media (album_id, media_type, file_id, processing_status, sort_order, original_filename, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$albumId, $mediaType, $fileId, Media::STATUS_PENDING, $sortOrder, $originalFilename, date('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markProcessing(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE gallery_media SET processing_status = ? WHERE id = ?');
        $stmt->execute([Media::STATUS_PROCESSING, $id]);
    }

    public function markFailed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE gallery_media SET processing_status = ? WHERE id = ?');
        $stmt->execute([Media::STATUS_FAILED, $id]);
    }

    public function markPhotoDone(int $id, string $thumbPath, string $mediumPath, string $largePath, int $width, int $height): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE gallery_media SET processing_status = ?, thumb_path = ?, medium_path = ?, large_path = ?, width = ?, height = ? WHERE id = ?'
        );
        $stmt->execute([Media::STATUS_DONE, $thumbPath, $mediumPath, $largePath, $width, $height, $id]);
    }

    public function markVideoDone(
        int $id,
        string $thumbPath,
        string $mediumPath,
        ?string $largePath,
        ?string $originalPath,
        int $width,
        int $height,
        int $durationSeconds
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE gallery_media SET processing_status = ?, thumb_path = ?, medium_path = ?, large_path = ?, original_path = ?, width = ?, height = ?, duration_seconds = ? WHERE id = ?'
        );
        $stmt->execute([Media::STATUS_DONE, $thumbPath, $mediumPath, $largePath, $originalPath, $width, $height, $durationSeconds, $id]);
    }

    /**
     * Writes $orderedMediaIds' positions as sort_order 0..n-1. Scoped to
     * $albumId in the WHERE clause as a second line of defence: the caller
     * (Service\MediaService::reorder()) already validates that every id
     * belongs to the album, and this makes a future caller that forgets to
     * a no-op rather than a cross-album write.
     *
     * @param int[] $orderedMediaIds
     */
    public function reorder(int $albumId, array $orderedMediaIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE gallery_media SET sort_order = ? WHERE id = ? AND album_id = ?');
        foreach (array_values($orderedMediaIds) as $index => $mediaId) {
            $stmt->execute([$index, $mediaId, $albumId]);
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM gallery_media WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Media
    {
        return new Media(
            id: (int) $row['id'],
            albumId: (int) $row['album_id'],
            mediaType: (string) $row['media_type'],
            fileId: (int) $row['file_id'],
            thumbPath: $row['thumb_path'] !== null ? (string) $row['thumb_path'] : null,
            mediumPath: $row['medium_path'] !== null ? (string) $row['medium_path'] : null,
            largePath: $row['large_path'] !== null ? (string) $row['large_path'] : null,
            originalPath: $row['original_path'] !== null ? (string) $row['original_path'] : null,
            processingStatus: (string) $row['processing_status'],
            width: $row['width'] !== null ? (int) $row['width'] : null,
            height: $row['height'] !== null ? (int) $row['height'] : null,
            durationSeconds: $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            sortOrder: (int) $row['sort_order'],
            originalFilename: $row['original_filename'] !== null ? (string) $row['original_filename'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
