<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

/**
 * The receiver's record of every installation that reports to it
 * (ARCHITECTURE.md §8.45).
 *
 * One row per installation, never a history: each accepted report
 * overwrites the previous one. The shared secret is stored **only** as a
 * `password_hash()`, never in clear, in any column.
 */
class SupportInstallationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByInstallationId(string $installationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_installations WHERE installation_id = ?');
        $stmt->execute([$installationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_installations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Every retained installation, newest report first.
     *
     * Deliberately unpaginated and unfiltered: filtering, searching,
     * sorting and paging all happen in Modules\SupportDashboard\Service\
     * SupportDashboardService. See its docblock for why — the short version
     * is that one of the filters reads the stored JSON payload, which no
     * portable SQL can express, and splitting the work would make the
     * table, the counters and the KPI cards disagree with each other.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM support_installations ORDER BY last_received_at DESC, id DESC');

        return $stmt !== false ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * First registration: an installation id nobody has seen before.
     *
     * @param array<string, mixed> $denormalized
     */
    public function register(string $installationId, string $secretHash, string $rawPayload, array $denormalized): int
    {
        $columns = array_merge(
            ['installation_id', 'secret_hash', 'payload'],
            array_keys($denormalized)
        );
        $values = self::bindable(array_merge([$installationId, $secretHash, $rawPayload], array_values($denormalized)));

        $stmt = $this->pdo->prepare(
            'INSERT INTO support_installations (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A subsequent report from a known installation. Deliberately replaces
     * every denormalised column, including with NULL: a sender that stops
     * being able to measure something must stop reporting it, not keep the
     * last value it happened to know.
     *
     * @param array<string, mixed> $denormalized
     */
    public function recordReport(int $id, string $rawPayload, array $denormalized): void
    {
        $assignments = ['payload = ?', 'last_received_at = ?'];
        $values = [$rawPayload, (new \DateTimeImmutable())->format('Y-m-d H:i:s')];

        foreach ($denormalized as $column => $value) {
            $assignments[] = $column . ' = ?';
            $values[] = $value;
        }

        $values[] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE support_installations SET ' . implode(', ', $assignments) . ' WHERE id = ?'
        );
        $stmt->execute(self::bindable($values));
    }

    /**
     * PDOStatement::execute() binds every value of its array as a string,
     * and PHP casts `false` to `''` — which MySQL refuses for a BOOLEAN
     * (TINYINT) column under strict mode, and which SQLite silently stores
     * as an empty string that then reads back as "not reported". Both are
     * wrong for the same reason: `false` is a reported value, not a missing
     * one. NULL is left untouched — that one really is "not reported".
     *
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private static function bindable(array $values): array
    {
        return array_map(
            static fn(mixed $value): mixed => is_bool($value) ? (int) $value : $value,
            $values
        );
    }
}
