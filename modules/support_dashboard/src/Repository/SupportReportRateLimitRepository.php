<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

use Core\Database\AdvisoryLock;

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

    /**
     * The advisory-lock name a reservation for $ipHash is serialised
     * under. `GET_LOCK()` names are capped at 64 characters, so the name
     * carries a prefix of the 64-character blind index rather than all of
     * it: a collision between two addresses only makes them take turns
     * once, and the full index is what gets COUNTED, never the lock name.
     */
    public static function reservationLockName(string $ipHash): string
    {
        return 'support_report_rate_limit:' . substr($ipHash, 0, 32);
    }

    /**
     * Counts the window and records the attempt as ONE step: true when
     * the attempt was recorded and the caller may go on, false when the
     * window already held $limit attempts — or when another request from
     * the same address is being reserved at this very instant.
     *
     * countSince() followed by record() is two statements, and two
     * requests arriving together can both count $limit - 1 and both
     * record: the cap is then exceeded by however many were in flight.
     * A named advisory lock per address (Core\Database\AdvisoryLock —
     * timeout 0, never a wait) puts the pair under one holder, and a
     * request that cannot have the lock is, by construction, one of a
     * burst from that address: « over the limit » is the truthful answer
     * for it. A row lock would not do the job here: on an address with no
     * row yet, two `FOR UPDATE` gap locks coexist and the two inserts
     * deadlock — one of them as a 500 on a route whose whole contract is
     * a uniform 403.
     */
    public function reserve(string $ipHash, string $sinceDatetime, int $limit): bool
    {
        $lock = self::reservationLockName($ipHash);
        if (!AdvisoryLock::acquire($this->pdo, $lock)) {
            return false;
        }

        try {
            if ($this->countSince($ipHash, $sinceDatetime) >= $limit) {
                return false;
            }
            $this->record($ipHash);

            return true;
        } finally {
            AdvisoryLock::release($this->pdo, $lock);
        }
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
