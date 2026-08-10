-- registration module: public "inscriptions" workflow — a prospective
-- family submits a request, which lives at status 'pending' throughout this
-- iteration (staff review/acceptance is a later iteration; see module docs).
--
-- registration_requests deliberately does NOT store a requester
-- user_account_id: linking a request to an identified visitor is always
-- recomputed from email_blind_index (primary) and
-- registration_secondary_emails (confirmed secondary addresses) against
-- Core\Security\AuthSession::getEmail()'s own blind index — the same
-- "never persist the link, always re-derive it" approach
-- Core\Member\MemberService::getLinkedMembers() already uses for members,
-- so a request never becomes unreachable just because an account's email
-- changed after submission.
CREATE TABLE registration_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- The scout year this request targets — public year + 1 normally, or
    -- the current public year when a valid in-year code was supplied
    -- (Service\SlotService / registration_year_codes below).
    scout_year_id INT UNSIGNED NOT NULL,
    -- All identity data is a BLOB encrypted via Core\Security\
    -- EncryptionService, encrypted/decrypted only in Repository\
    -- RegistrationRequestRepository — never in a Service, Controller, or
    -- journal entry (SECURITY.md §5).
    parent_name_encrypted BLOB NOT NULL,
    child_last_name_encrypted BLOB NOT NULL,
    child_first_name_encrypted BLOB NOT NULL,
    -- Three values, matching Desk's own "Genre" column domain (M/F/X) —
    -- see Core\Import\DeskCsvParser — so a future Desk reconciliation
    -- (iteration 5) compares like with like without a translation table.
    gender_encrypted BLOB NOT NULL,
    birth_date_encrypted BLOB NOT NULL,
    street_encrypted BLOB NOT NULL,
    number_encrypted BLOB NOT NULL,
    postal_code_encrypted BLOB NOT NULL,
    city_encrypted BLOB NOT NULL,
    email_encrypted BLOB NOT NULL,
    -- Exact-match lookup: tracking-page linkage (by email) and the
    -- staff-facing possible-duplicate signal in a later iteration. Never
    -- used to block a submission (Service\RegistrationService never
    -- refuses on a blind-index match — see the class docblock).
    email_blind_index CHAR(64) NOT NULL,
    phone1_encrypted BLOB NOT NULL,
    phone2_encrypted BLOB,
    remarks_encrypted BLOB,
    -- Blind index of the normalized (last name, first name, birth date)
    -- triple — created here, consumed by the Desk-reconciliation match in
    -- a later iteration. Never used for anything in this iteration.
    name_dob_blind_index CHAR(64) NOT NULL,
    -- NULL = "pas de préférence". Deliberately no ON DELETE behavior
    -- beyond the FK default (RESTRICT) — a section actually referenced by
    -- a pending request should not silently disappear from under it.
    desired_section_id INT UNSIGNED NULL,
    -- Single value today ('pending' — this iteration adds nothing else),
    -- extended by a future iteration's ALTER when acceptance/refusal
    -- states are added; never pre-declared speculatively here.
    status ENUM('pending') NOT NULL DEFAULT 'pending',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Tracking-page token (see Service\TrackingService): 32 random bytes,
    -- hashed at rest (password_hash, same technique as Core\Member\
    -- MemberEmailService's confirmation tokens) and compared by
    -- password_verify() against the value presented in the link — never
    -- stored or compared in clear. Non-expiring while the request lives.
    tracking_token_hash VARCHAR(255) NOT NULL,
    CONSTRAINT fk_rr_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_rr_section FOREIGN KEY (desired_section_id) REFERENCES sections(id),
    INDEX idx_rr_email_blind (email_blind_index),
    INDEX idx_rr_name_dob_blind (name_dob_blind_index),
    INDEX idx_rr_year (scout_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Declared siblings the submitting visitor selected — always a link to a
-- REAL member (an actual Desk-imported member_years row for the effective
-- year), never a bare account. "Priority" itself (what a sibling link is
-- FOR) is a later iteration's concern; this iteration only captures which
-- links were declared at submission time.
CREATE TABLE registration_request_siblings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_request_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    CONSTRAINT fk_rrs_request FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_rrs_member FOREIGN KEY (member_id) REFERENCES members(id),
    UNIQUE INDEX idx_rrs_pair (registration_request_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Secondary emails on a registration request — module-local rather than a
-- generalization of Core\Member\MemberEmailService: nothing else in this
-- codebase uses a polymorphic (subject_type, subject_id) owner, every
-- table here is FK'd to one single concrete owner concept, and a
-- registration request is explicitly not a member (it may end up refused,
-- never becoming one). Mirrors member_emails' shape/states/token technique
-- exactly (states, bin2hex(random_bytes(32)) + password_hash() token,
-- explicit expiry) so the mechanics stay familiar, without the FK deciding
-- what a "member" is. See ARCHITECTURE.md's module hook section for the
-- documented decision.
CREATE TABLE registration_secondary_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_request_id INT UNSIGNED NOT NULL,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    status ENUM('pending', 'valid', 'inactive') NOT NULL DEFAULT 'pending',
    confirmation_token_hash VARCHAR(255),
    confirmation_expires_at DATETIME,
    last_confirmation_sent_at DATETIME,
    confirmed_at DATETIME,
    deactivated_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rse_request FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_rse_request_blind (registration_request_id, email_blind_index),
    INDEX idx_rse_blind (email_blind_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-branch age bracket: entry age (age reached at year_in_branch=1) and
-- how many years the branch spans. Deliberately module-owned settings, not
-- a reuse of Core\Member\MemberYearService::BRANCHES (that table is fixed
-- federation-wide constants used for EXISTING members' effective-age
-- computation — never touched by this module, per the "never recompute an
-- age by hand" rule); this module translates a SLOT to a birth-year range
-- for a visitor who isn't a member yet, and that translation must stay
-- fully configurable per the module spec (no unit-specific value in code).
CREATE TABLE registration_age_brackets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    age_branch_id INT UNSIGNED NOT NULL,
    entry_age TINYINT UNSIGNED NOT NULL,
    duration_years TINYINT UNSIGNED NOT NULL,
    UNIQUE INDEX idx_rab_branch (age_branch_id),
    CONSTRAINT fk_rab_branch FOREIGN KEY (age_branch_id) REFERENCES age_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Capacity for one slot (branch × year-in-branch), all sections of that
-- branch combined — the module's own unit of measure (see module docs),
-- never a per-section or per-birth-year number.
CREATE TABLE registration_slot_capacities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    age_branch_id INT UNSIGNED NOT NULL,
    year_in_branch TINYINT UNSIGNED NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE INDEX idx_rsc_slot (age_branch_id, year_in_branch),
    CONSTRAINT fk_rsc_branch FOREIGN KEY (age_branch_id) REFERENCES age_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-year registration code: lets a family arriving mid-year target the
-- CURRENT scout year instead of next year's. One row per scout year,
-- code stored in clear (module spec: "ce n'est pas une barrière de
-- sécurité", permanently displayed to the chief) — is_active false (or no
-- row at all for that year) means "no active code", which is itself the
-- only "close in-year registration" mechanism needed. Regenerating
-- overwrites `code` in place so the previous value stops matching
-- immediately, rather than appending a new row.
CREATE TABLE registration_year_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ryc_year (scout_year_id),
    CONSTRAINT fk_ryc_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
