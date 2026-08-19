<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * discussion_group_link_fetch_log — see that table's own schema.sql
 * comment for why member_id is stored directly (no HMAC, unlike
 * Modules\Retro\Repository\RateLimitRepository) and why there is no
 * dedicated purge task.
 */
class LinkFetchLogRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(int $memberId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO discussion_group_link_fetch_log (member_id, created_at) VALUES (?, ?)');
        $stmt->execute([$memberId, date('Y-m-d H:i:s')]);
    }

    public function countSince(int $memberId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_group_link_fetch_log WHERE member_id = ? AND created_at >= ?'
        );
        $stmt->execute([$memberId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    public function purgeOlderThan(string $beforeDatetime): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_link_fetch_log WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);
    }
}
