<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * gallery_link_preview_cache — see that table's own comment in
 * schema/core.sql for why this caches scrape metadata only, never a
 * downloaded image, why stale rows are purged opportunistically here rather
 * than by a dedicated scheduled task, and why a core table still carries
 * the gallery's prefix.
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
     * Null when there is no fresh (within TTL_HOURS) cached row.
     */
    public function findFresh(string $urlHash): ?UrlMetadata
    {
        $since = (new \DateTimeImmutable('-' . self::TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT title, html_title, description, image_url FROM gallery_link_preview_cache
             WHERE url_hash = ? AND fetched_at >= ?'
        );
        $stmt->execute([$urlHash, $since]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $text = static fn (mixed $value): ?string => $value !== null ? (string) $value : null;

        return new UrlMetadata(
            $text($row['title']),
            $text($row['html_title']),
            $text($row['description']),
            $text($row['image_url'])
        );
    }

    /**
     * Upserts the scrape result for $urlHash and, as a side effect, purges
     * every row (of any hash) past TTL_HOURS — piggybacking cleanup on the
     * one write path this table has, rather than a dedicated scheduled task
     * (see schema.sql).
     */
    public function store(string $urlHash, UrlMetadata $metadata): void
    {
        $now = date('Y-m-d H:i:s');
        // Clipped to the columns: a third-party page can put anything in
        // these tags, and a clipped title beats a PDOException.
        $title = self::clip($metadata->ogTitle, 255);
        $htmlTitle = self::clip($metadata->htmlTitle, 255);
        $description = $metadata->description;
        $imageUrl = self::clip($metadata->imageUrl, 500);

        // Portable insert-or-update (no MySQL-only ON DUPLICATE KEY — this
        // repository is exercised against SQLite in tests too).
        if ($this->urlHashExists($urlHash)) {
            $stmt = $this->pdo->prepare(
                'UPDATE gallery_link_preview_cache SET title = ?, html_title = ?, description = ?, image_url = ?,
                 fetched_at = ? WHERE url_hash = ?'
            );
            $stmt->execute([$title, $htmlTitle, $description, $imageUrl, $now, $urlHash]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO gallery_link_preview_cache (url_hash, title, html_title, description, image_url, fetched_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$urlHash, $title, $htmlTitle, $description, $imageUrl, $now]);
        }

        $cutoff = (new \DateTimeImmutable('-' . self::TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $purge = $this->pdo->prepare('DELETE FROM gallery_link_preview_cache WHERE fetched_at < ?');
        $purge->execute([$cutoff]);
    }

    private static function clip(?string $value, int $length): ?string
    {
        return $value !== null ? mb_substr($value, 0, $length) : null;
    }

    private function urlHashExists(string $urlHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM gallery_link_preview_cache WHERE url_hash = ?');
        $stmt->execute([$urlHash]);

        return (bool) $stmt->fetchColumn();
    }
}
