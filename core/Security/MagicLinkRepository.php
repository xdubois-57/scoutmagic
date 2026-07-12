<?php

declare(strict_types=1);

namespace Core\Security;

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
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$emailBlindIndex, $tokenHash, $expiresAt->format('Y-m-d H:i:s')]);

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
        $stmt = $this->pdo->prepare(
            'UPDATE magic_links SET used = TRUE, confirmed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Count recent magic links for an email (for rate limiting).
     */
    public function countRecentByEmail(string $emailBlindIndex, int $withinSeconds = 3600): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM magic_links WHERE email_blind_index = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$emailBlindIndex, $withinSeconds]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete expired and used magic links. Returns the number of deleted rows.
     */
    public function deleteExpired(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM magic_links WHERE expires_at < NOW()'
        );
        $stmt->execute();

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
