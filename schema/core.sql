-- Core schema
-- This file describes the complete desired state of the core database tables.
-- The migration runner compares this to the actual database and generates DDL accordingly.
-- NEVER create incremental migration files — edit this file directly.

CREATE TABLE scout_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desk_id VARCHAR(50) NOT NULL,
    -- Set when this row was merged INTO another identity: somebody was
    -- re-created in Desk instead of having their old record reopened, and
    -- a chef d'unité decided the two were the same person
    -- (Core\Member\Duplicate\MemberMergeService). The row is kept, never
    -- deleted — nothing in this codebase deletes a member — and everything
    -- that hung off it now hangs off the id below. A merge is not
    -- reversible, which is why the screen shows exactly what will move
    -- before it happens.
    merged_into_member_id INT UNSIGNED NULL,
    merged_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_desk_id (desk_id),
    INDEX idx_merged_into (merged_into_member_id),
    CONSTRAINT fk_members_merged_into FOREIGN KEY (merged_into_member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    first_name_encrypted BLOB,
    last_name_encrypted BLOB,
    password_hash VARCHAR(255),
    -- When password_hash was last set (initial set, account-page change, or
    -- password-reset link) — shown on the account page, never used for
    -- expiry/rotation logic.
    password_changed_at DATETIME,
    -- Every session issued for this account BEFORE this instant is treated
    -- as revoked on its next request (Core\Security\SessionRevalidator).
    -- Bumped whenever the credentials behind existing sessions change —
    -- today a password set/change/reset (UserAccountRepository::
    -- updatePasswordHash()). PHP's file-based sessions have no per-user
    -- registry to walk and expire, so revocation has to be a stamp the
    -- session re-checks itself against on every request. NULL (the value
    -- for every pre-existing row) revokes nothing, so adding this column
    -- never logs the whole unit out on deploy.
    sessions_valid_from DATETIME,
    is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
    -- Whether this account may still log in at all. FALSE refuses every
    -- authentication path at once (Core\Security\RoleResolver::
    -- isEmailAuthorizedToLogin(), which magic link, password, passkey and
    -- SessionRevalidator all go through), and the deactivation that sets it
    -- also bumps sessions_valid_from so a session already open falls on its
    -- next request rather than lingering for the cookie's 30 days. It is a
    -- withdrawal of access, never a deletion: the row keeps the password,
    -- the passkeys and the notification preferences, and every table
    -- referencing it (event_log, scheduled_actions, files.created_by, …)
    -- keeps pointing at something. DEFAULT TRUE, so existing rows are
    -- unaffected when this column is added on deploy.
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    -- Per-account overrides for Core\Notification\NotificationService's
    -- push dispatch (Configuration > Notifications preferences, "Mon
    -- compte"). NULL on both means "follow the global
    -- notifications_quiet_hours_start/end SettingService keys" — only a
    -- member who explicitly wants a different window sets these, and both
    -- are always set together (never one without the other). Discretion
    -- mode (off by default): when on, every push notification's title/body
    -- is substituted for a generic placeholder at composition time — the
    -- lock screen shows nothing identifying — while the in-app centre
    -- still shows the real text once the member is on the site.
    quiet_hours_start TIME,
    quiet_hours_end TIME,
    notification_discretion BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    UNIQUE INDEX idx_email_blind (email_blind_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE magic_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_blind_index CHAR(64) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    confirmed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_email_blind (email_blind_index),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- password_reset_tokens: same generation/hashing/single-use conventions as
-- magic_links (bin2hex(random_bytes(32)) hashed with password_hash() before
-- storage, consumed via the "used" flag) but a deliberately separate table —
-- a magic link logs the user straight in, this only ever lets them set a
-- new password, and the two have different validity windows (see
-- Core\Security\PasswordResetService).
CREATE TABLE password_reset_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_blind_index CHAR(64) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_email_blind (email_blind_index),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE editable_contents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_key VARCHAR(100) NOT NULL,
    content_type ENUM('rich_text', 'image') NOT NULL,
    content_value MEDIUMTEXT,
    module_id VARCHAR(50),
    modified_at DATETIME,
    modified_by INT UNSIGNED,
    UNIQUE INDEX idx_content_key (content_key),
    INDEX idx_module (module_id),
    CONSTRAINT fk_editable_modified_by FOREIGN KEY (modified_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relative_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    module_id VARCHAR(50),
    role_min VARCHAR(20) NOT NULL DEFAULT 'public',
    custom_resolver VARCHAR(100),
    encrypted BOOLEAN NOT NULL DEFAULT FALSE,
    -- Nullable owner scoping (Core\File\FileAccessGuard): when set, a
    -- request may only read this file if the session's linked members
    -- (members.id, not member_years.id — ownership outlives a single
    -- scout year) include this member, on top of the usual role_min
    -- check — never a replacement for it. Generic: any future
    -- member-scoped file (not just member_documents) gets this for free.
    owner_member_id INT UNSIGNED,
    -- Generic polymorphic ownership, alongside (not replacing) the
    -- owner_member_id special case above: Core\File\FileAccessGuard
    -- consults a registry of Core\File\FileOwnershipCheckerInterface
    -- implementations, keyed by owner_type, after its usual role_min
    -- check — so a brand-new feature (first consumer: section documents,
    -- owner_type = 'section_document', owner_id = section_documents.id)
    -- gets fine-grained access control without FileController or
    -- FileAccessGuard ever hardcoding anything feature-specific. No FK
    -- here — the referenced table varies by owner_type.
    owner_type VARCHAR(50) NULL,
    owner_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    UNIQUE INDEX idx_path (relative_path),
    INDEX idx_file_owner (owner_type, owner_id),
    CONSTRAINT fk_file_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_file_owner_member FOREIGN KEY (owner_member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE functions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desk_code VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'identified',
    confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_desk_code (desk_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desk_code VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    UNIQUE INDEX idx_desk_code (desk_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE age_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desk_code VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    -- Member page "branch card" (Core\Http\Controller\MemberController):
    -- federation logo (uploaded via the generic /upload flow, context
    -- "age_branch_logo") and the link to the federation's explanation
    -- page for that branch — both configurable per branch from
    -- Configuration > Config Desk (superadmin). logo_file_id is null
    -- until an admin uploads one; the page falls back to a shipped
    -- default asset (matched by canonicalSortOrder(), never by comparing
    -- the branch's free-text label), then to nothing.
    logo_file_id INT UNSIGNED,
    explanation_url VARCHAR(500) NOT NULL DEFAULT 'https://lesscouts.be/fr/site-parents/le-parcours-scout',
    UNIQUE INDEX idx_desk_code (desk_code),
    CONSTRAINT fk_age_branch_logo FOREIGN KEY (logo_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    age_branch_id INT UNSIGNED NOT NULL,
    desk_code VARCHAR(50) NOT NULL,
    name VARCHAR(100),
    email VARCHAR(255),
    -- Controls whether the section appears in any section picker across the
    -- site (Staffs, Trombinoscope, the public Sections page). Configurable
    -- from Configuration > Config Desk. Defaults to visible.
    is_visible BOOLEAN NOT NULL DEFAULT TRUE,
    -- Automatically recomputed on every Desk import: true when the section
    -- has at least one member this year, false otherwise. A section with no
    -- members is kept (never deleted) but excluded from every section picker
    -- until a later import gives it members again.
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    -- Explicit color override (hex, e.g. "#378ADD"), configurable from
    -- Configuration > Config Desk. Null means "no override" — the section
    -- falls back to its branch's canonical color (or the dedicated Staff
    -- d'U color) via Core\Member\SectionService::colorForSection(), the
    -- single source of truth every section picker/list across the site
    -- (Staffs, Trombinoscope, the calendar module, statistics) calls.
    color VARCHAR(7) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_desk_code (desk_code),
    CONSTRAINT fk_section_branch FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    first_name_encrypted BLOB NOT NULL,
    last_name_encrypted BLOB NOT NULL,
    gender_encrypted BLOB,
    birth_date_encrypted BLOB,
    phone_encrypted BLOB,
    mobile_encrypted BLOB,
    email_encrypted BLOB,
    email_blind_index CHAR(64),
    totem_encrypted BLOB,
    quali_encrypted BLOB,
    patrol_encrypted BLOB,
    formation_level VARCHAR(100),
    federation_mail_consent BOOLEAN NOT NULL DEFAULT FALSE,
    unit_mail_consent BOOLEAN NOT NULL DEFAULT FALSE,
    fee_category_id INT UNSIGNED,
    unit_code VARCHAR(50),
    -- Chief-adjustable shift applied on top of the birth-year-derived age when
    -- computing branch/year (see MemberYearService::getEffectiveAge). Operational
    -- flag, not personal data — stored in clear.
    scout_year_offset TINYINT NOT NULL DEFAULT 0,
    -- Handicap is health data (GDPR special category) → encrypted at rest.
    handicap_encrypted BLOB,
    -- Assurance complémentaire is administrative → stored in clear like formation_level.
    supplementary_insurance VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    -- Departure marking (Core\Member\DepartureService, ARCHITECTURE.md §8) —
    -- a chief/animateur flags a member as not returning next scout year,
    -- while Desk still lists them as active this year. Scoped to THIS row's
    -- scout year on purpose: it resets naturally every new import/year, no
    -- purge task needed. leaving_comment_encrypted is a free-text reason —
    -- often a sensitive one (conflict, family situation, health) — so it is
    -- BLOB + encrypted like handicap_encrypted above, decrypted only in
    -- Core\Member\DepartureRepository.
    leaving BOOLEAN NOT NULL DEFAULT FALSE,
    leaving_marked_at DATETIME,
    leaving_comment_encrypted BLOB,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_member_year (member_id, scout_year_id),
    -- (scout_year_id, is_active): the roster filter used across core and
    -- six modules. The scout_year_id prefix also serves fk_my_year, which
    -- the plain idx_scout_year used to do; SchemaComparator never drops
    -- an index, so installs that predate this composite simply keep both.
    INDEX idx_my_year_active (scout_year_id, is_active),
    INDEX idx_email_blind (email_blind_index),
    CONSTRAINT fk_my_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_my_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_my_fee FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_year_id INT UNSIGNED NOT NULL,
    address_type VARCHAR(50) NOT NULL,
    street_encrypted BLOB,
    number_encrypted BLOB,
    box_encrypted BLOB,
    complement_encrypted BLOB,
    postal_code_encrypted BLOB,
    city_encrypted BLOB,
    country_encrypted BLOB,
    -- Blind index (HMAC) of Core\Member\AddressNormalizer::normalize()'s
    -- comparison form of street+number+box+postal_code — never a readable
    -- value, exact-match only (Core\Member\FeeEstimationService, §8), same
    -- technique as every other blind-indexed field. Populated at import
    -- time (Core\Import\DeskImportService) going forward; existing rows
    -- were retroactively filled once by the temporary Core\Member\
    -- AddressBlindIndexBackfill (removed at iteration 3).
    address_normalized_blind_index CHAR(64),
    CONSTRAINT fk_ma_member_year FOREIGN KEY (member_year_id) REFERENCES member_years(id) ON DELETE CASCADE,
    INDEX idx_ma_address_blind (address_normalized_blind_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_functions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_year_id INT UNSIGNED NOT NULL,
    function_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED,
    age_branch_id INT UNSIGNED,
    start_date DATE,
    end_date DATE,
    mandate_end DATE,
    is_main_function BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_mf_member_year FOREIGN KEY (member_year_id) REFERENCES member_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_mf_function FOREIGN KEY (function_id) REFERENCES functions(id),
    CONSTRAINT fk_mf_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_mf_branch FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section membership as periods, tied to the persistent members entity
-- (not member_years — a period can span a scout-year boundary while a
-- member stays in the same section). member_functions is an annual
-- snapshot overwritten wholesale on every Desk import
-- (MemberYearRepository::replaceFunctions()), so a mid-year section
-- change within the same scout year was previously lost — this table is
-- the history member_functions never kept. A NULL end_date means the
-- period is still open. Written by Core\Member\SectionMembershipService,
-- called from Core\Import\DeskImportService right after each member's
-- functions are replaced: any section no longer in the member's function
-- set is closed (end_date = the import date), any new section gets a
-- freshly opened period (start_date = the import date) — sections
-- unchanged since the last import are left untouched, so re-running the
-- same CSV creates nothing new. A period from an earlier scout year that
-- is still open when a later year's import runs (i.e. scout-year
-- rollover was never explicitly triggered) is closed at that earlier
-- year's own end_date, never at today's import date — "active in
-- section S for scout year Y" (Core\Member\SectionDocumentOwnershipChecker,
-- the Staffs/member-page section documents feature) means Y's configured
-- reference date falls within a period for (member, S, Y).
CREATE TABLE member_section_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msp_member (member_id),
    INDEX idx_msp_member_section_year (member_id, section_id, scout_year_id),
    INDEX idx_msp_open (member_id, end_date),
    CONSTRAINT fk_msp_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_msp_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_msp_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Section documents (camp booklets, activity sheets, material lists) —
-- managed by staff from the Staffs page, shown read-only on the member
-- page for every section/year a member was active in (per
-- member_section_periods above). No personal data column on this table
-- itself — the file's content is what may hold personal data, which is
-- why it is encrypted at rest (Core\File\EncryptedFileStorageService)
-- and access-gated via Core\File\FileAccessGuard's generic ownership
-- registry (files.owner_type = 'section_document', owner_id = this row's
-- id — see Core\Member\SectionDocumentOwnershipChecker), never by the
-- file's role_min alone.
CREATE TABLE section_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description VARCHAR(1000) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    -- Core\Pdf\PdfCompressor background pass (core/compress_section_document
    -- scheduled task) — 'pending' until the task runs, then 'compressed' or
    -- 'skipped' (no backend available, output not smaller, or not a PDF at
    -- all). The original stays downloadable throughout; the file is only
    -- ever swapped for a smaller one that still starts with the PDF magic
    -- bytes.
    compression_status ENUM('pending', 'compressed', 'skipped') NOT NULL DEFAULT 'pending',
    size_before_bytes INT UNSIGNED NULL,
    size_after_bytes INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_sd_section_year (section_id, scout_year_id),
    CONSTRAINT fk_sd_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_sd_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_sd_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_sd_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per Desk import, and the parent row of everything that import
-- produced: the CSV it consumed, the roster snapshot it froze, and (from
-- the diff onwards) the report it computed. One object, one lifecycle, one
-- purge — a kept file whose snapshot has been purged, or the reverse, is
-- half a dossier and answers nothing.
-- One row per Desk import, and the parent row of everything that import
-- produced: the CSV it consumed, the roster snapshot it froze, and the
-- diff it computed. One object, one lifecycle, one purge — a kept file
-- whose snapshot has been purged, or the reverse, is half a dossier and
-- answers nothing.
CREATE TABLE import_journal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    user_account_id INT UNSIGNED,
    line_count INT UNSIGNED NOT NULL,
    member_count INT UNSIGNED NOT NULL,
    new_functions_count INT UNSIGNED NOT NULL DEFAULT 0,
    -- The Desk CSV this import consumed, kept so a doubtful import can be
    -- investigated by replaying its report against the exact file that
    -- produced it. Encrypted at rest through Core\File\
    -- EncryptedFileStorageService, role_min 'admin', served only by
    -- /files/{id} under FileAccessGuard, and every successful download
    -- journaled (SECURITY.md §13). NULL for an import taken before the
    -- file was kept, and for one whose retention window has passed.
    file_id INT UNSIGNED NULL,
    -- What this import changed, compared with the one before it in the
    -- same scout year: Core\Import\ImportDiff, computed once at the end of
    -- the import and never recomputed. A dated, frozen fact — "12 members
    -- added, 7 gone on 3 September" will never become anything else —
    -- which is exactly what lets the report page stay honest months
    -- later. Foreign keys and codes only, never a name.
    --
    -- NULL only for an import that predates diffs. An import that HAD
    -- nothing to compare against (the season's first, or a predecessor
    -- the retention purge has taken) stores an explicitly unavailable
    -- diff instead, so the report can say "no point of comparison"
    -- rather than presenting 260 arrivals as a movement.
    diff_json JSON NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ij_year_imported (scout_year_id, imported_at),
    CONSTRAINT fk_ij_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ij_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_ij_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the Desk roster CONTAINED at a given moment — one row per import.
--
-- Nothing else in the site keeps it. `member_years` is overwritten
-- wholesale at every import (`MemberYearRepository::upsert()`,
-- `deactivateAllForYear()`), so without a snapshot the only roster the
-- site can ever describe is today's. An invoice from the federation
-- reflects Desk on the day it was issued, and checking February's invoice
-- against March's roster manufactures differences that were never real —
-- the kind of false alarm that gets a verification tool abandoned.
--
-- **These two tables carry a `fees_` prefix and are not the `fees`
-- module's.** That module was the first thing that needed them, so they
-- were born in `modules/fees/schema.sql`; a core that needs an optional
-- module in order to describe its own import is the inversion
-- `ARCHITECTURE.md` §7.4 forbids, so they moved here and
-- `Core\Import\DeskImportService` now takes the snapshot itself — present
-- whatever the module configuration. Same history as
-- `EncryptedFileStorageService`, born of finance receipts, and
-- `list_editor`, born of the banners. The names kept their prefix
-- deliberately: renaming them would strand `fees_invoices`' foreign key on
-- any installation that already has one, and this schema's migration
-- runner never drops a table or a constraint (see `SchemaComparator`).
--
-- **No personal data, deliberately.** Every column below is a foreign key
-- or a code. Names and birth dates stay where they already are, in
-- `member_years`, which persists for the whole scout year even for a
-- member gone inactive — so a snapshot row joins back to a readable person
-- through (member_id, the snapshot's scout_year_id) whenever a screen
-- genuinely needs one. Nothing here is a BLOB, nothing here is encrypted.

CREATE TABLE fees_roster_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    -- The import that froze this composition. The direction of the link
    -- is deliberate: `import_journal` is the parent row, and a snapshot
    -- goes when its import goes. NULL only for a snapshot taken before
    -- imports became that parent row.
    import_journal_id INT UNSIGNED NULL,
    -- When the import that produced this snapshot ran. Compared against an
    -- invoice's own date, and the gap between the two is shown rather than
    -- hidden: one day of drift is enough to produce differences that are
    -- not differences.
    taken_at DATETIME NOT NULL,
    -- Denormalised on purpose: the count is read on a list of snapshots,
    -- one row per import, and counting the members of each would be one
    -- query per line.
    member_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_frs_year_taken (scout_year_id, taken_at),
    INDEX idx_frs_import (import_journal_id),
    CONSTRAINT fk_frs_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_frs_import FOREIGN KEY (import_journal_id) REFERENCES import_journal(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fees_roster_snapshot_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT UNSIGNED NOT NULL,
    -- The persistent identity, not member_years.id: the snapshot's own
    -- scout_year_id already says which year, and members.id is what an
    -- invoice line is eventually matched back to.
    member_id INT UNSIGNED NOT NULL,
    -- The fee category encoded in Desk at that moment — the thing an
    -- invoice line is checked against. NULL when Desk had none.
    fee_category_id INT UNSIGNED NULL,
    -- The section of the member's main function (or, when Desk flagged
    -- none, their first function). NULL for someone with no function at
    -- all, and for the fee lines that legitimately carry no section.
    section_id INT UNSIGNED NULL,
    -- The site role that function resolves to (identified/intendant/
    -- chief/admin…), which is what separates an animé from a staff member
    -- on an invoice line. A code, never a label.
    function_role VARCHAR(20) NULL,
    -- That same function's own id. The role above answers "what access
    -- does this person have"; this answers "what do they DO", and the two
    -- move independently — a member can change function without changing
    -- role. Added for the import diff (Core\Import\ImportDiffCalculator),
    -- which reports both. NULL for a snapshot taken before it existed.
    function_id INT UNSIGNED NULL,
    -- Desk's own formation wording, verbatim, in clear exactly as it is on
    -- member_years — this is what the "réduction animateur breveté" line of
    -- an invoice is cross-checked against.
    formation_level VARCHAR(100) NULL,
    -- Recorded as it stood, NEVER used as a filter here. Desk still holds a
    -- member marked leaving, and the federation still bills them; deciding
    -- what to do with the flag belongs to whoever reads the snapshot. A
    -- snapshot that filtered could not answer "what did Desk contain".
    leaving BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE INDEX idx_frsm_snapshot_member (snapshot_id, member_id),
    INDEX idx_frsm_snapshot_section (snapshot_id, section_id),
    CONSTRAINT fk_frsm_snapshot FOREIGN KEY (snapshot_id) REFERENCES fees_roster_snapshots(id) ON DELETE CASCADE,
    -- No ON DELETE on the four below, deliberately: nothing in this
    -- codebase deletes a member, a section, a function or a fee category,
    -- and a snapshot that quietly lost rows when something did would be a
    -- history that lies. A refused DELETE is the better failure.
    CONSTRAINT fk_frsm_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_frsm_fee FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
    CONSTRAINT fk_frsm_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_frsm_function FOREIGN KEY (function_id) REFERENCES functions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The Desk identifiers an identity has answered to, beyond its current
-- one.
--
-- Indispensable rather than convenient: without it, the abandoned code
-- reappearing in a later CSV would create a brand-new `members` row again
-- and re-open the split a merge had just repaired — silently, and for the
-- same reason as the first time. `MemberRepository::findByDeskId()`
-- consults this table, so a row here is what makes the repair stick.
CREATE TABLE member_desk_id_aliases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    desk_id VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    -- Unique across the table AND distinct from every members.desk_id in
    -- practice: an alias only ever comes from a row that has just been
    -- merged away, and that row keeps its own desk_id.
    UNIQUE INDEX idx_mdia_desk_id (desk_id),
    INDEX idx_mdia_member (member_id),
    CONSTRAINT fk_mdia_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mdia_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pairs of `members` rows that look like the same person split in two.
--
-- The mistake is banal and its damage is silent: somebody leaves, comes
-- back a year later, and is created as a NEW person in Desk instead of
-- having their old record reopened. New desk_id, new `members` row — and
-- photos, badges, private documents, section periods, totem and
-- owner-scoped files all stay attached to the abandoned identity, so the
-- returning member's page is empty and nothing says why.
--
-- Detected after an import commits (never inside it: the comparison
-- decrypts names and birth dates in bulk, `Core\Member\Duplicate\
-- DuplicateMemberDetector`), on the members that import CREATED, against
-- the member_years of EARLIER scout years. Desk guarantees desk_id
-- uniqueness, so the problem is strictly inter-year and there is nothing
-- to look for within one season.
--
-- **A candidate is a proposal, never a decision.** Two people can share a
-- name and a birth date, so a human decides — which is also why the row
-- has a `distinct` outcome and not only a `merged` one: "these are two
-- different people" is a decision too, and one the site must remember,
-- or every import would re-propose the same pair for ever.
--
-- No personal data: two member ids, a flag, and what somebody decided.
CREATE TABLE member_duplicate_candidates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- The identity that existed first, and the one a merge keeps.
    kept_member_id INT UNSIGNED NOT NULL,
    -- The one the import just created.
    duplicate_member_id INT UNSIGNED NOT NULL,
    -- Secondary signal for telling a real duplicate from two namesakes:
    -- the two identities share a normalised address blind index
    -- (member_addresses, §8). Never decisive on its own — siblings share
    -- an address too — and never the reason a pair is proposed.
    same_address BOOLEAN NOT NULL DEFAULT FALSE,
    status ENUM('pending', 'merged', 'distinct') NOT NULL DEFAULT 'pending',
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    UNIQUE INDEX idx_mdc_pair (kept_member_id, duplicate_member_id),
    INDEX idx_mdc_status (status),
    CONSTRAINT fk_mdc_kept FOREIGN KEY (kept_member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mdc_duplicate FOREIGN KEY (duplicate_member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mdc_decided_by FOREIGN KEY (decided_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webauthn_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    credential_id VARBINARY(255) NOT NULL,
    public_key BLOB NOT NULL,
    sign_count INT UNSIGNED NOT NULL DEFAULT 0,
    device_label VARCHAR(100),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME,
    UNIQUE INDEX idx_credential_id (credential_id),
    INDEX idx_user_account (user_account_id),
    CONSTRAINT fk_wc_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_blind_index CHAR(64) NOT NULL,
    -- Blind index (never the raw address) of the client IP the attempt came
    -- from, so Core\Security\LoginThrottler can also slow a spray across
    -- MANY accounts from one source — which per-email counting alone never
    -- sees. Same HMAC treatment as every other searchable personal datum
    -- (SECURITY.md §5); NULL when no IP was available.
    ip_blind_index CHAR(64),
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email_blind_index, attempted_at),
    INDEX idx_ip_time (ip_blind_index, attempted_at),
    -- LoginThrottler::purgeStale() deletes by age alone; without this the
    -- minute-ly purge would scan the whole table.
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(50),
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    -- The value SettingRepository::upsert() was called with (i.e. the
    -- declared default from the register() call site) — kept in sync on
    -- every boot, even for rows that already existed, so
    -- Core\Maintenance\Task\ResetSettingsHandler can restore it later
    -- without needing to know what any given module's default "should" be.
    default_value TEXT,
    setting_type VARCHAR(20) NOT NULL DEFAULT 'text',
    label VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    validation_regex VARCHAR(255),
    select_options JSON,
    editable BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE INDEX idx_module_key (module_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_account_id INT UNSIGNED,
    ip_address VARCHAR(45),
    category VARCHAR(50) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    -- 'error' is written by Core\Http\ErrorHandler for an uncaught
    -- throwable, so a crash is consultable from /admin/journal and not
    -- only from whatever file error_log() happens to write to on a
    -- shared host (ARCHITECTURE.md §8.6).
    --
    -- 'warning' was added later, and not as a nicety: fourteen call sites
    -- across core and five modules were already writing it — a rejected
    -- statistics report, a booking mail that would not send, a mailbox
    -- over quota — and MySQL under STRICT_TRANS_TABLES refuses a value
    -- outside an ENUM, so every one of those paths threw a PDOException
    -- on a real installation while passing in tests, where SQLite's
    -- `level` is a plain TEXT. The endpoint that made it visible is the
    -- worst of them: the statistics intake is `role_min: public`, so its
    -- rejection path turned a bad request into a 500.
    level ENUM('info', 'warning', 'security', 'error') NOT NULL DEFAULT 'info',
    description VARCHAR(500) NOT NULL,
    context JSON,
    INDEX idx_logged_at (logged_at),
    INDEX idx_category (category),
    INDEX idx_level (level),
    INDEX idx_user (user_account_id),
    INDEX idx_ip (ip_address),
    CONSTRAINT fk_el_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The photo of an identified LOGIN — what "Mon compte" sets, and what the
-- shared avatar draws wherever the site shows the person who is connected
-- (Core\Photo\AccountPhotoService, person_avatar() in Core\View\TwigFactory).
--
-- Its own table rather than a column on user_accounts, and deliberately
-- NOT scout-year-scoped, unlike member_photos below: a login is a person,
-- not a membership, and a face does not become wrong in September. One row
-- per account, replaced in place — the previous photo is a `files` row that
-- goes when it is replaced (Core\Photo\AccountPhotoService::setPhoto()).
--
-- No personal data of its own: an account id and a file id. The picture
-- itself is a `files` row like every other upload, served through
-- /files/{id} with role_min 'identified' (Core\File\FileAccessGuard), never
-- from under public/.
CREATE TABLE user_account_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_uap_account (user_account_id),
    CONSTRAINT fk_uap_account FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_uap_file FOREIGN KEY (file_id) REFERENCES files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic "photo per person per year" core component (ARCHITECTURE.md §8):
-- one row per (member, scout_year). Reused anywhere a person's photo needs to
-- track the site's current scout year — not specific to any one module.
CREATE TABLE member_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    UNIQUE INDEX idx_member_year (member_id, scout_year_id),
    CONSTRAINT fk_mp_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mp_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_mp_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_mp_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Photo of the staff" (all chiefs of a section, together) shown on the
-- Staffs page — same "one per person per year, fall back to the most
-- recent earlier one" precedent as member_photos above, just keyed by
-- section instead of member. See Core\Photo\SectionPhotoService.
CREATE TABLE section_staff_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    UNIQUE INDEX idx_section_year (section_id, scout_year_id),
    CONSTRAINT fk_ssp_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_ssp_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ssp_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_ssp_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual check-off state for the "Année scoute" transition workflow
-- (Core\ScoutYear\ScoutYearTransitionService). Most of that workflow's
-- steps are derived from a real signal the site can observe — members
-- imported, staff year set, every section photographed — and store
-- nothing here. This table exists for the steps whose work happens
-- somewhere the site cannot see at all (encoding into Desk, updating the
-- fees) or whose completion is a human judgement call (badges assigned,
-- trombinoscope reviewed): the only way those can ever read as done is
-- for someone to say so.
--
-- A row's presence *is* the "done" state; un-ticking deletes it. Keyed by
-- scout year, so next year's workflow starts blank on its own without any
-- reset step, exactly like the Départs marks the registration module
-- clears at each import.
--
-- step_key is a stable string (never a step number) so reordering or
-- inserting a step in the workflow never silently re-points an existing
-- row at a different step.
CREATE TABLE scout_year_transition_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    step_key VARCHAR(50) NOT NULL,
    done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    done_by INT UNSIGNED,
    UNIQUE INDEX idx_year_step (scout_year_id, step_key),
    CONSTRAINT fk_syts_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_syts_done_by FOREIGN KEY (done_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private per-member documents (member page "Documents privés" —
-- Core\Member\MemberDocumentService), e.g. a future fiscal attestation.
-- The file itself is stored encrypted-at-rest (Core\File\
-- EncryptedFileStorageService) with files.owner_member_id set to
-- member_id, which is what Core\File\FileAccessGuard actually enforces
-- download access on — this table is metadata only (title, which member/
-- year it belongs to), never a second access-control path. Storage +
-- listing only in this iteration; no generation or admin upload UI yet.
CREATE TABLE member_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_md_member (member_id),
    CONSTRAINT fk_md_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_md_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_md_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_md_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- member_emails: additional email addresses for a member, beyond the one
-- imported from Desk (member_years.email_encrypted) — tied to the
-- persistent members entity, not member_years, so the list survives a
-- scout year change exactly like desk_id. Two sources:
-- - 'manual': added by the member themselves from their own member page.
--   Starts 'pending' until the emailed confirmation link is clicked (same
--   token scheme as magic_links: bin2hex(random_bytes(32)), hashed with
--   password_hash() before storage, 48h expiry — see Core\Member\
--   MemberEmailService::addEmail()/confirmEmail()).
-- - 'desk': a status override for the CURRENT Desk-imported address
--   itself. The Desk address is otherwise always usable (member_years'
--   own column, never touched here) and never editable/deletable by the
--   member — this row only ever exists to record that address having
--   been unsubscribed from mass-mail (status='inactive') and lets the
--   member reactivate it from their own page, same as a manual address.
--   Lazily created (find-or-create, keyed by the unique index below) only
--   the first time that exact address is actually unsubscribed — never
--   proactively synced at Desk import, so a changed Desk email simply
--   starts fresh with no row here until/unless it's unsubscribed too.
-- status: 'pending' (manual only, awaiting confirmation), 'valid'
-- (receives mass-mail; a manual address also grants login — see
-- Core\Security\RoleResolver), 'inactive' (mass-mail opt-out only, via
-- the unsubscribe mechanism — never reachable by the member's own UI
-- directly, only via delete-then-nothing or the unsubscribe link/one-
-- click endpoint; reactivating goes straight back to 'valid', no
-- reconfirmation).
CREATE TABLE member_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    source ENUM('manual', 'desk') NOT NULL DEFAULT 'manual',
    status ENUM('pending', 'valid', 'inactive') NOT NULL DEFAULT 'pending',
    confirmation_token_hash VARCHAR(255),
    confirmation_expires_at DATETIME,
    -- Resend cooldown (module addendum: once every 5 minutes per address) —
    -- read from this column, never from session, so it holds across
    -- devices/tabs.
    last_confirmation_sent_at DATETIME,
    confirmed_at DATETIME,
    deactivated_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Duplicate email within the same member (any status) must reuse the
    -- existing row rather than create a second one — enforced here, not
    -- only in Service\MemberEmailService.
    UNIQUE INDEX idx_me_member_blind (member_id, email_blind_index),
    INDEX idx_me_blind (email_blind_index),
    CONSTRAINT fk_me_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transversal roles assignable to chiefs/chief-d'unité (e.g. Infirmier,
-- Trésorier), configured once in Configuration générale and displayed on the
-- trombinoscope. See Core\Badge.
CREATE TABLE badges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    -- Default badges (Infirmier, Trésorier) are seeded automatically and can
    -- never be deleted — only deactivated. Also true for the auto-generated
    -- "Référent {section}" badges below, for the same read-only-name/
    -- non-deletable behavior.
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    -- A deactivated badge is invisible everywhere and no longer assignable,
    -- but existing member_badges assignments are preserved.
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    -- Non-null exactly for an auto-generated "Référent {section}" badge —
    -- one per visible, non-Staff-d'U section (Core\Badge\BadgeService::
    -- syncSectionReferentBadges()), kept in sync with that section's name/
    -- visibility, and assignable only to Staff d'U members.
    referent_section_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_name (name),
    UNIQUE INDEX idx_referent_section (referent_section_id),
    CONSTRAINT fk_badges_referent_section FOREIGN KEY (referent_section_id) REFERENCES sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Badge assignment: per member_year, so it's naturally scoped to a scout
-- year (see member_years) — the same member holds independent badge
-- assignments across different years, and history is preserved even after
-- a member_year is deactivated by a later import.
CREATE TABLE member_badges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_year_id INT UNSIGNED NOT NULL,
    badge_id INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED,
    UNIQUE INDEX idx_member_badge (member_year_id, badge_id),
    CONSTRAINT fk_mb_member_year FOREIGN KEY (member_year_id) REFERENCES member_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_mb_badge FOREIGN KEY (badge_id) REFERENCES badges(id),
    CONSTRAINT fk_mb_assigned_by FOREIGN KEY (assigned_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE module_registry (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(100) NOT NULL UNIQUE,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    installed_version VARCHAR(20) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    enabled_at DATETIME,
    enabled_by INT UNSIGNED,
    FOREIGN KEY (enabled_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(50) NOT NULL,
    task_key VARCHAR(100) NOT NULL,
    reference VARCHAR(200),
    payload JSON,
    run_at DATETIME NOT NULL,
    status ENUM('pending', 'processing', 'done', 'failed', 'canceled') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT,
    -- The account that triggered this task, if any (a human clicking
    -- "generate now") — NULL for tasks scheduled automatically (daily
    -- cron, no human requester to notify). SchedulerRunner::processOverdue()
    -- copies this into the payload passed to TaskHandlerInterface::handle()
    -- under the reserved 'requested_by_user_account_id' key, so any handler
    -- can call $context->notifications->notify() without every caller of
    -- SchedulerService::schedule()/scheduleAfter() having to remember to
    -- thread it through their own payload.
    requested_by_user_account_id INT UNSIGNED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME,
    INDEX idx_status_run (status, run_at),
    INDEX idx_module_task (module_id, task_key),
    INDEX idx_module_ref (module_id, task_key, reference),
    CONSTRAINT fk_sa_requested_by FOREIGN KEY (requested_by_user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Web Push (Core\Notification, RFC 8030) — one row per subscribed device.
-- A user can have several (phone, laptop, ...); each is registered/dropped
-- independently by the browser's own PushManager. endpoint is a stable
-- identifier tied to a natural person's device, so — unlike Iteration 1,
-- which stored it as plaintext TEXT — it is encrypted like any other
-- personal-data field, with endpoint_blind_index alongside for the exact-
-- match lookups a WHERE on the encrypted column could never do (see
-- SECURITY.md §5, "never write WHERE on an encrypted field"). auth_key/
-- p256dh_key are per-device cryptographic secrets the browser generates
-- for message encryption (RFC 8291) — not personal data themselves, but
-- still encrypted at rest since they grant the ability to push to that
-- device. device_label (e.g. "Chrome sur Android", best-effort parsed
-- from the subscribing request's User-Agent) lets a member tell their
-- devices apart in "Mes appareils" without needing to decrypt anything for
-- display. last_success_at/failure_count back the Web Push sender's
-- dead-subscription cleanup (Core\Notification\NotificationService::
-- dispatchPush() deletes a subscription on 404/410 immediately, or once
-- failure_count crosses its threshold on repeated other failures — a
-- success anywhere resets it to 0). Tied to user_accounts, never to
-- members — a push subscription belongs to a browser session, not a
-- scout profile.
CREATE TABLE push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    endpoint BLOB NOT NULL,
    endpoint_blind_index CHAR(64) NOT NULL,
    auth_key BLOB NOT NULL,
    p256dh_key BLOB NOT NULL,
    device_label VARCHAR(200),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_success_at DATETIME,
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_ps_user (user_account_id),
    INDEX idx_ps_endpoint (endpoint_blind_index),
    CONSTRAINT fk_ps_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History of notifications dispatched via Core\Notification\
-- NotificationService::dispatch() (one row per recipient/member pair,
-- regardless of how many devices — or zero — actually received a push for
-- it; see notification_preferences below for per-recipient channel
-- resolution). type_id is the declared notification type this row was
-- sent for (Core\Notification\NotificationRegistry or a module's
-- module.json "notifications" section — see their own comments) — sending
-- an undeclared type is rejected at dispatch time, never silently
-- accepted. member_id is nullable and names the member this notification
-- is ABOUT, not who receives it (that's user_account_id) — a parent linked
-- to three children each getting a notification about their own activity
-- gets three separate rows, one per child. title/body are frozen text
-- composed once at send time (never re-rendered from live data later) and
-- almost always name a totem or a member — encrypted at rest per
-- SECURITY.md §5 even though the columns aren't in its nominative list,
-- since their CONTENT routinely is personal data even if the column
-- itself isn't. url is a same-origin path only ("/gallery/42"), never
-- personal data, so it stays plain. No scout_year_id — a notification is
-- a dated event, not an annual state, same reasoning as calendar_events/
-- sos_oncall_assignments (AGENTS.md "Database" section).
-- email_sent_at stamps the moment the email copy of this notification left,
-- for the recipients whose `email` channel resolved on at dispatch time
-- (Core\Notification\NotificationService). It is the idempotency guard, not
-- a delivery receipt: the scheduled sender claims a row by stamping it
-- BEFORE handing it to the mail transport, so a handler that is rescheduled
-- mid-batch, or run twice, can never send the same notification to somebody
-- a second time. NULL therefore means "no email copy was sent" for both
-- reasons — the channel was off, or the send has not happened yet — which is
-- all any caller needs to know. The push channel needs no equivalent: a
-- duplicate push replaces its predecessor in the device's tray, a duplicate
-- email does not.
CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED,
    type_id VARCHAR(100) NOT NULL,
    title BLOB NOT NULL,
    body BLOB NOT NULL,
    url VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME,
    email_sent_at DATETIME,
    INDEX idx_notif_user (user_account_id),
    INDEX idx_notif_user_unread (user_account_id, read_at),
    INDEX idx_notif_type (type_id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-account, per-type channel overrides (Configuration > Notifications
-- preferences page). Absence of a row for a given (user_account_id,
-- type_id) means "use the type's declared default for every channel" —
-- rows are never pre-populated for every member/type combination, only
-- created the first time a member actually changes a channel away from
-- its default. Each channel column is independently nullable: NULL means
-- "not customized, still follows the type default" even when the row
-- exists because another channel of the SAME type was customized — a
-- member toggling only "push" for a type must not silently pin "in_app"/
-- "email" to whatever their current default happened to be. A channel the
-- type declares locked (`"on"`/`"off"` in module.json, forced) is never
-- writable here regardless of what a request sends — enforced in
-- Core\Notification\NotificationPreferenceService, not by a DB constraint.
CREATE TABLE notification_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    type_id VARCHAR(100) NOT NULL,
    in_app TINYINT(1),
    push TINYINT(1),
    email TINYINT(1),
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_np_user_type (user_account_id, type_id),
    CONSTRAINT fk_np_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- On-demand backups (Core\Maintenance\BackupService), Configuration >
-- Maintenance. file_id/db_dump_file_id reference the generic files table
-- (served via FileAccessGuard, /files/{id}, role_min admin) rather than
-- owning file storage directly — a backup IS a file like any other, just
-- one this app generated instead of a user uploading it. db_dump_file_id
-- is only ever set for a 'full_*'/'auto_*' backup (the standalone
-- database-only export, kept separate so a full backup's DB portion can
-- be restored on its own without touching the file archive) — a
-- 'database'-type backup instead uses file_id directly for its one dump
-- file. requested_by is NULL for backups the app creates on its own
-- (auto_update/auto_reset, iterations 3/4) — no admin to notify.
CREATE TABLE backups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('database', 'full_config', 'full_no_gallery', 'full_with_gallery', 'auto_update', 'auto_reset', 'auto_backup') NOT NULL,
    file_id INT UNSIGNED,
    db_dump_file_id INT UNSIGNED,
    status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    requested_by INT UNSIGNED,
    error_message VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    INDEX idx_backups_created (created_at),
    CONSTRAINT fk_backups_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL,
    CONSTRAINT fk_backups_db_dump_file FOREIGN KEY (db_dump_file_id) REFERENCES files(id) ON DELETE SET NULL,
    CONSTRAINT fk_backups_requested_by FOREIGN KEY (requested_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per "Installer la mise à jour" run (Core\Maintenance\Task\
-- InstallUpdateHandler). backup_id points at the automatic safety backup
-- (type 'auto_update' in the `backups` table above) taken before the
-- install starts — used to roll back if any step from downloading through
-- the VERSION file write fails. requested_by is who clicked "Installer"
-- (always a human here, unlike backups.requested_by).
CREATE TABLE update_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_from VARCHAR(20) NOT NULL,
    version_to VARCHAR(20) NOT NULL,
    status ENUM('pending', 'backing_up', 'downloading', 'installing', 'migrating', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
    dependencies_changed BOOLEAN NOT NULL DEFAULT FALSE,
    error_message VARCHAR(500),
    backup_id INT UNSIGNED,
    requested_by INT UNSIGNED,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Last sign of life, not last status change: every step of an install
    -- stamps it, and so does every migration slice run for it. The
    -- abandoned-update watchdog (Core\Maintenance\UpdateHistoryRepository
    -- ::STALE_AFTER_MINUTES) measures from here rather than from
    -- started_at, so an update that is genuinely still working is never
    -- declared abandoned purely for having taken a while. NULL on rows
    -- that predate the column; the watchdog falls back to started_at.
    progress_at DATETIME,
    completed_at DATETIME,
    INDEX idx_update_history_started (started_at),
    CONSTRAINT fk_update_history_backup FOREIGN KEY (backup_id) REFERENCES backups(id) ON DELETE SET NULL,
    CONSTRAINT fk_update_history_requested_by FOREIGN KEY (requested_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic short-URL redirector (Core\Url\ShortUrlService) — not tied to any
-- module. target_url is always an internal path (e.g. /news/12), resolved
-- and 302-redirected by GET /s/{code}. Introduced for the news module's
-- poster QR codes and confirmation-email edit links, but reusable by any
-- future module that needs a short, typeable/scannable link.
-- target_url is encrypted at rest: several callers shorten URLs that
-- embed bearer tokens (a retro board's /r/{token}, a news response's
-- edit link), so a plaintext column would hand working credentials to
-- any DB read even after the tokens themselves are protected. Lookup is
-- always by code, so no blind index is needed.
CREATE TABLE short_urls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,
    target_url_encrypted BLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    CONSTRAINT fk_su_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core\Security\HumanCheck: generic anti-bot protection for a public form
-- submitted by a non-identified session (ARCHITECTURE.md §8) — the third
-- barrier's per-IP sliding-window counter. Short-lived rows only, purged
-- by Task\PurgeHumanCheckRateLimitsHandler once past the configured
-- window. ip_hash is a one-way HMAC (Core\Security\EncryptionService::
-- blindIndex(), same technique as every other blind-indexed lookup in
-- this codebase, e.g. modules/retro's retro_rate_limits) — the real IP
-- address, personal data, is never stored.
CREATE TABLE human_check_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    form_key VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_human_check_rate_limits_lookup (ip_hash, form_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- entity_changes: the per-entity change history any module can render on
-- an entity's own page (ARCHITECTURE.md §8.65). Deliberately NOT the
-- journal: `event_log` is a global administrative log that forbids
-- personal data and has no entity anchor, so it can answer "what happened
-- on this installation" but never "what happened to THIS camp". Both
-- coexist; a sensitive action typically writes to each, one line for the
-- administrator and one for the entity's own timeline.
--
-- entity_type/entity_id mirror the files.owner_type/owner_id pair: a
-- loose reference with no FK, because the referenced table varies by
-- entity_type and a module's tables come and go with the module.
CREATE TABLE entity_changes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,

    -- Machine name of what changed ('price', 'status', 'dates'), never the
    -- label shown to a reader: the module owns the wording and can change
    -- it without rewriting its history.
    field_key VARCHAR(60) NOT NULL,

    -- Encrypted unconditionally, including values that are not personal
    -- data in themselves (a price, a status). One uniform rule beats a
    -- per-field classification nobody maintains — and a history is exactly
    -- where an innocuous-looking field ends up carrying a name someone
    -- typed into it. The accepted cost is that this table is NOT
    -- searchable or filterable on its values; it is read one entity at a
    -- time, newest first, which needs no index on them.
    from_value BLOB NULL,
    to_value BLOB NULL,

    -- An optional human sentence ("Contact ajouté"), shown under the
    -- from → to line when the change needs words the two values can't
    -- carry on their own.
    summary BLOB NULL,

    -- Who or what produced the change. 'human' is someone acting in the
    -- interface; 'email' an inbound message; 'ai' a model's suggestion a
    -- human accepted; 'system' the application on its own.
    source ENUM('human', 'email', 'ai', 'system') NOT NULL DEFAULT 'human',

    -- Opaque to this table: whatever lets the recording module point back
    -- at the origin (an inbound message id, a task run). Never rendered
    -- raw — the module turns it into a link if it wants one.
    source_reference VARCHAR(190) NULL,

    -- Null means nobody did it: an automatic entry, which the timeline
    -- renders differently. Kept distinct from "the account was deleted"
    -- only by the source column, which is why source is NOT NULL.
    actor_user_account_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_entity_changes_entity (entity_type, entity_id, created_at),
    CONSTRAINT fk_entity_changes_actor
        FOREIGN KEY (actor_user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- member_notes: dated, free-text staff notes about one PERSON — the
-- « Notes internes » block of the admin member page (/admin/members/{id},
-- Core\Member\MemberNoteService). Nothing on the site covered this
-- before: registration_requests.internal_notes_encrypted covers a
-- registration request, and nothing covered the person once they became
-- a member.
--
-- Keyed on members.id, the PERSISTENT identity, never member_years.id —
-- a note about a person survives the scout year that saw it written,
-- same reason as files.owner_member_id. That makes it the exception the
-- "every member-related table carries a scout_year_id" rule allows for
-- (AGENTS.md § Database): the entry's own date is already the temporal
-- marker, and a note tied to a year would vanish from the person it was
-- written about every September.
--
-- Dated ENTRIES, not one field. A registration request lives a few
-- weeks; a member stays ten years and passes through several staffs. A
-- single field overwrites: the 2026 Baladins chief would silently
-- replace what the Louveteaux chief wrote in 2023, and nobody would know
-- anything had gone. Each entry carries its author and its date, which
-- is what gives the history its meaning.
--
-- **This is probably the most sensitive free text on the site** —
-- "allergie signalée par la maman", "parents séparés", "à ne pas laisser
-- seul avec X". BLOB + encrypted via Core\Security\EncryptionService,
-- encrypted and decrypted ONLY in Repository\MemberNoteRepository. It
-- never reaches the journal (the member id and the note id are enough),
-- never an error message, never a trace, never an export, and never the
-- member or their parents.
--
-- No blind index: these are never searched, only listed for one member.
CREATE TABLE IF NOT EXISTS member_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    body BLOB NOT NULL,
    -- The account that wrote it. ON DELETE SET NULL rather than CASCADE:
    -- losing the author must never lose the note, which is a fact about
    -- the member and not about the person who typed it. The page then
    -- renders an unnamed author rather than dropping the entry.
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Set explicitly by the Repository on edit — no "ON UPDATE
    -- CURRENT_TIMESTAMP", which the migration system's ColumnDefinition
    -- does not model (same note as news_articles.updated_at).
    updated_at DATETIME NULL,
    INDEX idx_member_notes_member (member_id, created_at),
    CONSTRAINT fk_member_notes_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_notes_author FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customised automatic e-mails (Core\Mail\Template, ARCHITECTURE.md
-- §8.7bis).
--
-- **No row means no customisation**, and that is the whole design: the
-- e-mail is then rendered from the Twig template shipped with the
-- application, so it keeps benefiting from every update. A row exists
-- only once an administrator has changed something, and from then on it
-- wins, definitively and silently — « Revenir au gabarit par défaut »
-- deletes it and puts the e-mail back on the update path. Nothing is
-- versioned, nothing is diffed, and there is no third state.
--
-- `template_id` is the registry's own id — `magic_link`,
-- `rental.acknowledgement` — and is UNIQUE because one e-mail has one
-- customisation. It carries no foreign key on purpose: a module's
-- e-mails are declared in its manifest, not in a table, and disabling
-- the module must leave its customisation alone rather than destroy it.
--
-- Not personal data and not encrypted: these are template texts an
-- administrator typed, the same kind of content as an editable page
-- block. `body_html` is sanitised (Core\Security\HtmlSanitizer) BEFORE
-- it is written, like every other rich text (SECURITY.md §7), and is
-- never evaluated as Twig on the way out — it is substituted as a
-- string, which is the only thing standing between an administration
-- page and code execution.
CREATE TABLE IF NOT EXISTS email_template_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id VARCHAR(120) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    -- Set explicitly by the Repository on edit — no "ON UPDATE
    -- CURRENT_TIMESTAMP", which the migration system's ColumnDefinition
    -- does not model (same note as member_notes.updated_at).
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- ON DELETE SET NULL rather than CASCADE: losing the account that
    -- last edited an e-mail must never delete the wording the unit is
    -- sending.
    updated_by INT UNSIGNED NULL,
    UNIQUE KEY uniq_email_template_overrides_template (template_id),
    CONSTRAINT fk_email_template_overrides_author FOREIGN KEY (updated_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
