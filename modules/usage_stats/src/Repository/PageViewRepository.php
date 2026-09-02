<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Repository;

/**
 * The `usage_page_views` table — one counter per (month, route pattern,
 * audience).
 *
 * **One statement per page view, and no more.** `increment()` is on the
 * request path (after the response has been sent, but still inside the
 * same PHP process), so it is a single `INSERT … ON DUPLICATE KEY UPDATE`
 * against a unique key: no SELECT to decide, no transaction, no buffer
 * table. The contention a busy row could theoretically cause is exactly
 * that — theoretical at the scale of one scout unit, where two requests
 * for the same page in the same second essentially never happen. The
 * parade (append then fold by scheduled task) is a real technique and is
 * deliberately NOT built here: it costs a second table, a second task and
 * a window during which the figures are wrong, and it should only ever be
 * built against a measurement.
 *
 * Nothing in this class ever reads or writes an account id, a member id, a
 * session, an address or a user agent. There is no column for any of them.
 */
class PageViewRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Add one to the counter for this (month, pattern, audience), creating
     * the row the first time.
     *
     * `module_id` is refreshed on conflict rather than left alone: a route
     * that moves from core to a module (or between modules) keeps its
     * history under one row instead of splitting it, and the newest answer
     * is the true one.
     */
    public function increment(string $month, string $routePattern, string $moduleId, string $audience): void
    {
        $insert = 'INSERT INTO usage_page_views (month, route_pattern, module_id, audience, view_count)
                   VALUES (?, ?, ?, ?, 1)';

        // SQLite — the in-memory test database — spells the upsert
        // differently from MySQL/MariaDB; same portable pairing as
        // Modules\Camps\Repository\ReviewRepository::save().
        $sql = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? $insert . ' ON CONFLICT(month, route_pattern, audience) DO UPDATE SET
                   view_count = view_count + 1,
                   module_id = excluded.module_id'
            : $insert . ' ON DUPLICATE KEY UPDATE
                   view_count = view_count + 1,
                   module_id = VALUES(module_id)';

        $this->pdo->prepare($sql)->execute([$month, $routePattern, $moduleId, $audience]);
    }

    /**
     * Retention (Modules\UsageStats\Retention): every month strictly older
     * than the cutoff, gone. Returns how many rows went, for the journal.
     */
    public function deleteMonthsBefore(string $cutoffMonth): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM usage_page_views WHERE month < ?');
        $stmt->execute([$cutoffMonth]);

        return $stmt->rowCount();
    }
}
