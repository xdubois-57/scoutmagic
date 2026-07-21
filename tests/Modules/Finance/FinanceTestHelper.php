<?php

declare(strict_types=1);

namespace Tests\Modules\Finance;

/**
 * Creates the finance module's SQLite test tables (mirrors
 * modules/finance/schema.sql) on top of the shared core test database —
 * same convention as Tests\Modules\Calendar\CalendarTestHelper.
 */
class FinanceTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        // No finance_fiscal_years table — a fiscal year is a scout year
        // (the shared core `scout_years` table, already created by
        // Tests\DatabaseTestHelper::createTestDatabase()).

        $pdo->exec('CREATE TABLE finance_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            account_type TEXT NOT NULL,
            section_id INTEGER,
            iban BLOB,
            iban_blind_index TEXT,
            holder_name BLOB,
            role_min_view TEXT NOT NULL DEFAULT \'intendant\',
            status TEXT NOT NULL DEFAULT \'draft\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE finance_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE finance_category_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            condition_type TEXT NOT NULL,
            condition_value TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES finance_categories(id)
        )');

        $pdo->exec('CREATE TABLE finance_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            fiscal_year_id INTEGER NOT NULL,
            bank_reference TEXT,
            transaction_date TEXT NOT NULL,
            label BLOB NOT NULL,
            amount REAL NOT NULL,
            category_id INTEGER,
            comment TEXT,
            source TEXT NOT NULL,
            imported_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(account_id, bank_reference),
            FOREIGN KEY (account_id) REFERENCES finance_accounts(id),
            FOREIGN KEY (fiscal_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (category_id) REFERENCES finance_categories(id)
        )');

        $pdo->exec('CREATE TABLE finance_balance_checkpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            checkpoint_date TEXT NOT NULL,
            balance REAL NOT NULL,
            source TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES finance_accounts(id)
        )');

        $pdo->exec('CREATE TABLE finance_statement_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            bank_code TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            lines_total INTEGER NOT NULL DEFAULT 0,
            lines_new INTEGER NOT NULL DEFAULT 0,
            lines_duplicate INTEGER NOT NULL DEFAULT 0,
            imported_by INTEGER,
            imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES finance_accounts(id)
        )');

        $pdo->exec('CREATE TABLE finance_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER,
            file_id INTEGER NOT NULL,
            mime_type TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            suggested_amount REAL,
            suggested_date TEXT,
            suggested_label TEXT,
            suggested_source TEXT,
            status TEXT NOT NULL DEFAULT \'active\',
            parent_attachment_id INTEGER,
            uploaded_by INTEGER,
            uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES finance_accounts(id),
            FOREIGN KEY (file_id) REFERENCES files(id),
            FOREIGN KEY (parent_attachment_id) REFERENCES finance_attachments(id)
        )');

        $pdo->exec('CREATE TABLE finance_transaction_attachments (
            transaction_id INTEGER NOT NULL,
            attachment_id INTEGER NOT NULL,
            PRIMARY KEY (transaction_id, attachment_id),
            FOREIGN KEY (transaction_id) REFERENCES finance_transactions(id),
            FOREIGN KEY (attachment_id) REFERENCES finance_attachments(id)
        )');
    }

    /**
     * A fiscal year is a scout year — tests that need one seed
     * `scout_years` directly (a raw insert, not
     * Core\Config\ScoutYearService::ensureYear(), so tests can use
     * arbitrary historical/future date ranges under any label).
     */
    public static function createScoutYear(\PDO $pdo, string $label, string $startDate, string $endDate, bool $isCurrent = false): int
    {
        $stmt = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, ?)');
        $stmt->execute([$label, $startDate, $endDate, $isCurrent ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }
}
