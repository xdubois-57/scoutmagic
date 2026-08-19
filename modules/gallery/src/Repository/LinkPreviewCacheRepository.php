<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

/**
 * gallery_link_preview_cache — see that table's own schema.sql comment for
 * why this caches scrape metadata only, never a downloaded image, and why
 * stale rows are purged opportunistically here rather than by a dedicated
 * scheduled task.
 */
class LinkPreviewCacheRepository
{
    private const TTL_HOURS = 24;

    public function __construct(private \PDO $pdo)
    {
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * @return array{title: ?string, description: ?string, imageUrl: ?string}|null
     *         null when there is no fresh (within TTL_HOURS) cached row.
     */
    public function findFresh(string $urlHash): ?array
    {
        $since = (new \DateTimeImmutable('-' . self::TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT title, description, image_url FROM gallery_link_preview_cache
             WHERE url_hash = ? AND fetched_at >= ?'
        );
        $stmt->execute([$urlHash, $since]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'title' => $row['title'] !== null ? (string) $row['title'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'imageUrl' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
        ];
    }

    /**
     * Upserts the scrape result for $urlHash and, as a side effect, purges
     * every row (of any hash) past TTL_HOURS — piggybacking cleanup on the
     * one write path this table has, rather than a dedicated scheduled task
     * (see schema.sql).
     */
    public function store(string $urlHash, ?string $title, ?string $description, ?string $imageUrl): void
    {
        $now = date('Y-m-d H:i:s');

        // Portable insert-or-update (no MySQL-only ON DUPLICATE KEY — this
        // repository is exercised against SQLite in tests too), same
        // pattern as Repository\S3SecretRepository::set().
        if ($this->urlHashExists($urlHash)) {
            $stmt = $this->pdo->prepare(
                'UPDATE gallery_link_preview_cache SET title = ?, description = ?, image_url = ?, fetched_at = ?
                 WHERE url_hash = ?'
            );
            $stmt->execute([$title, $description, $imageUrl, $now, $urlHash]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO gallery_link_preview_cache (url_hash, title, description, image_url, fetched_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$urlHash, $title, $description, $imageUrl, $now]);
        }

        $cutoff = (new \DateTimeImmutable('-' . self::TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $purge = $this->pdo->prepare('DELETE FROM gallery_link_preview_cache WHERE fetched_at < ?');
        $purge->execute([$cutoff]);
    }

    private function urlHashExists(string $urlHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM gallery_link_preview_cache WHERE url_hash = ?');
        $stmt->execute([$urlHash]);

        return (bool) $stmt->fetchColumn();
    }
}
