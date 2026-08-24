<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

/**
 * Per-IP sliding-window counter for the statistics intake endpoint.
 *
 * A deliberate copy of Core\Security\HumanCheck\HumanCheckRateLimitRepository's
 * shape rather than a new mechanism: short-lived rows, counted since a
 * cutoff, purged past the window. The raw address is never stored — only its
 * HMAC blind index — because an IP address is personal data (SECURITY.md
 * §16 applies the same rule to the public-form limiter).
 */
class SupportReportRateLimitRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * created_at from PHP, never the column's DEFAULT CURRENT_TIMESTAMP —
     * countSince() below compares it against a PHP-computed cutoff, and the
     * copied original documents why that has to be one clock (Core\Security\
     * HumanCheck\HumanCheckRateLimitRepository::record()).
     */
    public function record(string $ipHash): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO support_report_rate_limits (ip_hash, created_at) VALUES (?, ?)');
        $stmt->execute([$ipHash, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    public function countSince(string $ipHash, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM support_report_rate_limits WHERE ip_hash = ? AND created_at >= ?'
        );
        $stmt->execute([$ipHash, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM support_report_rate_limits WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
