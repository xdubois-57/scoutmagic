<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * Same conventions as Core\Security\PasswordResetRepository: every
 * timestamp comparison is computed in PHP and bound as a parameter rather
 * than relying on MySQL's NOW()/DATE_SUB(), so this repository (and its
 * tests) work unmodified against the SQLite test database too.
 */
class MagicLinkRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Create a magic link record. Returns the inserted ID.
     */
    public function create(string $emailBlindIndex, string $tokenHash, \DateTimeImmutable $expiresAt): int
    {
        // created_at is stamped here rather than left to the column's
        // DEFAULT CURRENT_TIMESTAMP: countRecentByEmail() — the magic-link
        // rate limiter — compares it against a cutoff computed in PHP, and
        // a row written by one clock and filtered by another either lets a
        // flood through or locks a legitimate visitor out for an hour.
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailBlindIndex,
            $tokenHash,
            $expiresAt->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Find a magic link by ID.
     */
    public function findById(int $id): ?MagicLinkRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM magic_links WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Mark a magic link as used.
     */
    public function markUsed(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE magic_links SET used = TRUE, confirmed_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $id]);
    }

    /**
     * Count recent magic links for an email (for rate limiting).
     */
    public function countRecentByEmail(string $emailBlindIndex, int $withinSeconds = 3600): int
    {
        $cutoff = (new \DateTimeImmutable("-{$withinSeconds} seconds"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM magic_links WHERE email_blind_index = ? AND created_at > ?'
        );
        $stmt->execute([$emailBlindIndex, $cutoff]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete expired and used magic links. Returns the number of deleted rows.
     */
    public function deleteExpired(): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'DELETE FROM magic_links WHERE expires_at < ?'
        );
        $stmt->execute([$now]);

        return $stmt->rowCount();
    }

    /**
     * Hydrate a MagicLinkRecord from a database row.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MagicLinkRecord
    {
        return new MagicLinkRecord(
            id: (int) $row['id'],
            emailBlindIndex: $row['email_blind_index'],
            tokenHash: $row['token_hash'],
            expiresAt: new \DateTimeImmutable($row['expires_at']),
            used: (bool) $row['used'],
            confirmedAt: !empty($row['confirmed_at']) ? new \DateTimeImmutable($row['confirmed_at']) : null,
            createdAt: new \DateTimeImmutable($row['created_at'])
        );
    }
}
