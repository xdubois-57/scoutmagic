<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

/**
 * The three facts about an unresolved Desk value that cannot be recomputed
 * from the reports on hand (issue #356, D6): when it first appeared,
 * whether it has been announced, and whether a maintainer set it aside.
 *
 * Everything else the central page shows — how many installations carry
 * it, when it was last seen, the oldest version still reporting it — is
 * derived on every read from `support_installations.payload`, and is
 * deliberately NOT in this table.
 */
class DeskMappingGapRepository
{
    /**
     * A value is identified by its kind and its FOLDED spelling (D7); the
     * raw one is kept for display only.
     */
    public const KEY_SEPARATOR = '|';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every stored value, keyed by « kind|value_normalized » so a derived
     * list can join against it in memory rather than once per row.
     *
     * @return array<string, array{id: int, kind: string, value_normalized: string, value_raw: string,
     *     first_seen_at: string, notified_at: ?string, ignored_at: ?string}>
     */
    public function findAllKeyed(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM support_desk_mapping_gaps');
        if ($stmt === false) {
            return [];
        }

        $keyed = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $mapped = self::mapRow($row);
            $keyed[$mapped['kind'] . self::KEY_SEPARATOR . $mapped['value_normalized']] = $mapped;
        }

        return $keyed;
    }

    /**
     * @return array{id: int, kind: string, value_normalized: string, value_raw: string,
     *     first_seen_at: string, notified_at: ?string, ignored_at: ?string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_desk_mapping_gaps WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapRow($row);
    }

    /**
     * Remember a value this receiver had never seen, and say whether it
     * was actually new.
     *
     * The answer is what decides the notification (D8), so it has to come
     * from the database rather than from a preceding SELECT: two reports
     * arriving at the same second would both read « absent » and both
     * announce it. The unique index on (kind, value_normalized) is what
     * makes exactly one of them the first.
     */
    public function rememberIfNew(string $kind, string $valueNormalized, string $valueRaw): bool
    {
        // The portable pairing this repository already uses elsewhere
        // (Core\Help\Discovery\SeenTopicRepository): SQLite, the
        // in-memory test database, has no ON DUPLICATE KEY, and MySQL has
        // no ON CONFLICT. Both clauses are literals naming columns only;
        // every value is bound.
        $sql = 'INSERT INTO support_desk_mapping_gaps (kind, value_normalized, value_raw) VALUES (?, ?, ?)'
            . ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ' ON CONFLICT(kind, value_normalized) DO NOTHING'
                : ' ON DUPLICATE KEY UPDATE id = id');

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$kind, $valueNormalized, $valueRaw]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<int> $ids
     */
    public function markNotified(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE support_desk_mapping_gaps SET notified_at = CURRENT_TIMESTAMP
             WHERE notified_at IS NULL AND id IN ({$placeholders})"
        );
        $stmt->execute($ids);
    }

    /**
     * @return list<int>
     */
    public function idsAwaitingNotification(): array
    {
        // Set aside counts as answered. A value can sit here un-notified
        // for a while — nobody subscribed, or the push keys broken — and
        // be judged on the page in the meantime; announcing it afterwards
        // would be telling somebody about a value they have already
        // dismissed.
        $stmt = $this->pdo->query(
            'SELECT id FROM support_desk_mapping_gaps
             WHERE notified_at IS NULL AND ignored_at IS NULL
             ORDER BY id'
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(static fn(array $row): int => (int) $row['id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Set aside, or bring back. Never a delete: the next report would
     * recreate the row, re-notify, and the judgement would have to be made
     * again every morning.
     */
    public function setIgnored(int $id, bool $ignored): void
    {
        $stmt = $this->pdo->prepare(
            $ignored
                ? 'UPDATE support_desk_mapping_gaps SET ignored_at = CURRENT_TIMESTAMP WHERE id = ?'
                : 'UPDATE support_desk_mapping_gaps SET ignored_at = NULL WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, kind: string, value_normalized: string, value_raw: string,
     *     first_seen_at: string, notified_at: ?string, ignored_at: ?string}
     */
    private static function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'value_normalized' => (string) $row['value_normalized'],
            'value_raw' => (string) $row['value_raw'],
            'first_seen_at' => (string) $row['first_seen_at'],
            'notified_at' => $row['notified_at'] !== null ? (string) $row['notified_at'] : null,
            'ignored_at' => $row['ignored_at'] !== null ? (string) $row['ignored_at'] : null,
        ];
    }
}
