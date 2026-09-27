<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Held;

/**
 * The `held_documents` table (schema/core.sql). Every token arrives here
 * already hashed: this class never sees a key that opens anything.
 */
class HeldDocumentRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(
        int $fileId,
        string $browserTokenHash,
        string $appTokenHash,
        string $sessionHash,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $browserExpiresAt,
        \DateTimeImmutable $expiresAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO held_documents
                (file_id, browser_token_hash, app_token_hash, session_hash, created_at, browser_expires_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fileId,
            $browserTokenHash,
            $appTokenHash,
            $sessionHash,
            self::format($createdAt),
            self::format($browserExpiresAt),
            self::format($expiresAt),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Takes the browser key, once: marks the row opened and returns it, or
     * null when the key is unknown, expired or already used.
     *
     * The UPDATE is the decision, not a SELECT before it. Two requests
     * racing with the same key both find the row unopened; only one of
     * them changes it, and the other is told so by the row count.
     *
     * @return array{id: int, file_id: int}|null
     */
    public function claimForBrowser(string $browserTokenHash, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, file_id FROM held_documents
             WHERE browser_token_hash = ? AND browser_opened_at IS NULL AND browser_expires_at > ?'
        );
        $stmt->execute([$browserTokenHash, self::format($now)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $claim = $this->pdo->prepare(
            'UPDATE held_documents SET browser_opened_at = ? WHERE id = ? AND browser_opened_at IS NULL'
        );
        $claim->execute([self::format($now), (int) $row['id']]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        return ['id' => (int) $row['id'], 'file_id' => (int) $row['file_id']];
    }

    /**
     * The row an application key opens for THIS session, or null.
     *
     * @return array{id: int, file_id: int}|null
     */
    public function findForApp(string $appTokenHash, string $sessionHash, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, file_id, session_hash FROM held_documents WHERE app_token_hash = ? AND expires_at > ?'
        );
        $stmt->execute([$appTokenHash, self::format($now)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['session_hash'], $sessionHash)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'file_id' => (int) $row['file_id']];
    }

    /**
     * @return list<array{id: int, file_id: int}>
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare('SELECT id, file_id FROM held_documents WHERE expires_at <= ? ORDER BY id');
        $stmt->execute([self::format($now)]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['id' => (int) $row['id'], 'file_id' => (int) $row['file_id']];
        }

        return $rows;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM held_documents WHERE id = ?')->execute([$id]);
    }

    private static function format(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
