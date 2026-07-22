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

        $pdo->exec('CREATE TABLE mass_mail_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            body_html TEXT NOT NULL,
            section_id INTEGER NOT NULL,
            list_type TEXT NOT NULL,
            list_id INTEGER,
            list_section_id INTEGER,
            status TEXT NOT NULL DEFAULT \'draft\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at TEXT,
            created_by INTEGER,
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id),
            FOREIGN KEY (list_section_id) REFERENCES sections(id),
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
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            email_address_encrypted BLOB,
            status TEXT NOT NULL DEFAULT \'pending\',
            error_message TEXT,
            sent_at TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
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
