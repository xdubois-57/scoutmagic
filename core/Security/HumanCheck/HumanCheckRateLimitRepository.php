<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security\HumanCheck;

class HumanCheckRateLimitRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * created_at is stamped from PHP rather than left to the column's
     * DEFAULT CURRENT_TIMESTAMP, because countSince() and deleteOlderThan()
     * below both filter on it with a bound value computed in PHP. One clock
     * on both sides of that comparison is the whole point: a window whose
     * lower bound sits ahead of every row in the table is a limiter that
     * never fires.
     */
    public function record(string $ipHash, string $formKey): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO human_check_rate_limits (ip_hash, form_key, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$ipHash, $formKey, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    /**
     * Number of $formKey submissions by this IP hash since $sinceDatetime
     * ('Y-m-d H:i:s') — HumanCheckService compares this against the
     * configured ceiling.
     */
    public function countSince(string $ipHash, string $formKey, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM human_check_rate_limits WHERE ip_hash = ? AND form_key = ? AND created_at >= ?'
        );
        $stmt->execute([$ipHash, $formKey, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every row older than $beforeDatetime — Task\
     * PurgeHumanCheckRateLimitsHandler's scheduled cleanup.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM human_check_rate_limits WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
