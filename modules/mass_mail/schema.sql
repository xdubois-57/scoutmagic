-- mass_mail module
--
-- Mass emails sent to unit members. Two kinds of mailing lists:
-- - "default" lists are never stored as rows — they're computed on the fly
--   by Service\MailingListService (one per active section, "Membres actifs",
--   "Chefs uniquement"), so a section becoming active/inactive at Desk
--   import is reflected immediately, with nothing to keep in sync.
-- - "custom" lists (mass_mail_lists) are admin-authored, stored, and
--   resolved dynamically at every use (functions x sections criteria,
--   never a cached member snapshot) — see mass_mail_list_functions/
--   mass_mail_list_sections below.

-- mass_mail_lists: a custom mailing list's identity/lifecycle. The
-- selection criteria themselves live in the two junction tables below —
-- this table is deliberately criteria-free so "resolve this list's
-- members" always means "join against the current junction rows", never
-- risking a stale snapshot. is_active mirrors the Core\Badge pattern
-- (§8.11 of ARCHITECTURE.md): a list already referenced by an email (any
-- status) can never be deleted, only deactivated — see Service\
-- MailingListService::delete().
CREATE TABLE IF NOT EXISTS mass_mail_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    -- Mandatory — shown next to the list in every picker so a chief can
    -- tell what a list actually means without guessing from its name alone.
    description VARCHAR(500) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    CONSTRAINT fk_mml_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_list_functions / mass_mail_list_sections: a custom list's
-- selection criteria — AND semantics between the two tables (a member
-- must hold one of the selected functions AND within one of the selected
-- sections), OR semantics within each table. See Service\
-- MailingListService::resolveCustomList().
CREATE TABLE IF NOT EXISTS mass_mail_list_functions (
    list_id INT UNSIGNED NOT NULL,
    function_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (list_id, function_id),
    CONSTRAINT fk_mmlf_list FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmlf_function FOREIGN KEY (function_id) REFERENCES functions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mass_mail_list_sections (
    list_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (list_id, section_id),
    CONSTRAINT fk_mmls_list FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmls_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_emails: one mass email, draft → test → sending → sent (see
-- Service\MassMailService — no step is ever skipped or reversed except
-- test → draft). subject/body_html hold no personal data (admin-authored
-- content, not imported data) so they stay in clear, unlike the recipient
-- table below. The list actually used is identified by list_type plus
-- exactly one of list_id (a mass_mail_lists row, only for 'custom') or
-- list_section_id (a sections row, only for 'default_section') — kept as
-- two real, FK-checked columns rather than one polymorphic id, so neither
-- can ever point at a deleted/wrong-type row; enforced together in
-- Service\MassMailService, since a cross-column CHECK constraint isn't
-- portable to the SQLite test database.
CREATE TABLE IF NOT EXISTS mass_mail_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    list_type ENUM('default_section', 'default_active_members', 'default_chiefs', 'custom') NOT NULL,
    list_id INT UNSIGNED NULL,
    list_section_id INT UNSIGNED NULL,
    status ENUM('draft', 'test', 'sending', 'sent') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    created_by INT UNSIGNED,
    INDEX idx_mme_status (status),
    CONSTRAINT fk_mme_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_mme_list FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id),
    CONSTRAINT fk_mme_list_section FOREIGN KEY (list_section_id) REFERENCES sections(id),
    CONSTRAINT fk_mme_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_email_scout_years: an email can target several scout years at
-- once (e.g. "Montages dias" retrospectives spanning two promotions) — the
-- list resolved for each selected year is merged and deduplicated by
-- address at freeze time (Service\MailingListService::
-- resolveMembersForYears()). A dedicated junction table rather than a
-- second scout_year_id column, since the set has no natural cap.
CREATE TABLE IF NOT EXISTS mass_mail_email_scout_years (
    email_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (email_id, scout_year_id),
    CONSTRAINT fk_mmesy_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmesy_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_recipients: one row per member the list resolved to at the
-- moment the email left 'draft'/'test' for 'sending' (Service\
-- MassMailService::startSending() "freezes" the list here — resolved
-- fresh from Service\MailingListService, never cached before this
-- point). member_id references the permanent members(id), not
-- member_years, so the link (and the tracking page) survives a scout
-- year change. email_address_encrypted is personal data copied at that
-- same instant → BLOB + EncryptionService, decrypted only in
-- Repository\RecipientRepository; no blind index, since this table is
-- never searched by address. NULL for a member who had no usable address
-- at freeze time — status is then immediately 'error' (never 'pending'),
-- same row shape as any other send failure so the tracking page needs no
-- special case for it.
CREATE TABLE IF NOT EXISTS mass_mail_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    -- Which of the email's (possibly several) selected scout years this
    -- particular recipient was actually resolved from — needed to look up
    -- their member_years profile correctly on the tracking page (a member
    -- resolved via the "previous year" list only has a valid profile for
    -- that year, not necessarily the current one).
    scout_year_id INT UNSIGNED NOT NULL,
    email_address_encrypted BLOB NULL,
    status ENUM('pending', 'sent', 'error') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mmr_status_id (status, id),
    INDEX idx_mmr_email (email_id),
    CONSTRAINT fk_mmr_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmr_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_mmr_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_attachments: plain Core\File\UploadHandler + FileAccessGuard
-- (role_min "chief", see module.json's storage.attachments) — not
-- Core\File\EncryptedFileStorageService, since a mass-mail attachment is
-- content a chief chose to send (a flyer, a form), never personal data.
CREATE TABLE IF NOT EXISTS mass_mail_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    CONSTRAINT fk_mma_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mma_file FOREIGN KEY (file_id) REFERENCES files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
