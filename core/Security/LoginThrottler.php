<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;
use Core\Service\DateInput;

/**
 * Progressive lockout on failed password logins, counted along two
 * independent axes.
 *
 * Per email: stops someone grinding a single account's password.
 *
 * Per source IP: stops the attack the per-email counter is structurally
 * blind to — a spray of one likely password across MANY accounts, which
 * never accumulates enough failures on any single address to trip the
 * email lockout. Its thresholds are deliberately far higher, because a
 * whole unit can legitimately sit behind one NAT and a lockout here is
 * collateral for everyone sharing it.
 *
 * This is NOT the per-IP counter ARCHITECTURE.md §8.31 rules out: that one
 * is about the magic-link REQUEST form, where a second, differently-scoped
 * limiter would duplicate AuthService's own per-email rate limit for the
 * same abuse pattern. Password login has no such existing IP-scoped
 * counter, and per-email counting genuinely cannot see a spray.
 */
class LoginThrottler
{
    /** Failures within the window below which no per-email lockout applies. */
    private const EMAIL_TIERS = [
        20 => 1800,  // 30 minutes
        10 => 300,   // 5 minutes
        5 => 60,     // 1 minute
    ];

    /**
     * Same shape, per source IP. Set high enough that a shared connection
     * (a scout local, a school, a family) never trips it in normal use:
     * these counts are failures inside one hour from a single address.
     */
    private const IP_TIERS = [
        100 => 1800, // 30 minutes
        60 => 300,   // 5 minutes
        30 => 60,    // 1 minute
    ];

    private \PDO $pdo;

    /**
     * $encryption is what lets an IP be counted without being stored: the
     * raw address never reaches the database, only its blind index (same
     * HMAC treatment as every other searchable personal datum, SECURITY.md
     * §5). Null disables the per-IP axis entirely — for callers with no
     * key available (tests, CLI) — and is never the web path.
     */
    public function __construct(
        private Connection $connection,
        private ?EncryptionService $encryption = null
    ) {
        $this->pdo = $this->connection->getPdo();
    }

    /**
     * Record a failed login attempt for an email, and for the IP it came
     * from when one is known.
     */
    public function recordFailure(string $emailBlindIndex, ?string $ip = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email_blind_index, ip_blind_index, attempted_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$emailBlindIndex, $this->ipBlindIndex($ip), $now]);
    }

    /**
     * Seconds of lockout remaining, 0 if not locked out.
     *
     * Lockout schedule (failures inside a 1-hour sliding window):
     * - per email: 5 → 1 minute, 10 → 5 minutes, 20+ → 30 minutes
     * - per IP:   30 → 1 minute, 60 → 5 minutes, 100+ → 30 minutes
     *
     * Whichever axis is angrier wins — an attacker must satisfy both to
     * keep going.
     */
    public function getLockoutRemaining(string $emailBlindIndex, ?string $ip = null): int
    {
        $remaining = $this->lockoutFor('email_blind_index', $emailBlindIndex, self::EMAIL_TIERS);

        $ipBlindIndex = $this->ipBlindIndex($ip);
        if ($ipBlindIndex !== null) {
            $remaining = max($remaining, $this->lockoutFor('ip_blind_index', $ipBlindIndex, self::IP_TIERS));
        }

        return $remaining;
    }

    /**
     * How long a failed attempt is kept. Every read in this class looks
     * at a 1-hour sliding window; a week keeps recent forensic context
     * (who was spraying us yesterday) while stopping the table growing
     * forever — before this purge existed, failures against addresses
     * that never log in (bots probing /login) accumulated indefinitely,
     * since clearFailures() only fires on a SUCCESSFUL login.
     */
    public const RETENTION_DAYS = 7;

    /**
     * Delete attempts older than RETENTION_DAYS — called from the two
     * scheduler tails (public/cron.php and index.php's poor-man's cron),
     * next to the journal's own cleanup. Backed by idx_attempted_at.
     */
    public function purgeStale(): int
    {
        $cutoff = (new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Clear failures for an email (called after successful login).
     *
     * Deliberately does NOT clear the IP axis: one attacker succeeding on
     * one account they do control must not wipe the evidence of the spray
     * they are running from the same address against everyone else.
     */
    public function clearFailures(string $emailBlindIndex): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE email_blind_index = ?'
        );
        $stmt->execute([$emailBlindIndex]);
    }

    /**
     * @param array<int, int> $tiers failure count => lockout seconds, highest first
     */
    private function lockoutFor(string $column, string $value, array $tiers): int
    {
        $failures = $this->countRecentFailures($column, $value);

        $lockoutSeconds = 0;
        foreach ($tiers as $threshold => $seconds) {
            if ($failures >= $threshold) {
                $lockoutSeconds = $seconds;
                break;
            }
        }

        if ($lockoutSeconds === 0) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT attempted_at FROM login_attempts
             WHERE {$column} = ? AND attempted_at >= ?
             ORDER BY attempted_at DESC LIMIT 1"
        );
        $stmt->execute([$value, $cutoff]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return 0;
        }

        $unlockAt = (DateInput::requireFromStorage((string) $row['attempted_at'], 'attempted_at'))
            ->modify('+' . $lockoutSeconds . ' seconds');
        $now = new \DateTimeImmutable();

        if ($now >= $unlockAt) {
            return 0;
        }

        return $unlockAt->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Count failures in the last hour along one axis.
     */
    private function countRecentFailures(string $column, string $value): int
    {
        $cutoff = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE {$column} = ? AND attempted_at >= ?"
        );
        $stmt->execute([$value, $cutoff]);

        return (int) $stmt->fetchColumn();
    }

    private function ipBlindIndex(?string $ip): ?string
    {
        if ($this->encryption === null || $ip === null || trim($ip) === '') {
            return null;
        }

        return $this->encryption->blindIndex(strtolower(trim($ip)), 'login_ip');
    }
}
