<?php

declare(strict_types=1);

namespace Core\Security\HumanCheck;

class HumanCheckRateLimitRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(string $ipHash, string $formKey): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO human_check_rate_limits (ip_hash, form_key) VALUES (?, ?)');
        $stmt->execute([$ipHash, $formKey]);
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
