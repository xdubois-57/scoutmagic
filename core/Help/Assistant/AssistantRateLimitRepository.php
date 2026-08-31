<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

/**
 * The assistant's quota, one row per question asked — the same shape as
 * Core\Security\HumanCheck\HumanCheckRateLimitRepository, and for the
 * same reason: an LLM call costs the unit real money at its provider, so
 * the number of them one account can start in an hour is bounded.
 *
 * Per ACCOUNT, not per IP: the assistant is `role_min: chief`, so there
 * is always a signed-in account to charge it to, and an IP hash would
 * bill a whole staff sharing one connection as if it were one person.
 *
 * The row holds an account id and an instant, and nothing else — never
 * the question. It is free text a human typed and can hold a name, an
 * address or an amount (SECURITY.md §11); the quota needs to count, not
 * to remember.
 */
class AssistantRateLimitRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * created_at is stamped from PHP rather than left to the column's
     * DEFAULT CURRENT_TIMESTAMP, for the reason the human-check limiter
     * documents: countSince() and deleteOlderThan() both compare against
     * a bound value computed in PHP, and one clock on both sides of that
     * comparison is the whole point. On SQLite, CURRENT_TIMESTAMP is UTC
     * with no session timezone to align — a window whose lower bound sits
     * ahead of every row is a limiter that never fires.
     */
    public function record(int $userAccountId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO help_assistant_rate_limits (user_account_id, created_at) VALUES (?, ?)'
        );
        $stmt->execute([$userAccountId, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    /**
     * How many questions this account has asked since $sinceDatetime
     * ('Y-m-d H:i:s').
     */
    public function countSince(int $userAccountId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM help_assistant_rate_limits WHERE user_account_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userAccountId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every row older than $beforeDatetime — Task\
     * PurgeHelpAssistantHandler's scheduled cleanup. A row past the
     * window can never affect a future countSince() again.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM help_assistant_rate_limits WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
