<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff;

/**
 * Creates the sos_staff module's SQLite test tables (mirrors
 * modules/sos_staff/schema.sql) on top of the shared core test database.
 * Same convention as Tests\Modules\Calendar\CalendarTestHelper.
 */
class SosStaffTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE sos_provider_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL UNIQUE,
            is_active INTEGER NOT NULL DEFAULT 0,
            config_encrypted BLOB NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE sos_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            default_number_member_id INTEGER,
            sections_defaults_seeded INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (default_number_member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE sos_excluded_sections (
            section_id INTEGER PRIMARY KEY,
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE sos_oncall_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            assignment_date TEXT NOT NULL,
            state TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (member_id, assignment_date),
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE sos_calendar_sync (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            calendar_event_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');
    }
}
