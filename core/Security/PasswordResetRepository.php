<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * password_reset_tokens — same conventions as Core\Security\
 * MagicLinkRepository, but every timestamp comparison is computed in PHP
 * and bound as a parameter rather than relying on MySQL's NOW()/DATE_SUB(),
 * so this repository (and its tests) work unmodified against the SQLite
 * test database too.
 */
class PasswordResetRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $emailBlindIndex, string $tokenHash, \DateTimeImmutable $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$emailBlindIndex, $tokenHash, $expiresAt->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?PasswordResetToken
    {
        $stmt = $this->pdo->prepare('SELECT * FROM password_reset_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE password_reset_tokens SET used = TRUE WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Count tokens requested for this email within the last $withinSeconds (rate limiting).
     */
    public function countRecentByEmail(string $emailBlindIndex, int $withinSeconds = 3600): int
    {
        $cutoff = (new \DateTimeImmutable("-{$withinSeconds} seconds"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens WHERE email_blind_index = ? AND created_at > ?'
        );
        $stmt->execute([$emailBlindIndex, $cutoff]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteExpired(): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE expires_at < ?');
        $stmt->execute([$now]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PasswordResetToken
    {
        return new PasswordResetToken(
            id: (int) $row['id'],
            emailBlindIndex: (string) $row['email_blind_index'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            used: (bool) $row['used'],
            createdAt: new \DateTimeImmutable((string) $row['created_at'])
        );
    }
}
