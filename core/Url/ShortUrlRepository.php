<?php

declare(strict_types=1);

namespace Core\Url;

class ShortUrlRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findByCode(string $code): ?ShortUrl
    {
        $stmt = $this->pdo->prepare('SELECT * FROM short_urls WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM short_urls WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch() !== false;
    }

    public function create(string $code, string $targetUrl, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO short_urls (code, target_url, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$code, $targetUrl, $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ShortUrl
    {
        return new ShortUrl(
            id: (int) $row['id'],
            code: (string) $row['code'],
            targetUrl: (string) $row['target_url'],
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }
}
