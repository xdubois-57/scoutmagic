<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;

class LoginThrottler
{
    private \PDO $pdo;

    public function __construct(private Connection $connection)
    {
        $this->pdo = $this->connection->getPdo();
    }

    /**
     * Record a failed login attempt for an email.
     */
    public function recordFailure(string $emailBlindIndex): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email_blind_index, attempted_at) VALUES (?, ?)'
        );
        $stmt->execute([$emailBlindIndex, $now]);
    }

    /**
     * Check if the email is currently locked out.
     * Returns the number of seconds remaining if locked, 0 if not.
     *
     * Lockout schedule:
     * - 1-4 failures: no lockout
     * - 5 failures: 1 minute lockout
     * - 10 failures: 5 minutes
     * - 20+ failures: 30 minutes
     *
     * Failures are counted within a 1-hour sliding window.
     */
    public function getLockoutRemaining(string $emailBlindIndex): int
    {
        $failures = $this->countRecentFailures($emailBlindIndex);

        if ($failures < 5) {
            return 0;
        }

        // Determine lockout duration based on failure count
        if ($failures >= 20) {
            $lockoutSeconds = 1800; // 30 minutes
        } elseif ($failures >= 10) {
            $lockoutSeconds = 300;  // 5 minutes
        } else {
            $lockoutSeconds = 60;   // 1 minute
        }

        // Find the most recent failure timestamp
        $cutoff = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE email_blind_index = ? AND attempted_at >= ?
             ORDER BY attempted_at DESC LIMIT 1'
        );
        $stmt->execute([$emailBlindIndex, $cutoff]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return 0;
        }

        $lastAttempt = new \DateTimeImmutable($row['attempted_at']);
        $unlockAt = $lastAttempt->modify('+' . $lockoutSeconds . ' seconds');
        $now = new \DateTimeImmutable();

        if ($now >= $unlockAt) {
            return 0;
        }

        return $unlockAt->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Clear failures for an email (called after successful login).
     */
    public function clearFailures(string $emailBlindIndex): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE email_blind_index = ?'
        );
        $stmt->execute([$emailBlindIndex]);
    }

    /**
     * Count failures in the last hour.
     */
    private function countRecentFailures(string $emailBlindIndex): int
    {
        $cutoff = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email_blind_index = ? AND attempted_at >= ?'
        );
        $stmt->execute([$emailBlindIndex, $cutoff]);
        return (int) $stmt->fetchColumn();
    }
}
