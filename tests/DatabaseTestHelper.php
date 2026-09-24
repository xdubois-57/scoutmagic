<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

class DatabaseTestHelper
{
    /**
     * A refused database connection: skip it on a laptop, REPORT it
     * anywhere a server was promised.
     *
     * `TEST_DB_*` being set is that promise. A developer's machine with
     * nothing on 3306 has nothing to prove and skipping is right; a runner
     * whose credentials are wrong, whose service container died, or whose
     * variables were dropped from the workflow has a broken run, and a
     * skip there is a green result that proves nothing — the failure mode
     * `docs/quality-pipeline.md` keeps a list of.
     *
     * **Measured, not feared.** Pointing `TEST_DB_*` at nothing on
     * `e70bac2` moved the suite from `Skipped: 3` to `Skipped: 154` while
     * still exiting 0: a hundred and fifty-one tests stopped being checked
     * and only the twenty-eight belonging to the three classes that
     * already made this distinction said so (issue #393). What those
     * classes cover is exactly what SQLite cannot show — declared-schema
     * migration, default-value introspection where the two engines
     * disagree, the install and cron locks, backup and restore, the
     * portable package.
     *
     * `CI` counts as a promise too: a continuous-integration run that
     * cannot reach a database is a broken runner whether or not anybody
     * remembered to export `TEST_DB_HOST`. The `database-mariadb` job
     * exists *because* production runs MariaDB, and a job that quietly
     * degrades to a second SQLite pass is the one result that looks
     * exactly like the one it was built to differ from.
     *
     * Throws rather than returning a verdict so a caller cannot forget the
     * other half: every call site is one line, and the line either skips
     * or ends the test.
     */
    public static function skipOnlyWhenNoServerWasPromised(string $reason): never
    {
        // Falsy, not `=== false`, and the difference is not cosmetic: the
        // twenty-seven classes build their host as
        // `getenv('TEST_DB_HOST') ?: '127.0.0.1'`, so an exported-but-empty
        // TEST_DB_HOST sends them to the default and they connect. Reading
        // the empty string as a promise would make this throw exactly where
        // they are green — and would disagree with
        // Tests\Architecture\DatabaseBackedTestsReallyRunTest, whose own
        // guard says so in as many words after the same bug was fixed there
        // (PR #394's review, docs/chantiers/CHANTIER-revue-des-tests.md).
        if ((getenv('TEST_DB_HOST') ?: '') === '' && getenv('CI') === false) {
            TestCase::markTestSkipped($reason);
        }

        throw new \RuntimeException(
            'A database was promised (TEST_DB_HOST or CI is set) and could not be reached, so this '
            . 'class proved nothing and says so rather than skipping: ' . $reason
        );
    }

