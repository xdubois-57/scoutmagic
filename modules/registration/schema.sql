-- registration module: public "inscriptions" workflow — a prospective
-- family submits a request (status 'pending'), staff review it (accepted/
-- refused/withdrawn), and an accepted one is automatically migrated into a
-- real member once Desk reconciliation (or a manual link by Desk tiers
-- number) finds it (status 'encoded') — see module docs and ARCHITECTURE.md.
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
    -- see Core\Import\DeskCsvParser.
    gender_encrypted BLOB NOT NULL,
    birth_date_encrypted BLOB NOT NULL,
    street_encrypted BLOB NOT NULL,
    number_encrypted BLOB NOT NULL,
    postal_code_encrypted BLOB NOT NULL,
    city_encrypted BLOB NOT NULL,
    email_encrypted BLOB NOT NULL,
    -- Exact-match lookup: tracking-page linkage (by email) and the
    -- staff-facing possible-duplicate signal. Never used to block a
    -- submission (Service\RegistrationService never refuses on a
    -- blind-index match — see the class docblock).
    email_blind_index CHAR(64) NOT NULL,
    phone1_encrypted BLOB NOT NULL,
    phone2_encrypted BLOB,
    remarks_encrypted BLOB,
    -- Blind index of the normalized (last name, first name, birth date)
    -- triple, computed via Core\Service\TextNormalizerService::
    -- normalizeName() (module spec) — Service\ReconciliationService's
    -- exact-match key against freshly Desk-imported members. Comparison
    -- only, never displayed.
    name_dob_blind_index CHAR(64) NOT NULL,
    -- NULL = "pas de préférence". Deliberately no ON DELETE behavior
    -- beyond the FK default (RESTRICT) — a section actually referenced by
    -- a pending request should not silently disappear from under it.
    desired_section_id INT UNSIGNED NULL,
    -- Iteration 5's full progression: pending -> accepted -> encoded
    -- (automatic at Desk reconciliation, or manual linking — same code
    -- path, see Service\ReconciliationService), plus two exits (refused,
    -- withdrawn), both manual. encoded/refused/withdrawn are the three
    -- FINAL states that start the retention clock (final_at below).
    status ENUM('pending', 'accepted', 'refused', 'withdrawn', 'encoded') NOT NULL DEFAULT 'pending',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Tracking-page token (see Service\TrackingService): 32 random bytes,
    -- hashed at rest (password_hash, same technique as Core\Member\
    -- MemberEmailService's confirmation tokens) and compared by
    -- password_verify() against the value presented in the link — never
    -- stored or compared in clear. Non-expiring while the request lives.
    tracking_token_hash VARCHAR(255) NOT NULL,
    -- "Section prévue" (module spec, itération 5) — decided by staff,
    -- distinct from desired_section_id ("section souhaitée", the parent's
    -- own pick, read-only once submitted). Never communicated to parents,
    -- never on the tracking page, whichever access path is used. The same
    -- field the "Passage" page (a later iteration) also writes to — one
    -- piece of data, two surfaces, not duplicated here.
    intended_section_id INT UNSIGNED NULL,
    -- Staff-chosen/overridden fee category — Core\Member\
    -- FeeEstimationService only ever SUGGESTS one (household size on the
    -- fiche, never applied automatically); this column is where the
    -- staff's actual decision is recorded, independent of the suggestion.
    fee_category_id INT UNSIGNED NULL,
    -- Free-form staff notes — chiefs only, chiffré au repos, jamais
    -- exposé aux parents, jamais journalisé (module spec's own privacy
    -- rule for this field, stricter than the rest of the fiche).
    internal_notes_encrypted BLOB NULL,
    -- Set only once status reaches 'encoded' — the real member this
    -- request became, via Service\ReconciliationService::migrate()
    -- (automatic match or manual link by Desk tiers number, one single
    -- code path either way). A member can be the target of at most one
    -- request (idx_rr_linked_member below) — Desk identity data itself
    -- never migrates, Desk stays authoritative (module spec).
    linked_member_id INT UNSIGNED NULL,
    -- Emails are sent explicitly, never automatically at a status change
    -- (module spec) — and the tracking page shows a status change only
    -- once the corresponding email has actually gone out (module spec's
    -- own design rule, not a display nuance): a chief refusing on Friday
    -- and sending the email on Monday must not let the parent discover
    -- the refusal in between. NULL = not sent yet (or not applicable).
    accepted_email_sent_at DATETIME NULL,
    refused_email_sent_at DATETIME NULL,
    -- The moment status entered a FINAL state (encoded/refused/withdrawn)
    -- — both retention settings (Espace animés disappearance, permanent
    -- deletion) count from here, never from received_at, and this is the
    -- one column Task\PurgeRegistrationRequestsHandler filters on. Reset
    -- to NULL if a chief reverts a final state back to pending (module
    -- spec's manual "revenir en attente" transition).
    final_at DATETIME NULL,
    -- Blind index of the comparison-normalized submitted address (Core\
    -- Member\AddressNormalizer, same technique as member_addresses) —
    -- feeds the module's Api\HouseholdRegistrationCountProvider
    -- implementation, itself injected nullable into Core\Member\
    -- FeeEstimationService so the household-size suggestion also counts
    -- accepted/encoded registration requests, not just existing members.
    address_normalized_blind_index CHAR(64) NULL,
    CONSTRAINT fk_rr_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_rr_section FOREIGN KEY (desired_section_id) REFERENCES sections(id),
    CONSTRAINT fk_rr_intended_section FOREIGN KEY (intended_section_id) REFERENCES sections(id),
    CONSTRAINT fk_rr_fee_category FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
    CONSTRAINT fk_rr_linked_member FOREIGN KEY (linked_member_id) REFERENCES members(id),
    INDEX idx_rr_email_blind (email_blind_index),
    INDEX idx_rr_name_dob_blind (name_dob_blind_index),
    INDEX idx_rr_year (scout_year_id),
    INDEX idx_rr_status (status),
    INDEX idx_rr_final_at (final_at),
    INDEX idx_rr_address_blind (address_normalized_blind_index),
    -- A member can only ever be the migration target of one request — the
    -- same constraint that makes "refuser un numéro déjà lié à une autre
    -- demande" (manual linking) enforceable at the database level, not
    -- just in application code. Multiple NULLs are allowed (MySQL unique
    -- index semantics), so every not-yet-encoded request is unaffected.
    UNIQUE INDEX idx_rr_linked_member (linked_member_id)
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

-- Per-branch age bracket (entry age + duration) no longer lives here as of
-- this module version — it's resolved directly from Core\Member\
-- MemberYearService::BRANCHES (Repository\AgeBracketRepository), the same
-- federation age ranges member_stats already uses, rather than a second,
-- independently admin-configurable copy of the same numbers. An earlier
-- version of this schema declared a registration_age_brackets table here;
-- any install that already created it keeps that table, orphaned and
-- unused (this codebase's migration system never auto-drops a table it
-- finds but doesn't declare — Core\Database\SchemaComparator's own
-- deliberate safety rule, and drops.sql only ever supports explicit
-- column/foreign-key drops, not table drops).

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

-- Iteration 6 — "Passage" page: the destination section a chief picks for
-- an existing member changing branch for the TARGET scout year (public
-- year + 1, never the effective year — see Service\PassageService's own
-- docblock for why). This is planning data, not a fact about the member:
-- it belongs to the module, keyed on the permanent member_id (survives a
-- scout year change) plus the target scout year, never written to
-- member_years — Desk stays the sole source of truth once that year is
-- actually activated and re-imported. A row simply stops being relevant
-- (never purged) once its target year is no longer in the future; it is
-- never read outside the Passage page itself.
CREATE TABLE registration_section_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    target_scout_year_id INT UNSIGNED NOT NULL,
    destination_section_id INT UNSIGNED NOT NULL,
    -- PHP-computed on every write (never SQL's NOW() / ON UPDATE
    -- CURRENT_TIMESTAMP — same portability rule as the rest of this
    -- module, so the SQLite test database behaves identically).
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_rst_member_year (member_id, target_scout_year_id),
    CONSTRAINT fk_rst_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_rst_year FOREIGN KEY (target_scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_rst_section FOREIGN KEY (destination_section_id) REFERENCES sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
