<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Url;

use Core\Security\EncryptionService;

/**
 * target_url is encrypted at rest: several callers shorten URLs that embed
 * bearer tokens (a retro board's /r/{token}, a news response's edit link),
 * so a plaintext column would hand working credentials to any DB read.
 * Lookup is always by code, so no blind index is needed.
 */
class ShortUrlRepository
{
    private const TARGET_ENCRYPTION_CONTEXT = 'short_urls.target_url';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
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
            'INSERT INTO short_urls (code, target_url_encrypted, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$code, $this->encryption->encrypt($targetUrl, self::TARGET_ENCRYPTION_CONTEXT), $createdBy]);
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
            targetUrl: $this->encryption->decrypt(
                (string) $row['target_url_encrypted'],
                self::TARGET_ENCRYPTION_CONTEXT
            ),
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }
}