    /**
     * The `(label, start_date, end_date)` of a scout year, relative to the
     * one the application considers current RIGHT NOW.
     *
     * Fixtures used to write that triple out by hand — `'2025-2026',
     * '2025-09-01', '2026-08-31'` appears 127 times across this suite.
     * That is correct for eleven months and wrong on the twelfth: a scout
     * year turns over on 1 September (Core\Config\ScoutYearService::
     * labelForDate()), and tests/bootstrap.php puts PHPUnit on Belgian
     * time, so at 00:00 Brussels on 1 September the application starts
     * resolving the NEXT year while the fixture still names the previous
     * one. getCurrentYear() then finds no row, ensureYear() creates an
     * empty one, and every member, function and section period the test
     * seeded hangs off an id nothing asks for any more.
     *
     * That is not a hypothetical: on 2026-09-01 it took 80 tests across
     * 22 classes red at once, on a suite green two hours earlier, with
     * failures that name a missing member rather than a date.
     *
     * `$offsetYears` reaches the neighbours a test needs — -1 for last
     * year, +1 for next — so a fixture can still say « the year before
     * this one » without saying which calendar year that is.
     *
     * A test that genuinely means a FIXED year (a historical import, a
     * date printed in an expected string) should keep writing it out:
     * this is for the ones that mean « the current year » and only ever
     * spelled it as a number.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function scoutYear(int $offsetYears = 0): array
    {
        $label = \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable());
        $startYear = ((int) explode('-', $label)[0]) + $offsetYears;

        return [
            sprintf('%d-%d', $startYear, $startYear + 1),
            sprintf('%d-09-01', $startYear),
            sprintf('%d-08-31', $startYear + 1),
        ];
    }

    /**
     * Create an in-memory SQLite database with all core tables.
     */
    /**
     * @param \PDO|null $pdo an already-opened SQLite connection to build the schema on —
     *                       a Core\Database\InstrumentedPdo when a test counts statements
     */
    public static function createTestDatabase(?\PDO $pdo = null): \PDO
    {
        $pdo ??= new \PDO('sqlite::memory:');
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
            merged_into_member_id INTEGER,
            merged_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (merged_into_member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE member_desk_id_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            desk_id TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE member_duplicate_candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kept_member_id INTEGER NOT NULL,
            duplicate_member_id INTEGER NOT NULL,
            same_address INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'pending\',
            detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at TEXT,
            decided_by INTEGER,
            UNIQUE(kept_member_id, duplicate_member_id),
            FOREIGN KEY (kept_member_id) REFERENCES members(id),
            FOREIGN KEY (duplicate_member_id) REFERENCES members(id)
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
            is_active INTEGER NOT NULL DEFAULT 1,
            quiet_hours_start TEXT,
            quiet_hours_end TEXT,
            notification_discretion INTEGER NOT NULL DEFAULT 0,
            help_discovery_snoozed_until TEXT,
            push_invitation_dismissed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at TEXT
        )');

        $pdo->exec('CREATE TABLE help_topics_seen (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            topic_id TEXT NOT NULL,
            seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_account_id, topic_id),
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE
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

        // Dated staff notes about a PERSON (Core\Member\MemberNoteService).
        // Keyed on members.id, never on a member_year: a note outlives the
        // scout year that saw it written. ON DELETE CASCADE on the member,
        // SET NULL on the author — losing the author must not lose the
        // note.
        $pdo->exec('CREATE TABLE member_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            body BLOB NOT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
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
            file_id INTEGER,
            diff_json TEXT,
            imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (user_account_id) REFERENCES user_accounts(id),
            FOREIGN KEY (file_id) REFERENCES files(id)
        )');

        // Moved out of modules/fees/ with the roster snapshot itself: a
        // frozen roster is a fact about members, produced by the core's
        // own Desk import. The `fees_` prefix is where the tables were
        // born, not who owns them — see schema/core.sql.
        $pdo->exec('CREATE TABLE fees_roster_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            import_journal_id INTEGER,
            taken_at TEXT NOT NULL,
            member_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (import_journal_id) REFERENCES import_journal(id)
        )');

        $pdo->exec('CREATE TABLE fees_roster_snapshot_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            fee_category_id INTEGER,
            section_id INTEGER,
            function_role TEXT,
            function_id INTEGER,
            formation_level TEXT,
            leaving INTEGER NOT NULL DEFAULT 0,
            UNIQUE(snapshot_id, member_id),
            FOREIGN KEY (snapshot_id) REFERENCES fees_roster_snapshots(id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
            FOREIGN KEY (section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            text_page_id INTEGER NULL REFERENCES text_pages(id) ON DELETE CASCADE,
            modified_at TEXT,
            modified_by INTEGER
        )');

        $pdo->exec('CREATE TABLE text_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            menu_label TEXT NOT NULL,
            title TEXT NOT NULL,
            menu_id TEXT NOT NULL,
            menu_group TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT
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

        $pdo->exec('CREATE TABLE device_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            secret_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_sync_at TEXT,
            revoked_at TEXT,
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
            executed_at TEXT,
            claimed_at TEXT
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
            archive_sha256 TEXT,
            db_dump_sha256 TEXT,
            integrity_status TEXT NOT NULL DEFAULT \'unknown\',
            integrity_checked_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )');

        $pdo->exec('CREATE TABLE operational_alerts (
            alert_key TEXT PRIMARY KEY,
            state TEXT NOT NULL DEFAULT \'armed\',
            triggered_at TEXT,
            last_notified_at TEXT,
            last_reading TEXT
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
            progress_at TEXT,
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

        // The replay guard of the background e-mail handlers (schema/
        // core.sql: sent_email_claims). The UNIQUE key is the whole
        // mechanism — Core\Mail\SentEmailClaimRepository::claim() reads
        // a lost race off it — so it is declared here too.
        $pdo->exec('CREATE TABLE sent_email_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            recipient_key TEXT NOT NULL,
            claimed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (scope, recipient_key)
        )');

        // The outbound transport's three tables (schema/core.sql:
        // mail_providers, mail_lane_entries, mail_send_counters,
        // ARCHITECTURE.md §8.106). No connection value is declared here
        // because none is stored: host, port, user and password live in
        // secrets.enc, read through Core\Mail\Transport\
        // ProviderConnections.
        $pdo->exec('CREATE TABLE mail_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            secret_prefix TEXT NOT NULL,
            daily_quota INTEGER,
            batch_size INTEGER NOT NULL DEFAULT 50,
            batch_interval_minutes INTEGER NOT NULL DEFAULT 10,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (secret_prefix)
        )');

        $pdo->exec('CREATE TABLE mail_lane_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lane TEXT NOT NULL,
            provider_id INTEGER NOT NULL DEFAULT 0,
            position INTEGER NOT NULL DEFAULT 0,
            is_enabled INTEGER NOT NULL DEFAULT 1,
            UNIQUE (lane, provider_id)
        )');

        $pdo->exec('CREATE TABLE mail_send_counters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL DEFAULT 0,
            count_date TEXT NOT NULL,
            lane TEXT NOT NULL,
            sent_count INTEGER NOT NULL DEFAULT 0,
            UNIQUE (provider_id, count_date, lane)
        )');

        // The circuit breaker's memory and the deferral queue (D15, D9,
        // D18). `payload_encrypted` is a BLOB in MySQL and stays TEXT
        // here only because SQLite has no distinct binary type — what it
        // holds is ciphertext either way.
        $pdo->exec('CREATE TABLE mail_provider_health (
            provider_id INTEGER PRIMARY KEY,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            opened_at TEXT,
            opened_until TEXT,
            open_count INTEGER NOT NULL DEFAULT 0,
            last_reason TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE mail_deferred_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lane TEXT NOT NULL,
            purpose TEXT NOT NULL,
            payload_encrypted TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_reason TEXT NOT NULL DEFAULT \'\',
            next_attempt_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            settled_at TEXT
        )');

        // The return round trip (roadmap IT-03). UNIQUE on the blind
        // index here as in MySQL: one row per address is what makes
        // « the operator changed the address » reset the state with no
        // reset code to forget to call.
        $pdo->exec('CREATE TABLE mail_return_probes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            address_blind_index TEXT NOT NULL UNIQUE,
            address_encrypted TEXT NOT NULL,
            correlation_key TEXT NOT NULL,
            sent_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            received_at TEXT,
            mailbox_id INTEGER
        )');

        $pdo->exec('CREATE TABLE mail_probes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            destination_encrypted TEXT NOT NULL,
            provider_id INTEGER,
            provider_name TEXT NOT NULL,
            lane TEXT NOT NULL,
            sent_at TEXT NOT NULL,
            verdict TEXT,
            verdict_at TEXT
        )');

        $pdo->exec('CREATE TABLE mail_bounce_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_encrypted TEXT NOT NULL,
            email_blind_index TEXT NOT NULL UNIQUE,
            category TEXT NOT NULL,
            severity TEXT NOT NULL,
            status_code TEXT NOT NULL,
            failures INTEGER NOT NULL DEFAULT 0,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            blocked_at TEXT,
            notified_code TEXT,
            settling_since TEXT
        )');

        $pdo->exec('CREATE TABLE mail_send_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_blind_index TEXT NOT NULL UNIQUE,
            last_send_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE mail_dmarc_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organisation TEXT NOT NULL,
            report_id TEXT NOT NULL,
            domain TEXT NOT NULL,
            period_begin TEXT NOT NULL,
            period_end TEXT NOT NULL,
            policy TEXT NOT NULL,
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (organisation, report_id)
        )');

        $pdo->exec('CREATE TABLE mail_dmarc_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dmarc_report_id INTEGER NOT NULL,
            source_ip TEXT NOT NULL,
            message_count INTEGER NOT NULL,
            authenticated_count INTEGER NOT NULL,
            disposition TEXT NOT NULL,
            FOREIGN KEY (dmarc_report_id) REFERENCES mail_dmarc_reports(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE mail_seed_copies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_reference TEXT NOT NULL,
            seed_address_encrypted BLOB NOT NULL,
            seed_address_blind_index TEXT NOT NULL,
            provider TEXT NOT NULL,
            sent_at TEXT NOT NULL,
            verdict TEXT NOT NULL DEFAULT \'pending\',
            landed_folder TEXT NULL,
            recorded_at TEXT NULL,
            UNIQUE (run_reference, seed_address_blind_index)
        )');

        $pdo->exec('CREATE TABLE human_check_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash TEXT NOT NULL,
            form_key TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        // The help assistant's quota and answer cache (schema/core.sql:
        // help_assistant_rate_limits, help_assistant_cache). Neither ever
        // holds the question itself — a fingerprint on one side, a count
        // on the other (SECURITY.md §11).
        $pdo->exec('CREATE TABLE help_assistant_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_account_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE help_assistant_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT NOT NULL UNIQUE,
            answer TEXT NOT NULL,
            topic_ids TEXT NOT NULL,
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

        // Customised automatic e-mails (schema/core.sql:
        // email_template_overrides). No row means no customisation, so an
        // empty table is the ordinary state and every renderer test that
        // does not write one exercises the shipped-template path.
        $pdo->exec('CREATE TABLE email_template_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id TEXT NOT NULL UNIQUE,
            subject TEXT NOT NULL,
            body_html TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER,
            FOREIGN KEY (updated_by) REFERENCES user_accounts(id) ON DELETE SET NULL
        )');

        // Declared storage destinations (schema/core.sql:
        // storage_locations). In the core helper rather than the gallery's
        // because the gallery is only one consumer: anything that writes
        // files can stand on one of these.
        $pdo->exec('CREATE TABLE storage_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            label TEXT NOT NULL,
            is_default INTEGER NOT NULL DEFAULT 0,
            config TEXT NULL,
            secret_encrypted BLOB NULL,
            last_checked_at TEXT NULL,
            last_check_ok INTEGER NULL,
            last_check_error TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(label)
        )');

        // One location's safety copy on another (schema/core.sql:
        // storage_protections, ARCHITECTURE.md §8.110). No foreign keys
        // here: SQLite enforces them only when asked to, and every test
        // that needs the refusal exercises it through
        // Protection\StorageProtectionConsumer, which is where an
        // administrator actually meets it.
        $pdo->exec('CREATE TABLE storage_protections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_location_id INTEGER NOT NULL,
            destination_location_id INTEGER NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            grace_period_days INTEGER NOT NULL DEFAULT 30,
            cadence_hours INTEGER NOT NULL DEFAULT 24,
            pass_phase TEXT NULL,
            pass_started_at TEXT NULL,
            pass_cursor TEXT NULL,
            pass_page_last_key TEXT NULL,
            pass_seen_count INTEGER NOT NULL DEFAULT 0,
            last_completed_pass_at TEXT NULL,
            last_error TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(source_location_id)
        )');

        return $pdo;
    }

    /**
     * Put an address on the site's books, the way a bounce receipt now
     * requires before it will vouch for one.
     *
     * `BounceStateRepository::recordSend()` stamps a receipt only for an
     * address the site already holds — a confirmed `member_emails` row or
     * a `user_accounts` row — because an address a visitor merely handed
     * it must not vouch for itself. A fixture that sends to an address
     * nobody has ever heard of is therefore testing the refusal, which is
     * rarely what it means to test.
     */
    /**
     * The Desk address, which lives in `member_years` and nowhere else —
     * the commonest address on a real site, and the one belonging to the
     * parent who never signs in.
     */
    public static function markDeskAddressOnFile(\PDO $pdo, string $email): void
    {
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $blindIndex = $encryption->blindIndex(
            \Core\Security\EncryptionService::normalizeEmailForIndex($email),
            'email'
        );

        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK-BOUNCE')");
        $memberId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                 email_encrypted, email_blind_index)
             VALUES (?, 1, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $encryption->encrypt('Parent'),
            $encryption->encrypt('Exemple'),
            $encryption->encrypt($email),
            $blindIndex,
        ]);
    }

    public static function markAddressOnFile(\PDO $pdo, string $email): void
    {
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $blindIndex = $encryption->blindIndex(
            \Core\Security\EncryptionService::normalizeEmailForIndex($email),
            'email'
        );

        $existing = $pdo->prepare('SELECT 1 FROM user_accounts WHERE email_blind_index = ?');
        $existing->execute([$blindIndex]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute([$encryption->encrypt($email, 'user_accounts.email'), $blindIndex]);
    }
}
