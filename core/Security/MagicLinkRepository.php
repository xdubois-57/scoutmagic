<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Service\DateInput;

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
    public function create(
        string $emailBlindIndex,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?string $matchCodeHash = null
    ): int {
        // created_at is stamped here rather than left to the column's
        // DEFAULT CURRENT_TIMESTAMP: countRecentByEmail() — the magic-link
        // rate limiter — compares it against a cutoff computed in PHP, and
        // a row written by one clock and filtered by another either lets a
        // flood through or locks a legitimate visitor out for an hour.
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, created_at, match_code_hash)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailBlindIndex,
            $tokenHash,
            $expiresAt->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $matchCodeHash,
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
     * Mark a magic link as used. Only an unused link is claimed, in one
     * statement: of two requests racing on the same link, exactly one
     * gets `true` and goes on to log somebody in.
     */
    public function markUsed(int $id): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE magic_links SET used = TRUE, confirmed_at = ? WHERE id = ? AND used = FALSE'
        );
        $stmt->execute([$now, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Takes one of the link's match-code attempts BEFORE the code is
     * compared, and returns the attempt's number — or null when the link
     * is spent or out of attempts. The check and the count are one
     * statement, so concurrent guesses cannot all slip in while the
     * counter still reads low: at most `$maxAttempts` codes are ever
     * compared for a link, however many requests arrive at once.
     */
    public function reserveMatchAttempt(int $id, int $maxAttempts): ?int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE magic_links SET match_attempts = match_attempts + 1
             WHERE id = ? AND used = FALSE AND match_attempts < ?'
        );
        $stmt->execute([$id, $maxAttempts]);
        if ($stmt->rowCount() === 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT match_attempts FROM magic_links WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Spends a link without confirming it — too many wrong codes. `used`
     * with no `confirmed_at`: no window can collect it, and the emailed
     * token is refused from now on.
     */
    public function invalidate(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE magic_links SET used = TRUE, confirmed_at = NULL WHERE id = ? AND used = FALSE'
        );
        $stmt->execute([$id]);
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
     * Delete expired magic links — but only those older than the
     * rate-limit window too. countRecentByEmail() counts the last hour's
     * rows, expired or not; a link that has expired after fifteen minutes
     * still has to be counted for the rest of that hour, or every purge
     * would hand an address five fresh links. Returns the number deleted.
     */
    public function deleteExpired(int $keepWithinSeconds = 3600): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $cutoff = (new \DateTimeImmutable("-{$keepWithinSeconds} seconds"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'DELETE FROM magic_links WHERE expires_at < ? AND created_at < ?'
        );
        $stmt->execute([$now, $cutoff]);

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
            expiresAt: DateInput::requireFromStorage((string) $row['expires_at'], 'expires_at'),
            used: (bool) $row['used'],
            confirmedAt: DateInput::fromStorage($row['confirmed_at'] === null ? null : (string) $row['confirmed_at']),
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            matchCodeHash: isset($row['match_code_hash']) ? (string) $row['match_code_hash'] : null,
            matchAttempts: (int) ($row['match_attempts'] ?? 0)
        );
    }
}
