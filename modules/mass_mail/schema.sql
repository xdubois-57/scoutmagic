-- mass_mail module
--
-- Mass emails sent to unit members. Four kinds of mailing lists:
-- - "default" lists are never stored as rows — they're computed on the fly
--   by Service\MailingListService (one per active section, "Membres actifs",
--   "Chefs uniquement"), so a section becoming active/inactive at Desk
--   import is reflected immediately, with nothing to keep in sync.
-- - "custom" lists (mass_mail_lists) are admin-authored, stored, and
--   resolved dynamically at every use (never a cached member snapshot):
--   the union of their blocks — member groups (criteria) and contact
--   groups (external addresses) — see mass_mail_list_blocks below.
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

-- mass_mail_audience_rows: one Excel data row = one COMMUNICATION (issue
-- #999): one message per distinct address of the row, never deduplicated
-- between rows. Exactly one of member_id (row had a "Tiers" value — or a
-- module draft named the member — resolved at import; the messages then go
-- to that member's eligible addresses and the row is one target in their
-- history) or email_encrypted (no Tiers → the row's own "Email" column,
-- possibly several addresses separated by ";" or ",") is set — enforced by
-- Service\AudienceImportService, which refuses the whole file otherwise;
-- an Email column beside a Tiers is ignored for sending (it stays a
-- variable in data_encrypted).
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
-- unsubscribe must outlive any retention window); only an explicit reactivation removes it.
CREATE TABLE IF NOT EXISTS mass_mail_suppressed_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_mmsa_hash (email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_lists: a custom mailing list's identity/lifecycle. What it
-- designates lives in its blocks below — this table is deliberately
-- criteria-free so "resolve this list" always means "read its current
-- blocks", never risking a stale snapshot. is_active mirrors the
-- Core\Badge pattern (§8.11 of ARCHITECTURE.md): a list already referenced by an email (any
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

-- mass_mail_list_blocks: what a custom list is made of. A list is a
-- suite of 0..N blocks, ADDED together (a union) — no exclusion, no
-- order: the screen ranks them by kind, member groups first, then by
-- creation. No limit on how many of either kind.
--
-- Two kinds, and `kind` never changes once a block exists (Service\
-- ListBlockService refuses it: a member group turned contact group would
-- carry criteria nothing reads, or the other way round):
-- - 'member_group': a set of criteria in the three junctions below,
--   resolved against the scout year(s) chosen when the email is written.
--   No name — its sentence describes it — so `name` stays NULL.
-- - 'contact_group': people who are nobody in the members table, in
--   mass_mail_block_contacts, maintained by Excel file only. `name` is
--   mandatory for it (150 characters), and `file_replaced_at` says when
--   the last file was applied (« fichier remplacé le … »). Always
--   included, whatever the year.
-- Both rules on `name` are the service's, not a CHECK constraint, which
-- the SQLite test database would not enforce the same way.
--
-- A list with no block at all is valid and designates nobody.
CREATE TABLE IF NOT EXISTS mass_mail_list_blocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    kind ENUM('member_group', 'contact_group') NOT NULL,
    name VARCHAR(150) NULL,
    file_replaced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_mmlbk_list (list_id),
    CONSTRAINT fk_mmlbk_list FOREIGN KEY (list_id) REFERENCES mass_mail_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmlbk_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_block_functions / mass_mail_block_sections /
-- mass_mail_block_badges: a member group's criteria, on three axes. AND
-- between the tables (a member must hold one of the selected functions
-- AND be within one of the selected sections AND wear one of the
-- selected badges), OR within each table. An axis with no rows is NOT a
-- constraint and drops out of the conjunction entirely. A member group
-- with no row in any of the three would resolve to NO member, never to
-- every member — and cannot be saved: Service\ListBlockService demands
-- at least one choice. See Repository\MemberResolutionRepository::
-- resolveCustomList().
CREATE TABLE IF NOT EXISTS mass_mail_block_functions (
    block_id INT UNSIGNED NOT NULL,
    function_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (block_id, function_id),
    CONSTRAINT fk_mmbf_block FOREIGN KEY (block_id) REFERENCES mass_mail_list_blocks(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmbf_function FOREIGN KEY (function_id) REFERENCES functions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mass_mail_block_sections (
    block_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (block_id, section_id),
    CONSTRAINT fk_mmbs_block FOREIGN KEY (block_id) REFERENCES mass_mail_list_blocks(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmbs_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Badges are already historised BY SCOUT YEAR (member_badges.
-- member_year_id, ARCHITECTURE.md §8.11), so resolving this axis is a
-- join on the member_year of the year being resolved — a badge worn in a
-- past year never counts for the current one, with nothing to invent
-- here. badges(id) is a CORE table, so this foreign key crosses no
-- module boundary (AGENTS.md § Database).
-- The badge cascade is guarded ABOVE the database, and has to be. Losing
-- this row does not narrow the member group to nobody — an axis with no
-- rows stops constraining anything — so « la meute ET le badge X » would
-- quietly become « la meute » the moment X is deleted. Core\Badge\
-- BadgeService::delete() therefore refuses a badge a member group still
-- crosses, asking Core\Module\BadgeUsageProvider, which Service\
-- MailingListBadgeUsageService implements over findReferencedBadgeIds().
-- Deactivating a badge stays allowed: the criteria picker keeps offering
-- a deactivated badge a group names, greyed, so the criterion can be
-- removed by hand first. The FK stays CASCADE for the case the guard
-- cannot cover — a row deleted outside the application.
CREATE TABLE IF NOT EXISTS mass_mail_block_badges (
    block_id INT UNSIGNED NOT NULL,
    badge_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (block_id, badge_id),
    CONSTRAINT fk_mmbb_block FOREIGN KEY (block_id) REFERENCES mass_mail_list_blocks(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmbb_badge FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_block_contacts: the people a contact group writes to who are
-- NOT members of the site — the commune, the curé, the owner of the camp
-- ground, a former member Desk never knew. Resolving a custom list adds
-- the active contacts of every one of its contact groups to what its
-- member groups resolve.
--
-- The contacts belong to their GROUP (block_id, ON DELETE CASCADE). No
-- global address book, no contact entity shared between lists or
-- groups: the same address in two groups is two rows, and the send
-- writes to it once. A shared book would have bought inter-list
-- deduplication for a rare case, at the price of orphan rules and side
-- effects nobody can see on screen.
--
-- A group's contacts change ONLY through its Excel file (Service\
-- ContactGroupImportService): no row is added, corrected or removed one
-- at a time.
--
-- **The unsubscribe, by contrast, is global** and that is the reason for
-- the second index: it is an UPDATE on every row carrying the same blind
-- index, in every group of every list. Somebody asking not to be written
-- to is addressing the unit, not a list — and it creates no shared
-- entity, being a WHERE clause rather than a table. It is also written
-- to mass_mail_suppressed_addresses, which is what keeps it true once a
-- group is removed and its rows with it.
--
-- An unsubscribed row survives every file: a wholesale replacement
-- neither removes it nor re-subscribes it. Without that the unsubscribe
-- link is worth nothing.
--
-- One name field, deliberately: these people are not members, there is
-- no first name and no totem to tell apart. Name and address are
-- personal data → BLOB encrypted through Core\Security\EncryptionService,
-- encrypted and decrypted ONLY in Repository\ContactRepository
-- (SECURITY.md §5).
CREATE TABLE IF NOT EXISTS mass_mail_block_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    block_id INT UNSIGNED NOT NULL,
    name_encrypted BLOB,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    unsubscribed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_mmbc_block_email (block_id, email_blind_index),
    INDEX idx_mmbc_blind (email_blind_index),
    CONSTRAINT fk_mmbc_block FOREIGN KEY (block_id)
        REFERENCES mass_mail_list_blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_emails: one mass email, draft → test → sending → sent (see
-- Service\MassMailService — no step is ever skipped or reversed except
-- test → draft). subject/body_html hold no personal data (admin-authored
-- content, not imported data) so they stay in clear, unlike the message
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
    -- that is what mass_mail_targets.scout_year_id carries for them —
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
-- list resolved for each selected year is merged at freeze time, one target
-- per member (Service\MailingListService::resolveMembersForYears()). A
-- dedicated junction table rather than a second scout_year_id column, since
-- the set has no natural cap.
CREATE TABLE IF NOT EXISTS mass_mail_email_scout_years (
    email_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (email_id, scout_year_id),
    CONSTRAINT fk_mmesy_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmesy_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Three notions, four tables (issue #999, docs/chantiers/
-- CHANTIER-envois-groupes.md §2.1). « How many messages leave, to which
-- addresses » (mass_mail_deliveries), « whose history does this
-- communication belong to » (mass_mail_targets) and « how many alerts does
-- each account get » (mass_mail_notified_accounts) are counted apart, and
-- mass_mail_delivery_targets ties a message to the targets it concerns.
--
-- Everything is written at ONE moment: Service\MassMailService::
-- startSending() freezes the messages and the targets in the same
-- transaction as the test → sending transition, which is what makes a
-- second freeze of the same email impossible. No unique index including
-- audience_row_id guards that — the purge sets it to NULL, and MySQL holds
-- several NULLs to be distinct, so such an index would protect nothing
-- once it mattered (§2.1).

-- mass_mail_deliveries: one MESSAGE, always to exactly ONE address — the
-- unit of sending, of the tracking page, of « Renvoyer » and of the
-- one-click unsubscribe. A classic list writes one per DISTINCT address
-- across every target and every contact group (compared lowercased); a
-- mail merge one per distinct address OF EACH FILE ROW, never deduplicated
-- between rows (two rows carry different values). The address is personal
-- data copied at freeze time → BLOB + EncryptionService, decrypted only in
-- Repository\DeliveryRepository (SECURITY.md §5); email_blind_index is the
-- HMAC of the lowercased address, the only form an address is ever
-- compared under in SQL.
CREATE TABLE IF NOT EXISTS mass_mail_deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id INT UNSIGNED NOT NULL,
    -- The file row this message was frozen from (mail merge only) — what
    -- Task\SendBatchHandler renders the variables from. SET NULL at the
    -- audience purge: the tracking survives, the values are gone (a
    -- « Renvoyer » after the purge then fails explicitly).
    audience_row_id INT UNSIGNED NULL,
    email_address_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    -- The member_emails row this address maps to, when it is a member's
    -- (Core\Member\MemberEmailService::resolveValidAddressesForMassMail()
    -- lazily creates the Desk override row). What the unsubscribe flips to
    -- 'inactive'. NULL for a contact or a file address.
    member_email_id INT UNSIGNED NULL,
    status ENUM('pending', 'sent', 'error') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    -- One-click unsubscribe token of THIS message (RFC 8058), minted right
    -- before the send by Task\SendBatchHandler through Core\Security\
    -- CapabilityToken and stored as its SHA-256 hex digest — 32 random
    -- bytes need no slow hash. Never single-use nor expiring: it must work
    -- for as long as the message sits in a mailbox.
    unsubscribe_token_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mmd_status_id (status, id),
    INDEX idx_mmd_email (email_id),
    INDEX idx_mmd_blind (email_blind_index),
    CONSTRAINT fk_mmd_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmd_audience_row FOREIGN KEY (audience_row_id) REFERENCES mass_mail_audience_rows(id) ON DELETE SET NULL,
    -- SET NULL: a member may delete a secondary address that already
    -- received a mailing; the message stays, it merely can no longer
    -- deactivate that row (the unsubscribe still suppresses the address).
    CONSTRAINT fk_mmd_member_email FOREIGN KEY (member_email_id) REFERENCES member_emails(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_targets: one MEMBER a communication is meant for — the unit of
-- the member page's history and of the notification. A classic list: one
-- per member the list designates for the chosen years (the service keeps
-- it unique per email; a member brought by several criteria, groups or
-- years is one target, tagged with the most recent year that brought
-- them). A mail merge: one per FILE ROW that has a Tiers — two rows for
-- the same Tiers are two communications, so two targets. Sharing an
-- address never creates a target, and a target with no eligible address
-- exists all the same, with no message: it reads « Non envoyée ».
-- scout_year_id is the year whose member_years row the member page link
-- goes through; NULL only when the member has none at all (a merge Tiers
-- with no profile), and then there is no link.
CREATE TABLE IF NOT EXISTS mass_mail_targets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NULL,
    -- The file row (mail merge only). SET NULL at the purge, like the
    -- message's — the entry stays on the member page, « personnalisation
    -- effacée ».
    audience_row_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mmt_email (email_id),
    INDEX idx_mmt_member (member_id),
    CONSTRAINT fk_mmt_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmt_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_mmt_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_mmt_audience_row FOREIGN KEY (audience_row_id) REFERENCES mass_mail_audience_rows(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_delivery_targets: which targets a message concerns. A message
-- to a family address concerns every target that has that address; one
-- reaching an address only a contact group brings concerns none. CASCADE
-- on both sides, without which deleting an email (and with it its
-- messages and targets) would be refused.
CREATE TABLE IF NOT EXISTS mass_mail_delivery_targets (
    delivery_id INT UNSIGNED NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (delivery_id, target_id),
    INDEX idx_mmdt_target (target_id),
    CONSTRAINT fk_mmdt_delivery FOREIGN KEY (delivery_id) REFERENCES mass_mail_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmdt_target FOREIGN KEY (target_id) REFERENCES mass_mail_targets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mass_mail_notified_accounts: an account already sent its « Nouvel
-- email » notification for one communication (classic list:
-- audience_row_id NULL) or for one file row (mail merge). Written in the
-- same transaction as the notification itself (Task\SendBatchHandler), so
-- a second message of the same communication to another address of that
-- account, or a « Renvoyer », never notifies again.
--
-- audience_row_key is COALESCE(audience_row_id, 0), the column the unique
-- key needs: a NULL in a unique index is distinct from every other NULL.
-- The roadmap asks for a GENERATED column; this schema's migration tooling
-- (Core\Database\SqlParser, SchemaComparator) has no notion of a
-- generation expression and would create it as a plain, NULL column on a
-- fresh install, silently — the unique key then holding nothing. So it is
-- a plain NOT NULL column, written by Repository\NotifiedAccountRepository
-- and by nothing else (the same answer as rental_booking_modifications.
-- active_booking_id). It can never drift from audience_row_id: that column
-- is never updated, and its foreign key CASCADEs rather than going to NULL.
--
-- The CASCADE is the point: with SET NULL, purging an audience would bring
-- the rows of several children notified to the same parent account down to
-- (email, NULL, account) and break the unique key, failing the purge.
-- Nothing is lost: an audience is purged only once every send of it is
-- over, and its notifications are deleted in the same pass.
CREATE TABLE IF NOT EXISTS mass_mail_notified_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_id INT UNSIGNED NOT NULL,
    audience_row_id INT UNSIGNED NULL,
    audience_row_key INT UNSIGNED NOT NULL DEFAULT 0,
    user_account_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_mmna_once (email_id, audience_row_key, user_account_id),
    CONSTRAINT fk_mmna_email FOREIGN KEY (email_id) REFERENCES mass_mail_emails(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmna_audience_row FOREIGN KEY (audience_row_id) REFERENCES mass_mail_audience_rows(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmna_account FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE
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
