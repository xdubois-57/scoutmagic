<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Service\DateInput;

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
        // created_at written here, not by the column's DEFAULT
        // CURRENT_TIMESTAMP — see Core\Security\MagicLinkRepository::create().
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailBlindIndex,
            $tokenHash,
            $expiresAt->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?PasswordResetToken
    {
        $stmt = $this->pdo->prepare('SELECT * FROM password_reset_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Consume a token — only a live, unused one, in one statement. Of two
     * requests holding the same link, exactly one gets `true` and goes on to
     * set a password; the other is told the link is gone (issue #1257).
     *
     * Checking the token and consuming it used to be two steps, so both
     * could pass the check. The expiry is part of the claim for the same
     * reason: a link that lapsed between the check and the write is not
     * consumed.
     *
     * rowCount() answers with the rows CHANGED on MySQL and the rows FOUND
     * on SQLite; here the two agree, because a row that matches the WHERE
     * always changes (`used` goes from false to true).
     */
    public function markUsed(int $id): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used = TRUE WHERE id = ? AND used = FALSE AND expires_at > ?'
        );
        $stmt->execute([$id, $now]);

        return $stmt->rowCount() > 0;
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

    /**
     * Delete the tokens that can no longer serve anyone — expired or used —
     * but only those older than the rate-limit window too.
     * countRecentByEmail() counts the last hour's rows whatever became of
     * them; a token spent after five minutes still has to be counted for the
     * rest of that hour, or every purge would hand an address five fresh
     * requests (issue #1257). Returns the number deleted.
     */
    public function deleteExpired(int $keepWithinSeconds = 3600): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $cutoff = (new \DateTimeImmutable("-{$keepWithinSeconds} seconds"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'DELETE FROM password_reset_tokens WHERE created_at < ? AND (expires_at < ? OR used = TRUE)'
        );
        $stmt->execute([$cutoff, $now]);

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
            expiresAt: DateInput::requireFromStorage((string) $row['expires_at'], 'expires_at'),
            used: (bool) $row['used'],
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at')
        );
    }
}
