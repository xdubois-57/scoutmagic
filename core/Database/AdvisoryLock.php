<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * A named MySQL/MariaDB advisory lock — the mechanism, once.
 *
 * Three places serialise work this way, and each had its own copy of these
 * twenty lines: `Maintenance\InstallLock` (nothing overwrites the live
 * tree twice at once), `MigrationRunner` (one process diffs and applies
 * the schema), and `Scheduler\CronPassLock` (one cron pass at a time).
 * Three copies of a concurrency primitive is three places for it to drift,
 * and the copies had already begun to: two spelled the SQLite fallback
 * differently and one had lost the `closeCursor()` rationale.
 *
 * What each caller keeps is what is actually specific to it — its lock
 * NAME and the docblock explaining what it protects and why. What lives
 * here is the mechanism and its three non-obvious rules:
 *
 * **Timeout 0, never anything else.** Every caller here wants the loser to
 * find out immediately and stand down. A blocking wait would pile
 * processes up behind the winner — on shared hosting, against an
 * entry-processes ceiling around twenty, that is the failure this is meant
 * to prevent, arriving by another road.
 *
 * **A driver without `GET_LOCK()` gets `true`, not `false`.** The function
 * is MySQL/MariaDB-only and absent from the SQLite connections the fast
 * tests use. Proceeding without mutual exclusion is right there: those
 * tests never run two of anything at once against one in-memory database,
 * and refusing instead would turn every such test into a silent no-op that
 * still passes — the worst of both.
 *
 * **`::query()`, never `::exec()`, to release.** `RELEASE_LOCK()` returns a
 * result set, and leaving its cursor open breaks the very next query on
 * the connection with "Cannot execute queries while other unbuffered
 * queries are active". MigrationRunner learned that the hard way.
 */
final class AdvisoryLock
{
    /**
     * True when this connection now holds $name, false when another
     * connection does.
     */
    public static function acquire(\PDO $pdo, string $name): bool
    {
        try {
            $stmt = $pdo->query("SELECT GET_LOCK('" . $name . "', 0)");
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

    /**
     * Best-effort: the server drops every advisory lock a connection holds
     * the moment that connection closes, so a process that dies without
     * reaching here never wedges the next one. Releasing explicitly only
     * frees the lock at a point the code chose rather than whenever the
     * connection happens to be collected.
     */
    public static function release(\PDO $pdo, string $name): void
    {
        try {
            $stmt = $pdo->query("SELECT RELEASE_LOCK('" . $name . "')");
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
        } catch (\PDOException) {
        }
    }
}
