<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

/**
 * SQLite mirror of modules/support_dashboard/schema.sql, same pattern as
 * Tests\Modules\Retro\RetroTestHelper.
 */
class SupportDashboardTestHelper
{
    /**
     * Make `Modules\SupportDashboard\*` resolvable no matter which tests
     * ran first.
     *
     * The prefix is declared in composer.json but is absent from a
     * `vendor/composer/autoload_psr4.php` generated before this module
     * existed — the very case Core\System\ComposerAutoloadSync exists to
     * patch at boot in production. Under `vendor/bin/phpunit` the whole
     * suite only resolves these classes because some *other* test happens
     * to have applied that sync first, which makes any subset run
     * ("phpunit tests/Modules/SupportDashboard") fail with "Class not
     * found". Applying it ourselves costs one small JSON parse and removes
     * the ordering dependency.
     */
    public static function ensureAutoloadable(): void
    {
        $root = dirname(__DIR__, 3);

        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require $root . '/vendor/autoload.php';

        \Core\System\ComposerAutoloadSync::apply($loader, $root . '/composer.json');
    }

    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE support_installations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            installation_id TEXT NOT NULL UNIQUE,
            instance_url TEXT,
            secret_hash TEXT NOT NULL,
            payload TEXT,
            statistics_schema_version INTEGER NULL,
            first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scoutmagic_version TEXT NULL,
            is_dev_build INTEGER NULL,
            active_members INTEGER NULL,
            active_sections INTEGER NULL,
            installation_method TEXT NULL,
            auto_update_enabled INTEGER NULL,
            auto_update_level TEXT NULL,
            scout_year_label TEXT NULL,
            installed_at TEXT NULL,
            last_upgraded_at TEXT NULL,
            telemetry_enabled INTEGER NOT NULL DEFAULT 1
        )');

        $pdo->exec('CREATE TABLE support_monthly_aggregates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month TEXT NOT NULL UNIQUE,
            installation_count INTEGER NOT NULL,
            finalized_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE support_monthly_contributions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month TEXT NOT NULL,
            installation_id TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (month, installation_id)
        )');

        $pdo->exec('CREATE TABLE support_report_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE support_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NOT NULL UNIQUE,
            installation_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            description_encrypted BLOB NOT NULL,
            contact_email_encrypted BLOB NOT NULL,
            contact_email_blind_index TEXT NOT NULL,
            site_version TEXT NULL,
            php_version TEXT NULL,
            status TEXT NOT NULL DEFAULT \'open\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT NULL,
            resolution_note_encrypted BLOB NULL,
            archive_file_id INTEGER NULL,
            archive_received_at TEXT NULL
        )');
    }
}
