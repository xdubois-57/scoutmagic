-- Modules\TestTools — the mail sandbox's own tables (ARCHITECTURE.md §8.61).
--
-- This module only ever exists on the project's reference installation or
-- on a developer's machine: `"visible_when": ["reference_installation",
-- "local_installation"]` in module.json keeps it out of
-- ModuleManager::discoverModules() everywhere else. No deploying unit's
-- installation ever holds one of these rows.

-- One row per captured message. The message itself is never here: it lives
-- encrypted at rest under `mime_file_id` (Core\File\
-- EncryptedFileStorageService, ARCHITECTURE.md §8.14).
CREATE TABLE captured_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- In clear, and a deliberate exception to SECURITY.md §5: the sandbox
    -- list is unusable without a searchable subject, and the exception is
    -- admissible only *because* this module cannot load on a deploying
    -- unit's installation. Read that condition, not just the exception.
    subject VARCHAR(255) NOT NULL,
    -- A recipient IS personal data, so BLOB + blind index like every other
    -- searchable address in this codebase (design.md §2.6). Encrypted and
    -- decrypted only in Modules\TestTools\Repository\CapturedEmailRepository.
    recipient BLOB NOT NULL,
    recipient_blind_index CHAR(64) NOT NULL,
    -- Organisational addresses (the site's own From, and the Reply-To the
    -- sending feature chose), in clear per design.md §2.6's "Section email
    -- (organizational)" row.
    from_address VARCHAR(255) NOT NULL,
    reply_to VARCHAR(255) NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    has_dkim BOOLEAN NOT NULL DEFAULT 0,
    attachment_count INT UNSIGNED NOT NULL DEFAULT 0,
    -- files.id of the raw RFC 5322 message. NULL only when assembly failed
    -- before PHPMailer could produce one — see error_message.
    mime_file_id INT UNSIGNED NULL,
    -- PHPMailer's own error when assembly failed. The row is written
    -- anyway and the exception is rethrown: a test tool that silently
    -- swallows a broken mail is worse than useless.
    error_message TEXT NULL,
    INDEX idx_captured_emails_captured_at (captured_at),
    INDEX idx_captured_emails_recipient (recipient_blind_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per attachment, read off the PHPMailer instance at capture time
-- and stored as its own encrypted file. The source paths are frequently
-- temporary and will not exist later, and doing it this way means the
-- sandbox never has to parse MIME to offer a download.
CREATE TABLE captured_email_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    captured_email_id INT UNSIGNED NOT NULL,
    -- The display name the message carries, not the source path.
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    -- files.id of the attachment's own encrypted copy, so it downloads
    -- through /files/{id} like any other file (FileAccessGuard applies).
    file_id INT UNSIGNED NULL,
    INDEX idx_captured_email_attachments_email (captured_email_id),
    CONSTRAINT fk_captured_email_attachments_email
        FOREIGN KEY (captured_email_id) REFERENCES captured_emails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
