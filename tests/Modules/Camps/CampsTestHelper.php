<?php

declare(strict_types=1);

namespace Tests\Modules\Camps;

/**
 * The camps module's tables as SQLite, mirroring modules/camps/schema.sql.
 * ENUMs become TEXT (SQLite has none) and the module's own service is what
 * enforces their values anyway.
 */
class CampsTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE camp_places (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            address TEXT NULL,
            postal_code TEXT NULL,
            city TEXT NULL,
            country TEXT NULL,
            website_url TEXT NULL,
            is_archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE camp_camps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            place_id INTEGER NOT NULL,
            stay_type TEXT NOT NULL DEFAULT "grand_camp",
            start_date TEXT NULL,
            end_date TEXT NULL,
            year_only INTEGER NULL,
            status TEXT NOT NULL DEFAULT "to_confirm",
            price_cents INTEGER NULL,
            participant_count INTEGER NULL,
            booked_by_member_id INTEGER NULL,
            booked_by_name BLOB NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE camp_camp_sections (
            camp_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            PRIMARY KEY (camp_id, section_id)
        )');
    }
}
