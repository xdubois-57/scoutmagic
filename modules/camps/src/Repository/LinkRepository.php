<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/** camp_links. A URL is not personal data (SECURITY.md §5) — no encryption here. */
class LinkRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Link
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_links WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Link[]
     */
    public function findByCamp(int $campId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_links WHERE camp_id = ? ORDER BY id ASC');
        $stmt->execute([$campId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(
        int $campId,
        string $url,
        ?string $title,
        ?string $description,
        ?int $imageFileId,
        ?string $siteName,
        ?string $fetchedAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_links (camp_id, url, title, description, image_file_id, site_name, fetched_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $campId, $url, $title, $description, $imageFileId, $siteName, $fetchedAt, date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM camp_links WHERE id = ?')->execute([$id]);
    }

    public function moveCamp(int $fromCampId, int $toCampId): int
    {
        $stmt = $this->pdo->prepare('UPDATE camp_links SET camp_id = ? WHERE camp_id = ?');
        $stmt->execute([$toCampId, $fromCampId]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Link
    {
        return new Link(
            id: (int) $row['id'],
            campId: (int) $row['camp_id'],
            url: (string) $row['url'],
            title: $this->nullable($row['title']),
            description: $this->nullable($row['description']),
            imageFileId: $row['image_file_id'] !== null ? (int) $row['image_file_id'] : null,
            siteName: $this->nullable($row['site_name']),
            fetchedAt: $this->nullable($row['fetched_at']),
            createdAt: (string) $row['created_at'],
        );
    }

    private function nullable(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
