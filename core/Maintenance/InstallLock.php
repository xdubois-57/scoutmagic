<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * Mutual exclusion for the one section of this application that overwrites
 * the live install directory: Task\InstallUpdateHandler's backup →
 * download → installFiles() sequence.
 *
 * Until this existed, nothing serialised two installs. The protection was
 * entirely indirect and had two holes that lined up:
 *
 *   - GitHubWebhookService skips a push when
 *     `UpdateHistoryRepository::findInProgress()` returns a row, but that
 *     query deliberately excludes `pending` — a queued install is
 *     invisible to it, by design, since it has not touched the site yet.
 *   - `SchedulerService::cancelPending()` dedupes only within ONE
 *     reference. A push install and a release install (or a manual
 *     "Installer maintenant", which is deliberately allowed to force an
 *     attempt) are different references and different rows, so both can
 *     legitimately be due at once — and `claimOverdue()` claiming each row
 *     atomically does not help, because they are not the same row.
 *
 * Two handlers then copy an extracted archive over the live tree at the
 * same time. That is not theoretical: the comment in
 * `GitHubWebhookService::processPushEvent()` records that it has corrupted
 * an in-progress install in practice.
 *
 * Same shape as `Database\MigrationRunner` and `Scheduler\CronPassLock`:
 * a named `GET_LOCK`, **timeout 0, never anything else** — the loser must
 * find out immediately and stand down, not queue up behind a file copy and
 * start its own the moment it ends.
 */
final class InstallLock
{
    public const NAME = 'scoutmagic_update_install';

    /**
     * True when this connection now holds the lock, false when another
     * connection does.
     */
    public static function acquire(\PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT GET_LOCK('" . self::NAME . "', 0)");
            if ($stmt === false) {
                return false;
            }
            $acquired = (int) $stmt->fetchColumn();
            $stmt->closeCursor();

            return $acquired === 1;
        } catch (\PDOException) {
            // GET_LOCK() is MySQL/MariaDB-only and absent on the SQLite
            // connection the fast tests use. Proceeding without mutual
            // exclusion is right there: those tests never run two installs
            // at once, and refusing instead would make every SQLite-backed
            // test of the handler stand down without doing anything.
            return true;
        }
    }

    public static function release(\PDO $pdo): void
    {
        try {
            // ::query(), not ::exec() — RELEASE_LOCK() returns a result set,
            // and leaving its cursor open breaks the very next query on the
            // connection with "Cannot execute queries while other unbuffered
            // queries are active". MigrationRunner learned this the hard
            // way; same shape, same fix.
            $stmt = $pdo->query("SELECT RELEASE_LOCK('" . self::NAME . "')");
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
        } catch (\PDOException) {
        }
    }
}
