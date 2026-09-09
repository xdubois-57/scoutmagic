<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

class OperationalAlertRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The stored state for a key, or an armed one when the row does not
     * exist. Never null: "no row" and "armed" are the same fact, and
     * making every caller re-decide that is how one of them eventually
     * decides differently.
     */
    public function findOrArmed(string $alertKey): OperationalAlert
    {
        $stmt = $this->pdo->prepare('SELECT * FROM operational_alerts WHERE alert_key = ?');
        $stmt->execute([$alertKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? OperationalAlert::armed($alertKey) : $this->hydrate($row);
    }

    /** @return OperationalAlert[] every currently-triggered alert, oldest trigger first */
    public function findTriggered(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM operational_alerts WHERE state = 'triggered' ORDER BY triggered_at ASC, alert_key ASC"
        );

        return array_map([$this, 'hydrate'], $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []);
    }

    /**
     * Moves a key to triggered and stamps both timestamps.
     *
     * An upsert, because the row may legitimately not exist: a healthy
     * installation never writes one, so the first time a check trips is
     * also the first time its key is stored.
     *
     * Written as delete-then-insert rather than `INSERT … ON DUPLICATE KEY
     * UPDATE`, which SQLite spells `ON CONFLICT … DO UPDATE` — the tests
     * run on SQLite and production on MySQL/MariaDB, and this codebase's
     * convention for that split is one pair of statements that means the
     * same thing on every engine (`Modules\Fees\Repository\
     * HouseholdTariffRepository::save()` and five others). Nothing is lost
     * by the delete: this method rewrites every column of the row anyway.
     * Two round trips, on a path that runs once when something breaks.
     */
    public function markTriggered(string $alertKey, string $value): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $delete = $this->pdo->prepare('DELETE FROM operational_alerts WHERE alert_key = ?');
        $delete->execute([$alertKey]);

        $insert = $this->pdo->prepare(
            'INSERT INTO operational_alerts (alert_key, state, triggered_at, last_notified_at, last_value) '
            . "VALUES (?, 'triggered', ?, ?, ?)"
        );
        $insert->execute([$alertKey, $now, $now, self::truncate($value)]);
    }

    /**
     * Back to armed. Deliberately clears `triggered_at` — the row now
     * describes a check that is watching, not one that has fired — while
     * `last_notified_at` survives, because "when did this last speak" is
     * the question somebody asks after the fact.
     */
    public function markArmed(string $alertKey, string $value): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE operational_alerts SET state = 'armed', triggered_at = NULL, last_value = ? WHERE alert_key = ?"
        );
        $stmt->execute([self::truncate($value), $alertKey]);
    }

    /**
     * Records what a check saw without changing its state — the ordinary
     * outcome, between the two thresholds or simply healthy.
     *
     * Updates only, and never inserts: a key that has never tripped has no
     * row, and writing one for every healthy pass would fill the table
     * with rows saying nothing.
     */
    public function recordValue(string $alertKey, string $value): void
    {
        $stmt = $this->pdo->prepare('UPDATE operational_alerts SET last_value = ? WHERE alert_key = ?');
        $stmt->execute([self::truncate($value), $alertKey]);
    }

    /**
     * `last_value` is a VARCHAR(255) and the readings that reach it are
     * short by construction. Truncating rather than letting the driver
     * refuse keeps a surprising value from turning an alert into an
     * exception — the one moment the site can least afford one.
     */
    private static function truncate(string $value): string
    {
        return mb_substr($value, 0, 255);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OperationalAlert
    {
        return new OperationalAlert(
            alertKey: (string) $row['alert_key'],
            state: (string) $row['state'],
            triggeredAt: $row['triggered_at'] !== null ? (string) $row['triggered_at'] : null,
            lastNotifiedAt: $row['last_notified_at'] !== null ? (string) $row['last_notified_at'] : null,
            lastValue: $row['last_value'] !== null ? (string) $row['last_value'] : null
        );
    }
}
