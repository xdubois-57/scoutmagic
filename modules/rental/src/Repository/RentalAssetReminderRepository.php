<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Reminder\ReminderKind;

/**
 * What one asset changed about its reminders (§6.29).
 *
 * **Only the differences are stored.** Twelve reminders times every asset
 * would be a table nobody edits and a migration that has to keep in step
 * with an enum; a row exists because somebody changed something, and its
 * absence means "whatever the unit's default says".
 *
 * Every timestamp is computed in PHP, never MySQL's `NOW()`, so this runs
 * unmodified against the SQLite test database.
 */
class RentalAssetReminderRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * One asset's overrides, keyed by `ReminderKind::value`.
     *
     * @return array<string, array{days: int|null, active: bool}>
     */
    public function findForAsset(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT reminder_key, delay_days, is_active FROM rental_asset_reminders WHERE asset_id = ?'
        );
        $stmt->execute([$assetId]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['reminder_key']] = [
                'days' => $row['delay_days'] === null ? null : (int) $row['delay_days'],
                'active' => (bool) $row['is_active'],
            ];
        }

        return $rows;
    }

    /**
     * Every asset's overrides in one query, keyed by asset id.
     *
     * The daily pass walks every booking of every asset; reading this per
     * asset would be one query per hall, every morning, for a table most
     * units never write to at all.
     *
     * @return array<int, array<string, array{days: int|null, active: bool}>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT asset_id, reminder_key, delay_days, is_active FROM rental_asset_reminders');
        if ($stmt === false) {
            return [];
        }

        $byAsset = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byAsset[(int) $row['asset_id']][(string) $row['reminder_key']] = [
                'days' => $row['delay_days'] === null ? null : (int) $row['delay_days'],
                'active' => (bool) $row['is_active'],
            ];
        }

        return $byAsset;
    }

    /**
     * Records one reminder's setting for one asset.
     *
     * Written as select-then-write rather than an upsert so it behaves
     * identically on MySQL and on the SQLite test database, whose conflict
     * syntaxes differ — the same reasoning as
     * `RentalDocumentRepository::saveText()`.
     *
     * A row that says nothing — inheriting the delay AND active, which is
     * the shipped state — is deleted rather than stored: a table of rows
     * that mean "no change" is a table whose size stops telling anybody
     * anything.
     */
    public function save(int $assetId, ReminderKind $kind, ?int $delayDays, bool $isActive): void
    {
        if ($delayDays === null && $isActive) {
            $this->clear($assetId, $kind);

            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT id FROM rental_asset_reminders WHERE asset_id = ? AND reminder_key = ?'
        );
        $stmt->execute([$assetId, $kind->value]);

        if ($stmt->fetchColumn() === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO rental_asset_reminders (asset_id, reminder_key, delay_days, is_active, updated_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $assetId,
                $kind->value,
                $delayDays === null ? null : max(0, $delayDays),
                $isActive ? 1 : 0,
                $now,
            ]);

            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE rental_asset_reminders SET delay_days = ?, is_active = ?, updated_at = ?
             WHERE asset_id = ? AND reminder_key = ?'
        );
        $update->execute([
            $delayDays === null ? null : max(0, $delayDays),
            $isActive ? 1 : 0,
            $now,
            $assetId,
            $kind->value,
        ]);
    }

    public function clear(int $assetId, ReminderKind $kind): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_asset_reminders WHERE asset_id = ? AND reminder_key = ?');
        $stmt->execute([$assetId, $kind->value]);
    }
}
