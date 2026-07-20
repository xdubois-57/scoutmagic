<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar;

/**
 * Creates the calendar module's SQLite test tables (mirrors
 * modules/calendar/schema.sql) on top of the shared core test database.
 * Module-specific tables are not part of Tests\DatabaseTestHelper — each
 * module owns its own schema, same convention as
 * Modules\Trombinoscope\Repository\*Test.
 */
class CalendarTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE calendar_calendars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER UNIQUE,
            name TEXT,
            color TEXT,
            is_default INTEGER NOT NULL DEFAULT 0,
            visibility TEXT NOT NULL DEFAULT \'public\',
            ics_token TEXT UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE calendar_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT,
            start_time TEXT,
            end_time TEXT,
            location TEXT,
            description TEXT,
            sequence INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (calendar_id) REFERENCES calendar_calendars(id),
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE calendar_personal_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL UNIQUE,
            token TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE calendar_unit_feed_token (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
