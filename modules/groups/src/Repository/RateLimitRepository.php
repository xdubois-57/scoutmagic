<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * discussion_group_rate_limits — the same three operations as
 * Modules\Retro\Repository\RateLimitRepository, over this module's own
 * table (see its schema.sql comment for why member_id is stored in the
 * clear here and hashed there).
 */
class RateLimitRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(int $memberId, string $actionType): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_rate_limits (member_id, action_type, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberId, $actionType, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Number of $actionType actions by this member since $sinceDatetime
     * ('Y-m-d H:i:s') — Service\RateLimitService compares this against a
     * per-action-type ceiling.
     */
    public function countSince(int $memberId, string $actionType, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_group_rate_limits
             WHERE member_id = ? AND action_type = ? AND created_at >= ?'
        );
        $stmt->execute([$memberId, $actionType, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every row older than $beforeDatetime — Task\
     * PurgeRateLimitHandler's scheduled cleanup.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_rate_limits WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
