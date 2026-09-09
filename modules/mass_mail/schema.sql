-- mass_mail module
--
-- Mass emails sent to unit members. Four kinds of mailing lists:
-- - "default" lists are never stored as rows — they're computed on the fly
--   by Service\MailingListService (one per active section, "Membres actifs",
--   "Chefs uniquement"), so a section becoming active/inactive at Desk
--   import is reflected immediately, with nothing to keep in sync.
-- - "custom" lists (mass_mail_lists) are admin-authored, stored, and
--   resolved dynamically at every use (functions x sections criteria,
--   never a cached member snapshot) — see mass_mail_list_functions/
--   mass_mail_list_sections below.
-- - "external" lists are never stored here either — contributed at
--   request time by another module's Api\ExternalMailingListProvider
--   (ARCHITECTURE.md §7.5), nullable, degrading to "no such list" when
--   that module is disabled. See mass_mail_emails.list_type below.
-- - "mail_merge" audiences (mass_mail_audiences below) come from a chief-
--   uploaded Excel file: one email PER ROW (not per address), each row
--   carrying its own substitution values ("Cher {{Prenom}}..."). Unlike
--   every other list type this is a frozen snapshot BY DESIGN — the file
--   is the authority, resolved once at import, never re-derived from
--   member tables.

-- mass_mail_audiences: one imported Excel file (first sheet only). The
-- .xlsx itself is deleted right after parsing (same rule as the Desk CSV
-- import) — these rows are all that remains. columns_json is the ordered
-- list of column headers (the merge variables offered in the compose
-- dialog); it holds header NAMES only, never values, so it stays in
-- clear. Purged by Task\PurgeMergeAudiencesHandler: 18 months after the
-- last referencing email was sent (merge_retention_months, read-only
-- setting), or 7 days after import for an audience no email ever
-- referenced (an orphan left by "Remplacer le fichier").
CREATE TABLE IF NOT EXISTS mass_mail_audiences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_filename VARCHAR(255) NOT NULL,
    sheet_name VARCHAR(100) NOT NULL,
    columns_json TEXT NOT NULL,
    row_count INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    CONSTRAINT fk_mmau_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_audience_rows: one Excel data row = one email to send.
