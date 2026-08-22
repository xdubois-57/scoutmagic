<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Security\EncryptionService;

/**
 * SQLite mirror of modules/test_tools/schema.sql, same pattern as
 * Tests\Modules\SupportDashboard\SupportDashboardTestHelper.
 */
class TestToolsTestHelper
{
    /**
     * Make `Modules\TestTools\*` resolvable no matter which tests ran
     * first — see SupportDashboardTestHelper::ensureAutoloadable() for why
     * this is not redundant with composer.json.
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
        $pdo->exec('CREATE TABLE captured_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            captured_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            subject TEXT NOT NULL,
            recipient BLOB NOT NULL,
            recipient_blind_index TEXT NOT NULL,
            from_address TEXT NOT NULL,
            reply_to TEXT NULL,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            has_dkim INTEGER NOT NULL DEFAULT 0,
            attachment_count INTEGER NOT NULL DEFAULT 0,
            mime_file_id INTEGER NULL,
            body_html_file_id INTEGER NULL,
            body_text_file_id INTEGER NULL,
            error_message TEXT NULL
        )');

        $pdo->exec('CREATE TABLE captured_email_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            captured_email_id INTEGER NOT NULL,
            file_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            file_id INTEGER NULL
        )');
    }

    public static function encryption(): EncryptionService
    {
        return new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );
    }
}
