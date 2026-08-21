<?php

declare(strict_types=1);

namespace Tests\Modules\Retro;

class RetroTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE retro_boards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            board_date TEXT NOT NULL,
            calendar_event_id INTEGER NULL,
            token_encrypted BLOB NOT NULL,
            token_blind_index TEXT NOT NULL UNIQUE,
            short_code TEXT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT "open",
            listed INTEGER NOT NULL DEFAULT 1,
            vote_mode TEXT NOT NULL DEFAULT "unlimited",
            vote_budget INTEGER NOT NULL DEFAULT 5,
            votes_visible INTEGER NOT NULL DEFAULT 1,
            anti_duplicate_mode TEXT NOT NULL DEFAULT "cookie",
            max_comment_length INTEGER NOT NULL DEFAULT 140,
            auto_close_delay TEXT NOT NULL DEFAULT "7d",
            auto_close_at TEXT NULL,
            ai_summary TEXT NULL,
            created_by INTEGER NULL,
            close_notify_enabled INTEGER NOT NULL DEFAULT 1,
            close_notify_email TEXT NULL,
            link_visibility TEXT NOT NULL DEFAULT "chief",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT NULL,
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE retro_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board_id INTEGER NOT NULL,
            column_key TEXT NOT NULL,
            body TEXT NOT NULL,
            votes INTEGER NOT NULL DEFAULT 0,
            hidden INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (board_id) REFERENCES retro_boards(id)
        )');

        $pdo->exec('CREATE TABLE retro_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_id INTEGER NOT NULL,
            board_id INTEGER NOT NULL,
            voter_hash TEXT NOT NULL,
            voter_board_hash TEXT NOT NULL,
            weight INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(comment_id, voter_hash),
            FOREIGN KEY (comment_id) REFERENCES retro_comments(id),
            FOREIGN KEY (board_id) REFERENCES retro_boards(id)
        )');

        $pdo->exec('CREATE TABLE retro_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier_hash TEXT NOT NULL,
            action_type TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        // Task\AutoCloseHandler always constructs LlmConnectorService
        // directly from $pdo regardless of whether llm_connector is
        // enabled (same precedent as Modules\Finance\Task\
        // ExtractReceiptDataHandler) — these tables must exist for its own
        // isAvailable() guard to even run its query.
        $pdo->exec('CREATE TABLE llm_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            driver TEXT NOT NULL,
            api_endpoint TEXT NOT NULL,
            api_key BLOB NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE llm_provider_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            model_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            is_tier_cheap INTEGER NOT NULL DEFAULT 0,
            is_tier_capable INTEGER NOT NULL DEFAULT 0,
            is_tier_ocr INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider_id, model_id),
            FOREIGN KEY (provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE
        )');
    }
}
