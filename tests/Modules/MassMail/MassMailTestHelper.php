<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

/**
 * Creates the mass_mail module's SQLite test tables (mirrors
 * modules/mass_mail/schema.sql) on top of the shared core test database —
 * same convention as Tests\Modules\Calendar\CalendarTestHelper.
 */
class MassMailTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE mass_mail_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_list_functions (
            list_id INTEGER NOT NULL,
            function_id INTEGER NOT NULL,
            PRIMARY KEY (list_id, function_id),
            FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id),
            FOREIGN KEY (function_id) REFERENCES functions(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_list_sections (
            list_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            PRIMARY KEY (list_id, section_id),
            FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id),
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_audiences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_filename TEXT NOT NULL,
            sheet_name TEXT NOT NULL,
            columns_json TEXT NOT NULL,
            row_count INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_audience_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            audience_id INTEGER NOT NULL,
            row_index INTEGER NOT NULL,
            member_id INTEGER,
            email_encrypted BLOB,
            data_encrypted BLOB NOT NULL,
            FOREIGN KEY (audience_id) REFERENCES mass_mail_audiences(id),
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_suppressed_addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE mass_mail_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            body_html TEXT NOT NULL,
            section_id INTEGER NOT NULL,
            list_type TEXT NOT NULL,
            list_id INTEGER,
            list_section_id INTEGER,
            audience_id INTEGER,
            status TEXT NOT NULL DEFAULT \'draft\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at TEXT,
            created_by INTEGER,
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id),
            FOREIGN KEY (list_section_id) REFERENCES sections(id),
            FOREIGN KEY (audience_id) REFERENCES mass_mail_audiences(id),
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_email_scout_years (
            email_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            PRIMARY KEY (email_id, scout_year_id),
            FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_id INTEGER NOT NULL,
            member_id INTEGER,
            scout_year_id INTEGER,
            audience_row_id INTEGER,
            email_address_encrypted BLOB,
            member_email_id INTEGER,
            unsubscribe_token_hash TEXT,
            status TEXT NOT NULL DEFAULT \'pending\',
            error_message TEXT,
            sent_at TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (member_email_id) REFERENCES member_emails(id),
            FOREIGN KEY (audience_row_id) REFERENCES mass_mail_audience_rows(id)
        )');

        $pdo->exec('CREATE TABLE mass_mail_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
        )');
    }
}
