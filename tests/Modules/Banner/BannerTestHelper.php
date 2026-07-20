<?php

declare(strict_types=1);

namespace Tests\Modules\Banner;

/**
 * Creates the banner module's SQLite test tables (mirrors
 * modules/banner/schema.sql) on top of the shared core test database.
 * Same convention as Tests\Modules\Calendar\CalendarTestHelper.
 */
class BannerTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE banners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
