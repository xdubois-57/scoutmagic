<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

/**
 * SQLite mirror of modules/support_dashboard/schema.sql, same pattern as
 * Tests\Modules\Retro\RetroTestHelper.
 */
class SupportDashboardTestHelper
{
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
            last_upgraded_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE support_report_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
