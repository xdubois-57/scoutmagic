<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use PDO;

/**
 * Where the circuit breaker keeps its count (D15).
 *
 * One row per provider, created on the first failure — a provider that
 * has never failed has no row, and `closed()` is what that absence means.
 */
final class ProviderHealthRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forProvider(int $providerId): ProviderHealth
    {
        $statement = $this->pdo->prepare(
            'SELECT provider_id, consecutive_failures, opened_at, opened_until, open_count, last_reason
             FROM mail_provider_health WHERE provider_id = ?'
        );
        $statement->execute([$providerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return ProviderHealth::closed($providerId);
        }

        return $this->hydrate($row);
    }

    /**
     * Every provider the breaker has ever had to remember.
     *
     * @return array<int, ProviderHealth> provider id => health
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT provider_id, consecutive_failures, opened_at, opened_until, open_count, last_reason
             FROM mail_provider_health ORDER BY provider_id'
        );

        $health = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $health[(int) $row['provider_id']] = $this->hydrate($row);
        }

        return $health;
    }

    /**
     * Record a failure this provider is answerable for, and say whether
     * it has just been stepped over.
     *
     * Returns the health as it now stands, so the caller can journal the
     * opening without reading the row back.
     */
    public function recordFailure(int $providerId, string $reason, ?string $now = null): ProviderHealth
    {
        $now ??= date('Y-m-d H:i:s');
        $current = $this->forProvider($providerId);
        $failures = $current->consecutiveFailures + 1;

        if ($failures < ProviderHealth::FAILURES_BEFORE_OPEN || $current->isOpen($now)) {
            // Still under the threshold, or already stepped over and
            // failing again on the one attempt the « never empty a lane »
            // rule allowed. Neither reopens anything.
            $updated = new ProviderHealth(
                $providerId,
                $failures,
                $current->openedAt,
                $current->openedUntil,
                $current->openCount,
                $reason
            );
            $this->store($updated, $now);

            return $updated;
        }

        $until = date('Y-m-d H:i:s', strtotime($now) + $current->nextLockoutMinutes() * 60);
        $opened = new ProviderHealth($providerId, $failures, $now, $until, $current->openCount + 1, $reason);
        $this->store($opened, $now);

        return $opened;
    }

    /**
     * A provider answered. Everything the breaker knew about it is now
     * out of date, so all of it goes.
     *
     * Returns whether this closed an open circuit, which is the half
     * worth a journal line.
     */
    public function recordSuccess(int $providerId, ?string $now = null): bool
    {
        $now ??= date('Y-m-d H:i:s');
        $current = $this->forProvider($providerId);

        if ($current->consecutiveFailures === 0 && $current->openedUntil === null) {
            // The ordinary path, and the reason it writes nothing: every
            // message that leaves would otherwise be an UPDATE on a row
            // that already says what it needs to.
            return false;
        }

        // « Had it been shut out », not « was it still shut out this
        // second ». A lockout that lapsed a minute ago is still an
        // exclusion this success is ending, and the first message through
        // after one is exactly the « it is back » moment worth a line. On
        // `isOpen()` the line would be missed whenever recovery happened
        // to land after the clock ran out, which is most of the time.
        $wasOpen = $current->openedUntil !== null;
        // `open_count` is deliberately NOT reset. It is the memory of how
        // often this relay has come back broken, and it is what makes the
        // next lockout longer than the last; zeroing it on the first
        // success would give a relay that fails every ten minutes the
        // same five-minute penalty for ever.
        $this->store(new ProviderHealth($providerId, 0, null, null, $current->openCount, ''), $now);

        return $wasOpen;
    }

    public function forget(int $providerId): void
    {
        $this->pdo->prepare('DELETE FROM mail_provider_health WHERE provider_id = ?')->execute([$providerId]);
    }

    private function store(ProviderHealth $health, string $now): void
    {
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

        // The same portable upsert as SendCounterRepository: SQLite and
        // MySQL disagree on the spelling and on nothing else.
        $sql = $sqlite
            ? 'INSERT INTO mail_provider_health
                   (provider_id, consecutive_failures, opened_at, opened_until, open_count, last_reason, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(provider_id) DO UPDATE SET
                   consecutive_failures = excluded.consecutive_failures,
                   opened_at = excluded.opened_at,
                   opened_until = excluded.opened_until,
                   open_count = excluded.open_count,
                   last_reason = excluded.last_reason,
                   updated_at = excluded.updated_at'
            : 'INSERT INTO mail_provider_health
                   (provider_id, consecutive_failures, opened_at, opened_until, open_count, last_reason, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE
                   consecutive_failures = VALUES(consecutive_failures),
                   opened_at = VALUES(opened_at),
                   opened_until = VALUES(opened_until),
                   open_count = VALUES(open_count),
                   last_reason = VALUES(last_reason),
                   updated_at = VALUES(updated_at)';

        $this->pdo->prepare($sql)->execute([
            $health->providerId,
            $health->consecutiveFailures,
            $health->openedAt,
            $health->openedUntil,
            $health->openCount,
            mb_substr($health->lastReason, 0, 255),
            $now,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProviderHealth
    {
        return new ProviderHealth(
            (int) $row['provider_id'],
            (int) $row['consecutive_failures'],
            $row['opened_at'] === null ? null : (string) $row['opened_at'],
            $row['opened_until'] === null ? null : (string) $row['opened_until'],
            (int) $row['open_count'],
            (string) $row['last_reason']
        );
    }
}
