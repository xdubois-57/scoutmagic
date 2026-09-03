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
     * Every month the table holds at least one counter for, newest first —
     * the picker's options, and nothing invented: a month with no row at
     * all is a month there is nothing to show for.
     *
     * @return list<string>
     */
    public function months(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT month FROM usage_page_views ORDER BY month DESC');

        return $stmt === false ? [] : array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function totalViews(string $month): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(view_count), 0) FROM usage_page_views WHERE month = ?');
        $stmt->execute([$month]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Total views per month over a window, as month => count. Months with
     * no row are absent; the caller fills them with zero, because a month
     * that happened and had no traffic is a real zero while a month before
     * counting started is not.
     *
     * @return array<string, int>
     */
    public function viewsPerMonth(string $fromMonth, string $toMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT month, SUM(view_count) AS views
             FROM usage_page_views
             WHERE month >= ? AND month <= ?
             GROUP BY month
             ORDER BY month'
        );
        $stmt->execute([$fromMonth, $toMonth]);

        $perMonth = [];
        foreach ($stmt->fetchAll() as $row) {
            $perMonth[(string) $row['month']] = (int) $row['views'];
        }

        return $perMonth;
    }

    /**
     * Views per audience for one month, as audience value => count.
     *
     * @return array<string, int>
     */
    public function viewsPerAudience(string $month): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT audience, SUM(view_count) AS views
             FROM usage_page_views
             WHERE month = ?
             GROUP BY audience'
        );
        $stmt->execute([$month]);

        $perAudience = [];
        foreach ($stmt->fetchAll() as $row) {
            $perAudience[(string) $row['audience']] = (int) $row['views'];
        }

        return $perAudience;
    }

    /**
     * Views per module over a window, as module id => count. `core` is a
     * module id here like any other (Service\PageViewRecorder::CORE_MODULE_ID).
     *
     * @return array<string, int>
     */
    public function viewsPerModule(string $fromMonth, string $toMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT module_id, SUM(view_count) AS views
             FROM usage_page_views
             WHERE month >= ? AND month <= ?
             GROUP BY module_id'
        );
        $stmt->execute([$fromMonth, $toMonth]);

        $perModule = [];
        foreach ($stmt->fetchAll() as $row) {
            $perModule[(string) $row['module_id']] = (int) $row['views'];
        }

        return $perModule;
    }

    /**
     * One row per page for a month, most opened first — optionally
     * narrowed to one audience.
     *
     * The audiences of one page are summed rather than listed: the screen
     * shows how much a page is opened, and the « qui consulte » question
     * is answered by the filter rather than by a column per public.
     *
     * @return list<array{route_pattern: string, module_id: string, views: int}>
     */
    public function pages(string $month, ?string $audience = null, ?int $limit = null): array
    {
        $sql = 'SELECT route_pattern, MIN(module_id) AS module_id, SUM(view_count) AS views
                FROM usage_page_views
                WHERE month = ?';
        $parameters = [$month];

        if ($audience !== null) {
            $sql .= ' AND audience = ?';
            $parameters[] = $audience;
        }

        $sql .= ' GROUP BY route_pattern ORDER BY views DESC, route_pattern';

        if ($limit !== null) {
            // Interpolated after an integer cast, never bound: LIMIT is
            // not a bindable position on every driver, and the cast is
            // what makes the interpolation safe.
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        $pages = [];
        foreach ($stmt->fetchAll() as $row) {
            $pages[] = [
                'route_pattern' => (string) $row['route_pattern'],
                'module_id' => (string) $row['module_id'],
                'views' => (int) $row['views'],
            ];
        }

        return $pages;
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
