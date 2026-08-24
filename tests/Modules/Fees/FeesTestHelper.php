<?php

declare(strict_types=1);

namespace Tests\Modules\Fees;

/**
 * SQLite equivalents of `modules/fees/schema.sql`, same shape and same
 * pattern as Tests\Modules\Registration\RegistrationTestHelper.
 */
class FeesTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE fees_roster_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            taken_at TEXT NOT NULL,
            member_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
        )');

        $pdo->exec('CREATE TABLE fees_roster_snapshot_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            fee_category_id INTEGER,
            section_id INTEGER,
            function_role TEXT,
            formation_level TEXT,
            leaving INTEGER NOT NULL DEFAULT 0,
            UNIQUE(snapshot_id, member_id),
            FOREIGN KEY (snapshot_id) REFERENCES fees_roster_snapshots(id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');
    }
}
