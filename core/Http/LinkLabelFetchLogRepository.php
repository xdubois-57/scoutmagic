<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * link_label_fetch_log — one row per « baguette magique » request, for
 * Core\Http\LinkLabelResolver's per-account budget. See the table's own
 * comment in schema/core.sql.
 */
class LinkLabelFetchLogRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(int $userAccountId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO link_label_fetch_log (user_account_id, created_at) VALUES (?, ?)');
        $stmt->execute([$userAccountId, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    public function countSince(int $userAccountId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM link_label_fetch_log WHERE user_account_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userAccountId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    public function purgeOlderThan(string $beforeDatetime): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM link_label_fetch_log WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);
    }
}
