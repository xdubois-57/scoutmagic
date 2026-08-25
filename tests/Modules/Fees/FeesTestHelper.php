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

        $pdo->exec('CREATE TABLE fees_household_tariffs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            household_category TEXT NOT NULL UNIQUE,
            fee_category_id INTEGER,
            amount_cents INTEGER,
            updated_at TEXT,
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id)
        )');

        $pdo->exec('CREATE TABLE fees_ignored_households (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            address_blind_index TEXT NOT NULL,
            composition_hash TEXT NOT NULL,
            reason_encrypted BLOB NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            UNIQUE(scout_year_id, address_blind_index),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
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

        $pdo->exec('CREATE TABLE fees_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            document_number TEXT NOT NULL,
            issue_date TEXT,
            total_cents INTEGER NOT NULL,
            iban TEXT,
            structured_communication TEXT,
            template_number TEXT,
            ignored_row_count INTEGER NOT NULL DEFAULT 0,
            snapshot_id INTEGER,
            imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            imported_by INTEGER,
            finance_file_id INTEGER,
            UNIQUE(scout_year_id, document_number),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (snapshot_id) REFERENCES fees_roster_snapshots(id),
            FOREIGN KEY (imported_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE fees_invoice_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            reference TEXT NOT NULL,
            descriptor TEXT NOT NULL,
            section_code TEXT,
            section_id INTEGER,
            unit_price_cents INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            amount_cents INTEGER NOT NULL,
            nature TEXT NOT NULL,
            line_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES fees_invoices(id),
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE fees_invoice_people (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_line_id INTEGER NOT NULL,
            member_id INTEGER,
            FOREIGN KEY (invoice_line_id) REFERENCES fees_invoice_lines(id),
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');
    }
}
