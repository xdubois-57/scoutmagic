<?php

declare(strict_types=1);

namespace Modules\Calendar\Repository;

/**
 * One row per user_account, holding the bearer token for their personal ICS
 * feed. Regeneration replaces the token in place — the old link stops
 * matching immediately (real revocation, no history kept).
 */
class CalendarPersonalTokenRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findTokenByUserAccountId(int $userAccountId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT token FROM calendar_personal_tokens WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
        $token = $stmt->fetchColumn();
        return $token !== false ? (string) $token : null;
    }

    public function findUserAccountIdByToken(string $token): ?int
    {
        $stmt = $this->pdo->prepare('SELECT user_account_id FROM calendar_personal_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $userAccountId = $stmt->fetchColumn();
        return $userAccountId !== false ? (int) $userAccountId : null;
    }

    /**
     * Insert-or-replace the token for a user account.
     */
    public function setToken(int $userAccountId, string $token): void
    {
        $existing = $this->findTokenByUserAccountId($userAccountId);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO calendar_personal_tokens (user_account_id, token) VALUES (?, ?)'
            );
            $stmt->execute([$userAccountId, $token]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE calendar_personal_tokens SET token = ? WHERE user_account_id = ?');
        $stmt->execute([$token, $userAccountId]);
    }
}
