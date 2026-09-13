<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use PDO;

/**
 * `mail_providers` — the stored half of a relay (ARCHITECTURE.md §8.106).
 *
 * Name, quota and cadence. Never a host, never a credential: those live
 * in `secrets.enc` and are read by `ProviderConnections`.
 */
final class MailProviderRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array{id: int, name: string, secret_prefix: string, daily_quota: int|null,
     *     batch_size: int, batch_interval_minutes: int}>
     */
    public function findAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, secret_prefix, daily_quota, batch_size, batch_interval_minutes
             FROM mail_providers ORDER BY id'
        );

        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array{id: int, name: string, secret_prefix: string, daily_quota: int|null,
     *     batch_size: int, batch_interval_minutes: int}|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, secret_prefix, daily_quota, batch_size, batch_interval_minutes
             FROM mail_providers WHERE id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The provider stored under one `secret_prefix`, if any.
     *
     * `TransportSeeder` resumes on this rather than on « are there any
     * providers at all »: a seed that created the legacy relay's row and
     * then failed before placing it in all three lanes would otherwise be
     * unresumable — the row exists, so a count-based guard concludes
     * there is nothing to create, and the lanes it never reached stay
     * empty for the life of the installation.
     *
     * @return array{id: int, name: string, secret_prefix: string, daily_quota: int|null,
     *     batch_size: int, batch_interval_minutes: int}|null
     */
    public function findBySecretPrefix(string $secretPrefix): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, secret_prefix, daily_quota, batch_size, batch_interval_minutes
             FROM mail_providers WHERE secret_prefix = ?'
        );
        $statement->execute([$secretPrefix]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function countAll(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_providers');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Insert a provider and hand back its id.
     *
     * The `secret_prefix` is written in two steps for every provider but
     * the first: it embeds the row's own id, which only exists once the
     * INSERT has run. The placeholder it is inserted with is random
     * rather than empty, because `secret_prefix` is UNIQUE — two rows
     * created in the same instant would both want the empty string, and
     * the loser would get an integrity error instead of a provider.
     */
    public function create(
        string $name,
        ?int $dailyQuota,
        int $batchSize,
        int $batchIntervalMinutes,
        ?string $secretPrefix = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_providers (name, secret_prefix, daily_quota, batch_size, batch_interval_minutes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $name,
            $secretPrefix ?? ('pending_' . bin2hex(random_bytes(8))),
            $dailyQuota,
            $batchSize,
            $batchIntervalMinutes,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        if ($secretPrefix === null) {
            $prefix = ProviderConnections::prefixFor($id);
            $update = $this->pdo->prepare('UPDATE mail_providers SET secret_prefix = ? WHERE id = ?');
            $update->execute([$prefix, $id]);
        }

        return $id;
    }

    public function update(int $id, string $name, ?int $dailyQuota, int $batchSize, int $batchIntervalMinutes): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_providers
             SET name = ?, daily_quota = ?, batch_size = ?, batch_interval_minutes = ?
             WHERE id = ?'
        );
        $statement->execute([$name, $dailyQuota, $batchSize, $batchIntervalMinutes, $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_providers WHERE id = ?');
        $statement->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, secret_prefix: string, daily_quota: int|null,
     *     batch_size: int, batch_interval_minutes: int}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'secret_prefix' => (string) $row['secret_prefix'],
            'daily_quota' => $row['daily_quota'] === null ? null : (int) $row['daily_quota'],
            'batch_size' => (int) $row['batch_size'],
            'batch_interval_minutes' => (int) $row['batch_interval_minutes'],
        ];
    }
}
