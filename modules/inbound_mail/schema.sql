-- inbound_mail module
--
-- A read-only gateway onto one or more remote mailboxes (§7.1). Nothing in
-- this schema describes a folder, a flag or a draft: this is not a mail
-- client, and it never writes to the remote box (§7.5).

-- inbound_mailboxes: one configured remote mailbox.
--
-- The password is the only secret, is stored encrypted via
-- EncryptionService (BLOB), and is read exactly once — when a client is
-- about to connect. Nothing renders it, not even partially (§7.4).
CREATE TABLE IF NOT EXISTS inbound_mailboxes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- What the superadmin called it, and the only thing a non-superadmin
    -- ever learns about a box besides whether it is working.
    name VARCHAR(150) NOT NULL,
    -- 'imap' | 'fake'. The abstraction exists so a native connector could
    -- be added later (§7.2); no such connector is implemented, and Gmail
    -- deliberately connects over IMAP with an app password.
    provider_type VARCHAR(20) NOT NULL DEFAULT 'imap',
    host VARCHAR(255) NOT NULL DEFAULT '',
    port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    -- 'ssl' | 'tls' | 'starttls'. Never a "no encryption" option and never
    -- a "skip certificate check" one: an invalid certificate is a failed
    -- connection, not a warning to click through (§7.5).
    encryption VARCHAR(20) NOT NULL DEFAULT 'ssl',
    -- The account name. Not a secret in the sense the password is, but it
    -- is an email address, so it is encrypted like every other one.
    username_encrypted BLOB NOT NULL,
    password_encrypted BLOB NOT NULL,
    -- Newline-separated folder paths, in the server's own naming. Empty
    -- means INBOX.
    folders TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- 'never' | 'ok' | 'error'. 'never' is a real state, distinct from
    -- 'ok': a box that has not once connected must not look healthy.
    sync_state VARCHAR(20) NOT NULL DEFAULT 'never',
    last_synced_at DATETIME NULL,
    -- A sentence written for a superadmin by Service\MailboxErrorFormatter,
    -- never a library's own exception text — that routinely carries the
    -- account name and the server's verbatim rejection of a credential.
    last_error VARCHAR(500) NULL,
    last_error_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_mailbox_cursors: how far each watched folder has been read.
--
-- An IMAP UID means nothing without its folder's UIDVALIDITY: a server that
-- renumbers a folder (restore, migration) announces it by changing that
-- value, and a cursor remembering only "last UID 4212" would then skip
-- every message below the new 4212 (§7.5). Storing the pair is what makes a
-- renumbering a re-read rather than a silent loss.
CREATE TABLE IF NOT EXISTS inbound_mailbox_cursors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id INT UNSIGNED NOT NULL,
    folder VARCHAR(255) NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_cursor_mailbox_folder (mailbox_id, folder),
    CONSTRAINT fk_cursor_mailbox FOREIGN KEY (mailbox_id) REFERENCES inbound_mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_messages: the messages a consumer module claimed.
--
-- **Only claimed messages are here** (§7.6). A message no consumer
-- recognises is discarded during the sync: not stored, not listed, not
-- notified. Keeping it "just in case" would build an archive of the unit's
-- mailbox with no screen to consult it — the worst of both worlds for
-- retention.
--
-- Everything a sender wrote is encrypted at rest: subject, addresses and
-- both bodies. message_id_blind_index is the searchable form of the
-- Message-ID, used for deduplication and for threading a reply onto the
-- message it answers, since an encrypted column cannot be compared.
CREATE TABLE IF NOT EXISTS inbound_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id INT UNSIGNED NOT NULL,
    folder VARCHAR(255) NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    imap_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- Which module claimed it, and for which of its objects. Deliberately
    -- an opaque string on this side: `inbound_mail` has no idea what a
    -- booking reference looks like and must not acquire one.
    consumer_id VARCHAR(50) NOT NULL,
    business_reference VARCHAR(100) NOT NULL,
    -- 'reference' | 'thread' | 'sender' | 'ai'. Shown to the manager: an
    -- attachment made on a sender address is a weaker claim than one made
    -- on an explicit reference, and saying so is the honest interface.
    link_origin VARCHAR(20) NOT NULL,

    message_id_blind_index VARCHAR(64) NOT NULL,
    in_reply_to_blind_index VARCHAR(64) NULL,
    from_email_blind_index VARCHAR(64) NOT NULL,

    subject_encrypted BLOB NOT NULL,
    from_email_encrypted BLOB NOT NULL,
    from_name_encrypted BLOB NULL,
    message_id_encrypted BLOB NOT NULL,
    in_reply_to_encrypted BLOB NULL,
    -- Every address the message named, newline-separated and encrypted like
    -- the rest. A consumer's timeline shows them so a manager can tell a
    -- private reply from one copied to the whole staff.
    to_emails_encrypted BLOB NULL,
    body_text_encrypted BLOB NOT NULL,
    -- Already sanitised through HtmlSanitizer and stripped of remote images
    -- before it was written (§7.9). The raw HTML is never stored: keeping it
    -- would mean every future reader has to remember to sanitise.
    body_html_encrypted BLOB NOT NULL,

    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- The same message must not land twice on the same object, whatever
    -- UID it now carries after a renumbering.
    UNIQUE INDEX idx_message_dedup (mailbox_id, consumer_id, business_reference, message_id_blind_index),
    INDEX idx_message_reference (consumer_id, business_reference, sent_at),
    INDEX idx_message_thread (mailbox_id, message_id_blind_index),
    CONSTRAINT fk_message_mailbox FOREIGN KEY (mailbox_id) REFERENCES inbound_mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_message_attachments: metadata only.
--
-- The bytes live in the core file store behind FileAccessGuard, under a
-- generated name, outside public/ (§7.9). content_hash is what makes the
-- same signature logo arriving on ten messages one stored file rather than
-- ten.
CREATE TABLE IF NOT EXISTS inbound_message_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    -- What the sender called it — a label, never the stored name.
    filename_encrypted BLOB NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attachment_message (message_id),
    INDEX idx_attachment_hash (content_hash),
    CONSTRAINT fk_attachment_message FOREIGN KEY (message_id) REFERENCES inbound_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
