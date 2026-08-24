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
            password_changed_at TEXT,
            sessions_valid_from TEXT,
            is_super_admin INTEGER NOT NULL DEFAULT 0,
            quiet_hours_start TEXT,
            quiet_hours_end TEXT,
            notification_discretion INTEGER NOT NULL DEFAULT 0,
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

        $pdo->exec('CREATE TABLE password_reset_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_blind_index TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used INTEGER NOT NULL DEFAULT 0,
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
            sort_order INTEGER NOT NULL DEFAULT 0,
            logo_file_id INTEGER,
            explanation_url TEXT NOT NULL DEFAULT \'https://lesscouts.be/fr/site-parents/le-parcours-scout\'
        )');

        $pdo->exec('CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            age_branch_id INTEGER NOT NULL,
            desk_code TEXT NOT NULL UNIQUE,
            name TEXT,
            email TEXT,
            is_visible INTEGER NOT NULL DEFAULT 1,
            is_active INTEGER NOT NULL DEFAULT 1,
            color TEXT,
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
            scout_year_offset INTEGER NOT NULL DEFAULT 0,
            handicap_encrypted BLOB,
            supplementary_insurance TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            leaving INTEGER NOT NULL DEFAULT 0,
            leaving_marked_at TEXT,
            leaving_comment_encrypted BLOB,
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
            address_normalized_blind_index TEXT,
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

        $pdo->exec('CREATE TABLE member_section_periods (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
        )');

        $pdo->exec('CREATE TABLE section_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            compression_status TEXT NOT NULL DEFAULT "pending",
            size_before_bytes INTEGER,
            size_after_bytes INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
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
            owner_member_id INTEGER,
            owner_type TEXT,
            owner_id INTEGER,
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
            ip_blind_index TEXT,
            attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE user_account_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_account_id),
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
        )');

        $pdo->exec('CREATE TABLE member_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            UNIQUE(member_id, scout_year_id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
        )');

        $pdo->exec('CREATE TABLE section_staff_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            UNIQUE(section_id, scout_year_id),
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
        )');

        $pdo->exec('CREATE TABLE scout_year_transition_steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            step_key TEXT NOT NULL,
            done_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_by INTEGER,
            UNIQUE(scout_year_id, step_key),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (done_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE member_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            file_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
        )');

        $pdo->exec('CREATE TABLE member_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            email_encrypted BLOB NOT NULL,
            email_blind_index TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "manual",
            status TEXT NOT NULL DEFAULT "pending",
            confirmation_token_hash TEXT,
            confirmation_expires_at TEXT,
            last_confirmation_sent_at TEXT,
            confirmed_at TEXT,
            deactivated_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (member_id, email_blind_index),
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            is_default INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            referent_section_id INTEGER NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (referent_section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE member_badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_year_id INTEGER NOT NULL,
            badge_id INTEGER NOT NULL,
            assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            assigned_by INTEGER,
            UNIQUE(member_year_id, badge_id),
            FOREIGN KEY (member_year_id) REFERENCES member_years(id),
            FOREIGN KEY (badge_id) REFERENCES badges(id)
        )');

        $pdo->exec('CREATE TABLE module_registry (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            module_id TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 0,
            installed_version TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            enabled_at TEXT,
            enabled_by INTEGER,
            FOREIGN KEY (enabled_by) REFERENCES user_accounts(id) ON DELETE SET NULL
        )');

        $pdo->exec('CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            module_id TEXT,
            setting_key TEXT NOT NULL,
            setting_value TEXT,
            default_value TEXT,
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
            requested_by_user_account_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_at TEXT
        )');

        $pdo->exec('CREATE TABLE push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            endpoint BLOB NOT NULL,
            endpoint_blind_index CHAR(64) NOT NULL,
            auth_key BLOB NOT NULL,
            p256dh_key BLOB NOT NULL,
            device_label TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_success_at TEXT,
            failure_count INTEGER NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            member_id INTEGER,
            type_id TEXT NOT NULL,
            title BLOB NOT NULL,
            body BLOB NOT NULL,
            url TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at TEXT,
            email_sent_at TEXT
        )');

        $pdo->exec('CREATE TABLE notification_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            type_id TEXT NOT NULL,
            in_app INTEGER,
            push INTEGER,
            email INTEGER,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_account_id, type_id)
        )');

        $pdo->exec('CREATE TABLE backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            file_id INTEGER,
            db_dump_file_id INTEGER,
            status TEXT NOT NULL DEFAULT \'pending\',
            requested_by INTEGER,
            error_message TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )');

        $pdo->exec('CREATE TABLE update_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version_from TEXT NOT NULL,
            version_to TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            dependencies_changed INTEGER NOT NULL DEFAULT 0,
            error_message TEXT,
            backup_id INTEGER,
            requested_by INTEGER,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )');

        $pdo->exec('CREATE TABLE short_urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            target_url_encrypted BLOB NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
        )');

        $pdo->exec('CREATE TABLE human_check_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash TEXT NOT NULL,
            form_key TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        // Core\Audit's per-entity change history (schema/core.sql:
        // entity_changes). The three value columns are BLOB here as they
        // are in production — the repository writes ciphertext into them.
        $pdo->exec('CREATE TABLE entity_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            field_key TEXT NOT NULL,
            from_value BLOB,
            to_value BLOB,
            summary BLOB,
            source TEXT NOT NULL,
            source_reference TEXT,
            actor_user_account_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        return $pdo;
    }
}
