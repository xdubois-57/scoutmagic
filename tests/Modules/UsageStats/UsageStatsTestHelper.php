<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats;

/**
 * The usage_stats module's table as SQLite, mirroring
 * modules/usage_stats/schema.sql — same convention as
 * Tests\Modules\Camps\CampsTestHelper.
 *
 * The UNIQUE key is what the repository's upsert relies on, so it is
 * reproduced here rather than left out: without it `ON CONFLICT` has
 * nothing to conflict with and the test would insert duplicates while
 * production increments.
 */
class UsageStatsTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE usage_page_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month TEXT NOT NULL,
            route_pattern TEXT NOT NULL,
            module_id TEXT NOT NULL,
            audience TEXT NOT NULL,
            view_count INTEGER NOT NULL DEFAULT 0,
            UNIQUE (month, route_pattern, audience)
        )');
    }
}