-- Exactly one of member_id (row had a "Tiers" value, resolved against
-- members.desk_id at import — the email then goes to every address known
-- for that member) or email_encrypted (no Tiers → the row's own "Email"
-- column, possibly several addresses "a; b") is set — enforced by
-- Service\AudienceImportService, which refuses the whole file otherwise.
-- data_encrypted is the full row as a JSON map {header: value}: imported
-- personal data → BLOB + EncryptionService, decrypted only in
-- Repository\AudienceRepository (SECURITY.md). row_index is the Excel
-- line number (2 for the first data row), kept for error messages and
-- the per-row preview.
CREATE TABLE IF NOT EXISTS mass_mail_audience_rows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    row_index INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NULL,
    email_encrypted BLOB NULL,
    data_encrypted BLOB NOT NULL,
    INDEX idx_mmar_audience (audience_id),
    CONSTRAINT fk_mmar_audience FOREIGN KEY (audience_id) REFERENCES mass_mail_audiences(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmar_member FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_suppressed_addresses: the unsubscribe list for addresses that
-- are NOT a member's (a mail-merge row with no Tiers). A member address
-- unsubscribes through its member_emails row (Core\Member\
-- MemberEmailService::unsubscribe()) — an external address has no such
-- row, so its one-click unsubscribe lands here instead, as a SHA-256 hash
-- of the lowercased address (never the address itself — this table is
-- only ever checked by exact match at freeze time, a blind-index-like
-- pattern with nothing to decrypt back). Honored by MassMailService::
-- startSending() for every future mail-merge send; never purged (an
-- unsubscribe must outlive any retention window).
CREATE TABLE IF NOT EXISTS mass_mail_suppressed_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_mmsa_hash (email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- mass_mail_list_functions / mass_mail_list_sections /
-- mass_mail_list_badges: a custom list's selection criteria, on three
-- axes. AND between the tables (a member must hold one of the selected
-- functions AND be within one of the selected sections AND wear one of
-- the selected badges), OR within each table. An axis with no rows is
-- NOT a constraint and drops out of the conjunction entirely; a list
-- with no rows in any of the three resolves to NO member, never to
-- every member — a list may legitimately hold nothing but its own
-- addresses. See Service\MailingListService::resolveCustomList().
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

-- Badges are already historised BY SCOUT YEAR (member_badges.
-- member_year_id, ARCHITECTURE.md §8.11), so resolving this axis is a
-- join on the member_year of the year being resolved — a badge worn in a
-- past year never counts for the current one, with nothing to invent
-- here. badges(id) is a CORE table, so this foreign key crosses no
-- module boundary (AGENTS.md § Database).
-- The badge cascade is guarded ABOVE the database, and has to be. Losing
-- this row does not narrow the list to nobody — an axis with no rows
-- stops constraining anything — so « la meute ET le badge X » would
-- quietly become « la meute » the moment X is deleted. Core\Badge\
-- BadgeService::delete() therefore refuses a badge a list still crosses,
-- asking Core\Module\BadgeUsageProvider, which Service\
-- MailingListBadgeUsageService implements over findReferencedBadgeIds().
-- Deactivating a badge stays allowed: the criteria picker keeps offering
-- a deactivated badge a list names, greyed, so the criterion can be
-- removed by hand first. The FK stays CASCADE for the case the guard
-- cannot cover — a row deleted outside the application.
CREATE TABLE IF NOT EXISTS mass_mail_list_badges (
    list_id INT UNSIGNED NOT NULL,
    badge_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (list_id, badge_id),
    CONSTRAINT fk_mmlb_list FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmlb_badge FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_list_addresses: the people a list writes to who are NOT
-- members of the site — the commune, the curé, the owner of the camp
-- ground, a former member Desk never knew. Resolving a custom list is the
-- UNION of what its criteria resolve and its own active addresses here.
--
-- The addresses belong to the LIST (list_id, ON DELETE CASCADE). No
-- global address book, no join table, no contact entity shared between
-- lists: editing an address changes it here and nowhere else. A shared
-- book would have bought inter-list deduplication for a rare case, at
-- the price of orphan rules and side effects nobody can see on screen.
--
-- **The unsubscribe, by contrast, is global** and that is the reason for
-- the second index: it is an UPDATE on every row carrying the same blind
-- index, in every list. Somebody asking not to be written to is
-- addressing the unit, not a list — and it creates no shared entity,
-- being a WHERE clause rather than a table.
--
-- An unsubscribed row survives everything: it stays visible (greyed) on
-- the screen, it survives a wholesale Excel replacement, and no import
-- can ever re-subscribe it. Without that the unsubscribe link is worth
-- nothing.
--
-- One name field, deliberately: these people are not members, there is
-- no first name and no totem to tell apart. Name and address are
-- personal data → BLOB encrypted through Core\Security\EncryptionService,
-- encrypted and decrypted ONLY in Repository\ListAddressRepository
-- (SECURITY.md §5).
CREATE TABLE IF NOT EXISTS mass_mail_list_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    name_encrypted BLOB,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    unsubscribed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_mmla_list_email (list_id, email_blind_index),
    INDEX idx_mmla_blind (email_blind_index),
    CONSTRAINT fk_mmla_list FOREIGN KEY (list_id)
        REFERENCES mass_mail_lists(id) ON DELETE CASCADE
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
    -- 'external': a predefined, non-editable list contributed by another
    -- module via Api\ExternalMailingListProvider (ARCHITECTURE.md §7.5) —
    -- list_id/list_section_id both stay NULL for it, exactly like
    -- 'default_active_members'/'default_chiefs'; the providing module
    -- resolves its own single list fresh on every use, nothing to key
    -- against here. Currently only the registration module provides one.
    -- 'mail_merge': a chief-uploaded Excel audience (mass_mail_audiences
    -- above) — audience_id is set for it and only for it, list_id/
    -- list_section_id stay NULL, and the mass_mail_email_scout_years
    -- junction stays empty (the file, not a scout year, defines who
    -- receives it).
    -- 'default_former_members': the « Anciens » list — active in a past
    -- scout year, not active in the effective one. Computed on the fly
    -- like the other default lists; list_id/list_section_id stay NULL.
    -- Each former member is resolved from THEIR OWN last active year, and
    -- that is what mass_mail_recipients.scout_year_id carries for them —
    -- the tracking page looks their profile up by it, and their profile
    -- only exists for that year.
    list_type ENUM('default_section', 'default_active_members', 'default_chiefs', 'custom', 'external', 'mail_merge', 'default_former_members') NOT NULL,
    list_id INT UNSIGNED NULL,
    list_section_id INT UNSIGNED NULL,
    audience_id INT UNSIGNED NULL,
    status ENUM('draft', 'test', 'sending', 'sent') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    created_by INT UNSIGNED,
    INDEX idx_mme_status (status),
    CONSTRAINT fk_mme_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_mme_list FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id),
    CONSTRAINT fk_mme_list_section FOREIGN KEY (list_section_id) REFERENCES sections(id),
    -- SET NULL: the retention purge deletes an audience 18 months after
    -- the send — the sent email itself lives on, merely unlinked.
    CONSTRAINT fk_mme_audience FOREIGN KEY (audience_id) REFERENCES mass_mail_audiences(id) ON DELETE SET NULL,
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

-- mass_mail_recipients: one row per (member, address) the list resolved
-- to at the moment the email left 'draft'/'test' for 'sending' (Service\
-- MassMailService::startSending() "freezes" the list here — resolved
-- fresh from Service\MailingListService, never cached before this
-- point). A member with several currently-valid addresses (Desk +
-- secondary, Core\Member\MemberEmailService) gets one row per address,
-- not one row for the member — member_id is deliberately NOT unique per
-- email_id. member_id references the permanent members(id), not
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
    -- NULL for the two recipients who are nobody in the members table,
    -- and audience_row_id is what tells them apart: a mail-merge row
    -- addressed by its "Email" column rather than a "Tiers" (audience_row_id
    -- SET), and a mass_mail_list_addresses row of a custom list
    -- (audience_row_id NULL). That pair is what
    -- Controller\UnsubscribeController branches on, so a « stop writing to
    -- me » lands on the right table. Every criteria-resolved recipient
    -- still always has a member_id.
    member_id INT UNSIGNED NULL,
    -- Which of the email's (possibly several) selected scout years this
    -- particular recipient was actually resolved from — needed to look up
    -- their member_years profile correctly on the tracking page (a member
    -- resolved via the "previous year" list only has a valid profile for
    -- that year, not necessarily the current one). NULL for an external
    -- mail-merge recipient (no member, no profile, no year).
    scout_year_id INT UNSIGNED NULL,
    -- The mass_mail_audience_rows row this recipient was frozen from
    -- (mail-merge only, NULL otherwise) — what Task\SendBatchHandler
    -- renders the per-recipient variables from. SET NULL on audience
    -- purge: the tracking history survives, only the merge values are
    -- gone (a "Renvoyer" after the purge then fails explicitly).
    audience_row_id INT UNSIGNED NULL,
    email_address_encrypted BLOB NULL,
    -- The member_emails row this specific address maps to (Core\Member\
    -- MemberEmailService::resolveOrCreateForMassMail() — for a Desk
    -- address, lazily find-or-creates a 'desk'-sourced override row the
    -- first time it's needed here, so unsubscribing it later always has
    -- something to flip to 'inactive'). NULL only for the defensive
    -- "invalid address" branch above, which never gets a real address or
    -- an unsubscribe token either.
    member_email_id INT UNSIGNED NULL,
    -- Per-recipient one-click unsubscribe token (module addendum, RFC
    -- 8058) — same generation/hashing convention as magic_links
    -- (bin2hex(random_bytes(32)), hashed with password_hash() before
    -- storage). Unlike a login token this is never single-use/expiring:
    -- it must keep working for as long as this specific send's footer
    -- link exists in the recipient's mailbox.
    unsubscribe_token_hash VARCHAR(255) NULL,
    status ENUM('pending', 'sent', 'error') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mmr_status_id (status, id),
    INDEX idx_mmr_email (email_id),
    CONSTRAINT fk_mmr_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmr_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_mmr_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_mmr_member_email FOREIGN KEY (member_email_id) REFERENCES member_emails(id),
    CONSTRAINT fk_mmr_audience_row FOREIGN KEY (audience_row_id) REFERENCES mass_mail_audience_rows(id) ON DELETE SET NULL
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
