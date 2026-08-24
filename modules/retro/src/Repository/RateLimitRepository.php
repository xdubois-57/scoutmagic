<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Repository;

class RateLimitRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * created_at is stamped from PHP rather than left to the column's
     * DEFAULT CURRENT_TIMESTAMP: countSince() and deleteOlderThan() both
     * filter on it against a value Service\RateLimitService computed in
     * PHP, and the two have to be on the same clock — see Core\Security\
     * HumanCheck\HumanCheckRateLimitRepository::record().
     */
    public function record(string $identifierHash, string $actionType): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO retro_rate_limits (identifier_hash, action_type, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$identifierHash, $actionType, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    /**
     * Number of $actionType actions by this identifier since $sinceDatetime
     * ('Y-m-d H:i:s') — Service\RateLimitService compares this against a
     * per-action-type ceiling.
     */
    public function countSince(string $identifierHash, string $actionType, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM retro_rate_limits WHERE identifier_hash = ? AND action_type = ? AND created_at >= ?'
        );
        $stmt->execute([$identifierHash, $actionType, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every row older than $beforeDatetime — Task\
     * PurgeRateLimitHandler's scheduled cleanup.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM retro_rate_limits WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
