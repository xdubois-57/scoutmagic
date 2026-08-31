<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

/**
 * SQLite mirrors of modules/inbound_mail/schema.sql, for the in-memory test
 * database — same approach as Tests\Modules\Rental\RentalTestHelper. The
 * column set must stay in step with the real schema by hand: a column added
 * there and forgotten here shows up as a repository test failing on "no
 * such column", which is the intended failure mode.
 */
class InboundMailTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE inbound_mailboxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            provider_type TEXT NOT NULL DEFAULT "imap",
            host TEXT NOT NULL DEFAULT "",
            port INTEGER NOT NULL DEFAULT 993,
            encryption TEXT NOT NULL DEFAULT "ssl",
            username_encrypted BLOB NOT NULL,
            password_encrypted BLOB NOT NULL,
            folders TEXT,
            is_enabled INTEGER NOT NULL DEFAULT 1,
            purpose TEXT NOT NULL DEFAULT "shared",
            dedicated_to TEXT,
            sync_state TEXT NOT NULL DEFAULT "never",
            last_synced_at TEXT,
            last_error TEXT,
            last_error_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE inbound_mailbox_consumers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER NOT NULL,
            consumer_id TEXT NOT NULL,
            analyze_enabled INTEGER NOT NULL DEFAULT 0,
            read_mode TEXT NOT NULL DEFAULT "none",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (mailbox_id, consumer_id)
        )');

        $pdo->exec('CREATE TABLE inbound_mailbox_cursors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER NOT NULL,
            folder TEXT NOT NULL,
            uid_validity INTEGER NOT NULL DEFAULT 0,
            last_uid INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (mailbox_id, folder)
        )');

        $pdo->exec('CREATE TABLE inbound_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER NOT NULL,
            folder TEXT NOT NULL,
            uid_validity INTEGER NOT NULL DEFAULT 0,
            imap_uid INTEGER NOT NULL DEFAULT 0,
            consumer_id TEXT,
            business_reference TEXT,
            link_origin TEXT,
            message_id_blind_index TEXT NOT NULL,
            in_reply_to_blind_index TEXT,
            from_email_blind_index TEXT NOT NULL,
            subject_encrypted BLOB NOT NULL,
            from_email_encrypted BLOB NOT NULL,
            from_name_encrypted BLOB,
            message_id_encrypted BLOB NOT NULL,
            in_reply_to_encrypted BLOB,
            to_emails_encrypted BLOB,
            body_text_encrypted BLOB NOT NULL,
            body_html_encrypted BLOB NOT NULL,
            sent_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            stored_analysis_at TEXT,
            is_bulk INTEGER NOT NULL DEFAULT 0,
            last_unlinked_at TEXT,
            UNIQUE (mailbox_id, message_id_blind_index)
        )');

        $pdo->exec('CREATE TABLE inbound_message_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            consumer_id TEXT NOT NULL,
            business_reference TEXT NOT NULL,
            attachment_id INTEGER NOT NULL DEFAULT 0,
            link_origin TEXT NOT NULL,
            created_by_user_account_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (message_id, consumer_id, business_reference, attachment_id)
        )');

        $pdo->exec('CREATE TABLE inbound_message_candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            attachment_id INTEGER NOT NULL DEFAULT 0,
            consumer_id TEXT NOT NULL,
            business_reference TEXT NOT NULL,
            evidence_type TEXT NOT NULL,
            evidence_label_encrypted BLOB NOT NULL,
            evidence_explanation_encrypted BLOB NOT NULL,
            dismissed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (message_id, consumer_id, business_reference, attachment_id)
        )');

        $pdo->exec('CREATE TABLE inbound_message_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            file_id INTEGER,
            filename_encrypted BLOB NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            content_hash TEXT NOT NULL,
            omission_reason TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    /**
     * A raw RFC 5322 message, built the way the fixtures below want it.
     *
     * @param array<string, string> $headers
     */
    public static function rawMessage(array $headers, string $body): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = '';
        $lines[] = $body;

        return implode("\r\n", $lines);
    }
}
