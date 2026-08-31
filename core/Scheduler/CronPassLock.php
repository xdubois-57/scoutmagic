<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

/**
 * One cron pass at a time.
 *
 * **Why this appears exactly when the crontab becomes a requirement.** A
 * per-minute crontab starts a pass every sixty seconds whether or not the
 * previous one has finished, and a pass is not bounded by sixty seconds:
 * it migrates the whole declared schema (`Database\DeploymentMigration`,
 * budget 900 s), then runs every overdue task, and a single handler — a
 * full backup, an update install, one LLM call per uncategorised bank
 * movement — can outlast several ticks on its own. Overlapping passes are
 * not merely wasteful: `SchedulerRepository::claimOverdue()` claims each
 * row atomically, so they do not run the same task twice, but they do run
 * DIFFERENT tasks concurrently against a shared-hosting account with an
 * entry-processes ceiling around twenty, and each one re-introspects the
 * schema. The failure mode of a slow queue is a late task; the failure
 * mode of unbounded overlap is an account suspension.
 *
 * **Timeout 0, never anything else** — the same rule as
 * `Maintenance\InstallLock` and `Database\MigrationRunner`. A pass that
 * cannot have the lock must stand down instantly and let the running one
 * finish; a queue of blocked cron processes waiting their turn is exactly
 * the pile-up this exists to prevent, arriving one minute later by another
 * road.
 *
 * **Releasing is belt and braces.** A MySQL/MariaDB advisory lock is held
 * by a CONNECTION, and the server drops it the moment that connection
 * closes — which is what happens when the PHP process ends, however it
 * ends: a fatal, a `kill`, the host recycling the worker. So a pass that
 * dies mid-flight never wedges the next one. `release()` exists so that a
 * pass which ends normally frees the lock at a point the code chose,
 * rather than at whatever point the connection happens to be collected.
 *
 * Never taken on the web path: `public/index.php` runs no scheduler pass
 * at all any more.
 */
final class CronPassLock
{
    /**
     * Deliberately distinct from the schema migration's
     * (`scoutmagic_schema_migration`) and the install's
     * (`scoutmagic_update_install`). Those two serialise a specific piece
     * of work against every caller of it, web included; this one
     * serialises the cron entry point against itself, and a pass must not
     * exclude — or be excluded by — a migration a browser is driving.
     */
    public const NAME = 'scoutmagic_cron_pass';

    /**
     * True when this connection now holds the lock, false when another
     * connection does.
     *
     * A PDO driver with no `GET_LOCK()` (SQLite, in this class's own fast
     * tests) answers true: proceeding without mutual exclusion is right
     * there, since nothing runs two passes at once on one in-memory
     * database, and refusing instead would make every such test a no-op.
     * That is the same choice `Maintenance\InstallLock` makes, for the
     * same reason.
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
            return true;
        }
    }

    public static function release(\PDO $pdo): void
    {
        try {
            // ::query(), not ::exec() — RELEASE_LOCK() returns a result
            // set, and leaving its cursor open breaks the very next query
            // on the connection with "Cannot execute queries while other
            // unbuffered queries are active". MigrationRunner learned this
            // the hard way; same shape, same fix.
            $stmt = $pdo->query("SELECT RELEASE_LOCK('" . self::NAME . "')");
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
        } catch (\PDOException) {
        }
    }
}
