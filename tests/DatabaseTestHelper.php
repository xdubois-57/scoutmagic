<?php

declare(strict_types=1);

namespace Tests;

class DatabaseTestHelper
{
    /**
     * Create an in-memory SQLite database with all core tables.
     */
    public static function createTestDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE scout_years (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            is_current INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            desk_id TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE user_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_encrypted BLOB NOT NULL,
            email_blind_index TEXT NOT NULL UNIQUE,
            first_name_encrypted BLOB,
            last_name_encrypted BLOB,
            password_hash TEXT,
            is_super_admin INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at TEXT
        )');

        $pdo->exec('CREATE TABLE magic_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_blind_index TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER NOT NULL DEFAULT 0,
            confirmed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE functions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            desk_code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT \'identified\',
            confirmed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE fee_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            desk_code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE age_branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            desk_code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            age_branch_id INTEGER NOT NULL,
            desk_code TEXT NOT NULL UNIQUE,
            name TEXT,
            email TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
        )');

        $pdo->exec('CREATE TABLE member_years (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            first_name_encrypted BLOB NOT NULL,
            last_name_encrypted BLOB NOT NULL,
            gender_encrypted BLOB,
            birth_date_encrypted BLOB,
            phone_encrypted BLOB,
            mobile_encrypted BLOB,
            email_encrypted BLOB,
            email_blind_index TEXT,
            totem_encrypted BLOB,
            quali_encrypted BLOB,
            patrol_encrypted BLOB,
            formation_level TEXT,
            federation_mail_consent INTEGER NOT NULL DEFAULT 0,
            unit_mail_consent INTEGER NOT NULL DEFAULT 0,
            fee_category_id INTEGER,
            unit_code TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(member_id, scout_year_id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id)
        )');

        $pdo->exec('CREATE TABLE member_addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_year_id INTEGER NOT NULL,
            address_type TEXT NOT NULL,
            street_encrypted BLOB,
            number_encrypted BLOB,
            box_encrypted BLOB,
            complement_encrypted BLOB,
            postal_code_encrypted BLOB,
            city_encrypted BLOB,
            country_encrypted BLOB,
            FOREIGN KEY (member_year_id) REFERENCES member_years(id)
        )');

        $pdo->exec('CREATE TABLE member_functions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_year_id INTEGER NOT NULL,
            function_id INTEGER NOT NULL,
            section_id INTEGER,
            age_branch_id INTEGER,
            start_date TEXT,
            end_date TEXT,
            mandate_end TEXT,
            is_main_function INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (member_year_id) REFERENCES member_years(id),
            FOREIGN KEY (function_id) REFERENCES functions(id),
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
        )');

        $pdo->exec('CREATE TABLE import_journal (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            user_account_id INTEGER,
            line_count INTEGER NOT NULL,
            member_count INTEGER NOT NULL,
            new_functions_count INTEGER NOT NULL DEFAULT 0,
            imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            modified_at TEXT,
            modified_by INTEGER
        )');

        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relative_path TEXT NOT NULL UNIQUE,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            module_id TEXT,
            role_min TEXT NOT NULL DEFAULT \'public\',
            custom_resolver TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');

        $pdo->exec('CREATE TABLE webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            credential_id BLOB NOT NULL UNIQUE,
            public_key BLOB NOT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0,
            device_label TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TEXT,
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_blind_index TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE module_registry (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            module_id TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 0,
            installed_version TEXT NOT NULL,
            enabled_at TEXT,
            enabled_by INTEGER,
            FOREIGN KEY (enabled_by) REFERENCES user_accounts(id) ON DELETE SET NULL
        )');

        $pdo->exec('CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            module_id TEXT,
            setting_key TEXT NOT NULL,
            setting_value TEXT,
            setting_type TEXT NOT NULL DEFAULT \'text\',
            label TEXT NOT NULL,
            description TEXT NOT NULL,
            validation_regex TEXT,
            select_options TEXT,
            editable INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE UNIQUE INDEX idx_module_key ON settings (module_id, setting_key)');

        $pdo->exec('CREATE TABLE event_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            logged_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_account_id INTEGER,
            ip_address TEXT,
            category TEXT NOT NULL,
            event_type TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT \'info\',
            description TEXT NOT NULL,
            context TEXT,
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
        )');

        $pdo->exec('CREATE TABLE scheduled_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            module_id TEXT NOT NULL,
            task_key TEXT NOT NULL,
            reference TEXT,
            payload TEXT,
            run_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_at TEXT
        )');

        return $pdo;
    }
}
