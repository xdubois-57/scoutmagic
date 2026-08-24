-- rental module: renting out the unit's own assets — premises, grounds,
-- tents, trailers, equipment.
--
-- ## No scout_year_id, on purpose
--
-- AGENTS.md § Database asks every member-related table to carry a
-- scout_year_id, "unless the data itself genuinely isn't scout-year-scoped
-- (e.g. calendar_events)". Nothing here is scout-year-scoped, and this is
-- the same exception, for the same reason: a rental is dated on a calendar,
-- not attached to a school year. A booking that runs from 28 August to 2
-- September straddles two scout years; forcing it into one would make the
-- other year's availability calculation wrong, and the year-transition
-- machinery would silently orphan live bookings every September. An asset
-- likewise outlives any single year. Retention is therefore driven by the
-- accounting exercise instead (see the roadmap's §6.35 and the retention
-- work in a later iteration), never by the scout year.
--
-- ## Personal data
--
-- The emergency phone number on an asset is personal data (it reaches a
-- named human), so it is a BLOB encrypted via Core\Security\
-- EncryptionService, encrypted/decrypted only in
-- Repository\RentalAssetRepository — never in a Service, Controller, or
-- journal entry (SECURITY.md §5). Everything else in this file is an id, a
-- flag, a timestamp, a money amount, or a label chosen by a chief.
--
-- Manager identity is NOT stored here as a name: rental_asset_managers
-- points at members.id and the person is resolved at render time through
-- Core\Member\MemberService, exactly as modules/groups does.

-- ---------------------------------------------------------------------
-- Assets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rental_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Free, configurable asset type (local, terrain, tente, remorque,
    -- matériel, autre). Deliberately a plain string rather than an ENUM:
    -- the roadmap calls the list "libre et configurable", and an ENUM would
    -- turn adding a type into a schema migration plus a version bump.
    -- Suggested values live in configuration, never hardcoded in PHP.
    asset_type VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    -- Stable public identifier, used in /locations/{slug}. Stable means it
    -- survives a rename: renaming an asset must not break a link a renter
    -- already has. Unique across assets, archived ones included, so an
    -- archived asset's slug is never silently reassigned to a new asset
    -- and made to serve someone else's content.
    slug VARCHAR(160) NOT NULL,
    -- How many people the asset can host. NULL = not applicable (a trailer
    -- has no capacity), 0 is never used to mean that.
    capacity INT UNSIGNED NULL,
    -- 1 = exclusive (one booking at a time), >1 = stock (e.g. eight tents,
    -- bookable independently). Availability treats the two identically
    -- apart from the remaining-quantity calculation.
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    -- Local time of day, as a string rather than a TIME column so "not
    -- set" is expressible without a sentinel; used by the calendar and the
    -- ICS feeds when known.
    arrival_time VARCHAR(5) NULL,
    departure_time VARCHAR(5) NULL,
    -- Personal data (SECURITY.md §5) — shown only to the renter on their
    -- own tracking page, never publicly.
    emergency_phone_encrypted BLOB NULL,
    -- Archived, never deleted, as soon as any booking references the asset:
    -- deleting it would destroy the history the accounting exercise and the
    -- retention aggregate both depend on.
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    -- Visible to the public at all. A public asset is reached from the
    -- /locations index page, which is the module's single entry in the
    -- "Notre unité" menu — there is deliberately no per-asset menu entry,
    -- so this flag alone decides public reachability.
    is_public TINYINT(1) NOT NULL DEFAULT 0,

    -- ── Booking constraints (module spec, iteration 3) ───────────────
    -- What a visitor may ASK for, as opposed to whether the asset is free.
    -- The two are kept strictly apart: a day inside the notice window is
    -- free and merely too late to request, and §6.7 requires it to be shown
    -- like the past rather than like "occupé" — a visitor told a free day is
    -- taken concludes the asset is booked and gives up on it.
    -- 0 means "no limit" for each of the four numeric rules.
    min_nights INT UNSIGNED NOT NULL DEFAULT 0,
    max_nights INT UNSIGNED NOT NULL DEFAULT 0,
    min_notice_days INT UNSIGNED NOT NULL DEFAULT 0,
    max_horizon_days INT UNSIGNED NOT NULL DEFAULT 0,
    -- Comma-separated ISO weekdays (1 = Monday … 7 = Sunday) a stay may
    -- start on. Empty means any day. A short, fixed-domain list of at most
    -- seven single digits — a normalised table would cost a join per
    -- calendar render for nothing.
    allowed_arrival_weekdays VARCHAR(20) NOT NULL DEFAULT '',
    -- Hard ceiling on a request, distinct from `capacity` above: capacity
    -- describes the asset, this caps what may be asked for.
    max_persons INT UNSIGNED NULL,
    -- Nights of breathing space after each stay — cleaning, a caretaker's
    -- round. Extends an occupancy AFTER its departure only; extending both
    -- ends would leave twice the configured gap between two rentals.
    buffer_nights INT UNSIGNED NOT NULL DEFAULT 0,
    -- ── Pricing (module spec §6.10) ──────────────────────────────────
    -- What the unit price is the price OF. Also decides, on its own, whether
    -- availability is counted in nights or in full days (§6.8) — the two are
    -- never configured separately, or the calendar ends up contradicting the
    -- invoice. See Pricing\BillingUnit.
    -- ── Payments (§6.19, §6.20) ──────────────────────────────────────
    -- Entirely optional, and OFF by default. The Finance module is a
    -- nullable dependency: with it disabled these columns are simply never
    -- read, and the whole module keeps working — a unit that settles its
    -- rentals by hand is a perfectly normal unit.
    payments_enabled TINYINT(1) NOT NULL DEFAULT 0,
    -- Finance's own account id, reached only through
    -- Modules\Finance\Api\FinanceAccountInterface — never by joining
    -- Finance's tables, which is why there is no foreign key here.
    finance_account_id INT UNSIGNED NULL,

    -- 'none' | 'fixed' | 'percentage'. The deposit is a THRESHOLD on the
    -- single rental receivable, never a second receivable (§6.19): the
    -- deposit and the balance share one communication, and "deposit
    -- received" means amount_received >= deposit_amount.
    deposit_mode VARCHAR(20) NOT NULL DEFAULT 'none',
    deposit_amount_cents INT UNSIGNED NULL,
    deposit_percentage TINYINT UNSIGNED NULL,
    -- Days after confirmation, not a fixed date: an asset's rule has to
    -- outlive any single booking.
    deposit_due_days SMALLINT UNSIGNED NULL,
    balance_due_days SMALLINT UNSIGNED NULL,

    -- 'none' | 'fixed'. The security deposit IS its own receivable with its
    -- own communication (§6.20) — it is not part of the rental price and
    -- must never be counted as rental revenue.
    security_deposit_mode VARCHAR(20) NOT NULL DEFAULT 'none',
    security_deposit_amount_cents INT UNSIGNED NULL,
    security_deposit_due_days SMALLINT UNSIGNED NULL,

    -- ── Calendar publication (§6.30) ─────────────────────────────────
    -- Occupancy is published as VIRTUAL events (§6.31): nothing is ever
    -- written to `calendar_events`, so the booking stays the single source
    -- of truth and the two can never disagree. With the `calendar` module
    -- disabled these columns are simply never read.
    calendar_publication_enabled TINYINT(1) NOT NULL DEFAULT 0,
    -- WHICH calendars is rental_asset_calendars, not a column here: a hall
    -- belongs on the unit's calendar AND on the section's that mostly uses
    -- it, and one column forced a choice nobody wanted to make.
    -- 'confirmation' | 'hold'. Publishing from the hold shows the unit its
    -- own pencilled-in dates; publishing from confirmation shows only what
    -- is actually let. Neither is right for everyone, which is why it is a
    -- choice rather than a rule.
    calendar_publish_from VARCHAR(20) NOT NULL DEFAULT 'confirmation',

    -- ── Invoicing (§6.27) ────────────────────────────────────────────
    -- **No VAT is ever computed.** Prices are what the renter pays, full
    -- stop. A unit letting a hall is not a VAT-registered business in the
    -- general case, and a module that computed VAT would be quietly wrong
    -- for almost every installation. What an invoice does carry is a
    -- configurable exemption sentence, because a Belgian invoice with no
    -- VAT on it needs to say why.
    vat_exemption_note VARCHAR(255) NULL,

    billing_unit VARCHAR(30) NOT NULL DEFAULT 'flat_stay',
    -- Rate used when the period × category grid has no cell for the resolved
    -- pair. NULL means "not priced yet", which produces a visible warning
    -- rather than a silent zero.
    default_unit_price_cents INT UNSIGNED NULL,
    -- A floor on what renting the asset is worth. Mutually exclusive with
    -- minimum_persons — the spec says "un montant plancher OU un nombre de
    -- personnes plancher", and the service refuses to store both.
    minimum_amount_cents INT UNSIGNED NULL,
    minimum_persons INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_rental_assets_slug (slug),
    -- The public index page's own query: public, not archived, by name.
    KEY idx_rental_assets_public (is_public, is_archived, name),
    -- The menu hook's query, run on every request that builds a menu —
    -- narrow enough to be answered from the index alone.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Managers
-- ---------------------------------------------------------------------
-- Who may operate an asset day to day. Managers are Desk members, not
-- necessarily chiefs, and several may share one asset with identical
-- rights. "Staff d'U" is implicitly a manager of every asset and is
-- therefore NOT stored here — Service\RentalAuthorizationService resolves
-- that separately, so revoking a row can never accidentally lock the unit
-- staff out of their own assets.
--
-- ScoutMagic badges are never an ACL. This table is the only source of
-- per-asset authority besides Staff d'U membership.
-- ---------------------------------------------------------------------
-- Which calendars an asset publishes onto (§6.30)
-- ---------------------------------------------------------------------
-- A row per (asset, calendar) pair rather than a `calendar_id` column on
-- the asset: a hall is very often both the unit's business and one
-- section's, and asking a manager to pick a single calendar meant the
-- other group simply never saw it.
--
-- `calendar_id` points into the `calendar` module and is reached only
-- through its public API, so there is deliberately NO foreign key onto it
-- — a constraint would make `rental` unusable without `calendar`, which is
-- exactly the coupling §7.5 forbids. A calendar deleted on that side
-- leaves a row nothing resolves, and the provider skips it.
CREATE TABLE IF NOT EXISTS rental_asset_calendars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    calendar_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_rental_asset_calendar (asset_id, calendar_id),
    KEY idx_rental_asset_calendars_calendar (calendar_id),
    CONSTRAINT fk_rental_asset_calendars_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rental_asset_managers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    -- members.id — the PERSISTENT identity, never member_years.id, for the
    -- same reason files.owner_member_id uses it: a manager must not lose
    -- their assets every September when a new member_years row is created.
    member_id INT UNSIGNED NOT NULL,
    -- Whether this manager's contact details are shown to the renter on
    -- their tracking page. This NEVER means "publicly visible" — no
    -- manager's name, phone or email is ever shown to the public
    -- (roadmap §6.6). It only widens the audience from "internal" to
    -- "internal + this booking's renter".
    is_renter_contact TINYINT(1) NOT NULL DEFAULT 0,
    -- Set to 0 by Service\RentalDeskImportListener when a Desk import no
    -- longer lists the member, and back to 1 if they reappear. Never
    -- deleted: the association is the record of who was responsible, and
    -- a member absent from one import (a data-entry slip, a late
    -- registration) must be able to come back without a chief having to
    -- re-grant anything. Same motive as
    -- Core\Import\MappingResolver::deactivateAllSections().
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- One row per (asset, member): granting twice is the same grant, and
    -- the unique key is what makes reactivation an UPDATE rather than a
    -- duplicate.
    UNIQUE KEY uniq_rental_asset_managers (asset_id, member_id),
    -- "Which assets does this member manage" — the authorization check on
    -- every managed-space request, and the "Mes locations" entry.
    KEY idx_rental_asset_managers_member (member_id, is_active),
    CONSTRAINT fk_rental_asset_managers_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pricing (module spec §6.10)
-- ---------------------------------------------------------------------
-- The engine is deliberately narrow: quantity × unit price, the quantity
-- raised to a floor if a minimum applies, plus fees. There is no rule
-- precedence, no resolution, and no rule that cancels another — which is
-- exactly why this schema has no "priority", "rank" or "condition" column
-- anywhere. Adding one would be the first step back towards a rules engine.
--
-- Money is stored in CENTS, as integers, everywhere. Never a DECIMAL and
-- never a float: a price that has to survive a snapshot, a contract and an
-- invoice unchanged cannot be subject to binary rounding.
--
-- Periods and categories are per ASSET rather than unit-wide. The spec
-- describes four configuration blocks "par bien", and a unit renting out a
-- hall and a set of tents genuinely prices them on unrelated seasons and
-- unrelated audiences. The cost is a little duplication between two assets
-- that happen to agree; the alternative couples every asset's pricing to a
-- shared list nobody can change safely.

-- First axis of the price grid.
CREATE TABLE IF NOT EXISTS rental_price_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    -- For a recurring period only the month and day matter, but a full date
    -- is stored anyway so the column type stays honest and a period can be
    -- switched between recurring and one-off without losing its dates.
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    -- "1 July → 31 August, every year" is how a high season is really
    -- expressed; making an operator re-enter it annually is how it ends up
    -- wrong. A recurring range may wrap the new year (20 Dec → 5 Jan).
    recurs_yearly TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rental_price_periods_asset (asset_id, sort_order),
    CONSTRAINT fk_rental_price_periods_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Second axis of the price grid.
CREATE TABLE IF NOT EXISTS rental_renter_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    -- Pre-selected on the public request form. A convenience, never a
    -- permission: the price is always recomputed server-side from the
    -- category actually recorded on the booking.
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rental_renter_categories_asset (asset_id, sort_order),
    CONSTRAINT fk_rental_renter_categories_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One cell of the two-axis grid. A sparse table rather than a dense matrix:
-- a missing cell falls back to the asset's default rate, so adding a
-- category does not silently price every period at zero until someone fills
-- the whole new column.
CREATE TABLE IF NOT EXISTS rental_price_grid (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    -- NULL means "whatever the period, this row applies" — the axis is
    -- simply not in use for this asset.
    period_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    unit_price_cents INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rental_price_grid_asset (asset_id),
    CONSTRAINT fk_rental_price_grid_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_price_grid_period
        FOREIGN KEY (period_id) REFERENCES rental_price_periods (id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_price_grid_category
        FOREIGN KEY (category_id) REFERENCES rental_renter_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordered list of fees. Three natures and three only (fixed / per person /
-- meter reading). The order is presentational: fees are summed, and none
-- ever modifies, caps or cancels another.
CREATE TABLE IF NOT EXISTS rental_fees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    label VARCHAR(150) NOT NULL,
    -- 'fixed' | 'per_person' | 'meter'
    nature VARCHAR(20) NOT NULL,
    -- For 'meter', the price of ONE unit read (one kWh, one m³) — never an
    -- estimated total, which is why a meter fee never enters a quote.
    amount_cents INT UNSIGNED NOT NULL,
    meter_unit VARCHAR(20) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rental_fees_asset (asset_id, sort_order),
    CONSTRAINT fk_rental_fees_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bookings (module spec §6.13, §6.14, §6.15)
-- ---------------------------------------------------------------------
-- One aggregate covering the whole life of a rental, from a public request
-- to a closed stay. Later iterations add its lines, documents, meter
-- readings and communications, all hanging off this row.
--
-- ## Personal data
--
-- A booking is about a NON-MEMBER: someone with no account, no Desk record
-- and no other trace in this installation. Every identity column is
-- therefore a BLOB encrypted via Core\Security\EncryptionService, written
-- and read only in Repository\RentalBookingRepository — never in a Service,
-- a Controller, or a journal entry (SECURITY.md §5). The retention policy
-- that eventually deletes them lands in a later iteration; the encryption
-- is here from the first row.
--
-- ## No scout_year_id
--
-- Same exception as rental_assets, for the same reason (see the top of this
-- file): a rental is dated on a calendar, not attached to a school year.

CREATE TABLE IF NOT EXISTS rental_bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    -- Stable, human-quotable reference in the form LOC-YYYY-NNNN, allocated
    -- once at submission and never reused. It is what a renter quotes on the
    -- phone, what the contract carries, and what an inbound email's subject
    -- is matched on (§7.6) — so it must survive everything, including the
    -- booking being refused.
    reference VARCHAR(20) NOT NULL,

    arrival_date DATE NOT NULL,
    departure_date DATE NOT NULL,
    -- How much of a countable stock this booking takes. 1 for an exclusive
    -- asset.
    units INT UNSIGNED NOT NULL DEFAULT 1,
    estimated_persons INT UNSIGNED NULL,
    renter_category_id INT UNSIGNED NULL,

    -- ── Renter identity: personal data, all encrypted ────────────────
    renter_name_encrypted BLOB NOT NULL,
    renter_email_encrypted BLOB NOT NULL,
    -- Exact-match lookup only: linking a booking to an identified visitor
    -- with the same address, and matching an inbound email's sender (§7.6).
    -- Never used to block or refuse a request.
    renter_email_blind_index CHAR(64) NOT NULL,
    renter_phone_encrypted BLOB NULL,
    renter_organisation_encrypted BLOB NULL,
    -- What the asset is wanted for, and anything else the renter wrote.
    purpose_encrypted BLOB NULL,
    renter_comment_encrypted BLOB NULL,

    -- ── Lifecycle (§6.15) ────────────────────────────────────────────
    -- 'received' | 'reviewing' | 'info_requested' | 'proposed' | 'confirmed'
    -- | 'refused' | 'cancelled' | 'expired' | 'closed'.
    -- "In progress" is deliberately NOT a stored status: it is derived from
    -- the dates, so it can never disagree with the calendar.
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- When the booking reached a final state, and therefore when its
    -- retention clock starts. Never `received_at`.
    final_at DATETIME NULL,

    -- ── Temporary hold (§6.14) ───────────────────────────────────────
    -- ONE mechanism, not two: a single deadline and a single origin
    -- ('automatic' — created by the request itself, short — or 'manager' —
    -- a deliberate option with a promised deadline). One expiry task, one
    -- availability calculation, one set of tests. Both make the period
    -- unavailable identically to the public.
    hold_until DATETIME NULL,
    hold_origin VARCHAR(20) NULL,

    -- ── Price snapshot (§6.11) ───────────────────────────────────────
    -- The ESTIMATED price at submission, as a self-contained
    -- Pricing\PriceQuote snapshot. Never recomputed: it is the record of
    -- what the visitor was actually shown, and changing the asset's rates
    -- afterwards must not rewrite it. The AGREED price is a separate
    -- snapshot added when the manager and renter agree (later iteration) —
    -- the spec is explicit that the two must never be confused.
    estimated_price_snapshot MEDIUMTEXT NULL,
    estimated_total_cents INT UNSIGNED NULL,

    -- The AGREED price: the manager's working copy of the quote, and what
    -- the renter is actually asked to pay. Starts as a copy of the estimate
    -- the moment a manager first touches it, and from then on it is the
    -- only one that moves — the estimate above stays frozen as the record
    -- of what the visitor was shown at submission, which is the whole
    -- reason the two are separate columns rather than one.
    --
    -- Lines a manager edited by hand carry `isManual` inside the snapshot
    -- and are never recalculated afterwards (§6.12): re-quoting rebuilds
    -- the automatic lines from the live tariff and carries the manual ones
    -- across untouched.
    agreed_price_snapshot MEDIUMTEXT NULL,
    agreed_total_cents INT UNSIGNED NULL,

    -- ── Versioned acceptances (§6.13) ────────────────────────────────
    -- Two distinct tick-boxes, each recorded with the version and a hash of
    -- the exact text shown, so what was accepted can be proven later even
    -- after the text is reworded. The privacy one is NOT a consent: the
    -- processing is necessary to the booking, and calling it consent would
    -- misrepresent the legal basis.
    conditions_version VARCHAR(40) NULL,
    conditions_hash CHAR(64) NULL,
    conditions_accepted_at DATETIME NULL,
    privacy_version VARCHAR(40) NULL,
    privacy_hash CHAR(64) NULL,
    privacy_acknowledged_at DATETIME NULL,

    -- ── Payments (§6.19, §6.20) ──────────────────────────────────────
    -- ONE receivable for the whole rental. The deposit is a threshold on
    -- it, not a second one: two receivables would mean two communications,
    -- and a renter paying the balance with the deposit's reference would
    -- settle the wrong one.
    --
    -- The id points into Finance and is reached only through its public
    -- API (Modules\Finance\Api\ExpectedReceivableInterface), so there is
    -- deliberately no foreign key: Finance may be disabled, may be enabled
    -- later, and a rental must survive either.
    rental_receivable_id INT UNSIGNED NULL,
    rental_communication VARCHAR(24) NULL,
    -- Snapshotted when the receivable is created, so the threshold cannot
    -- move under a renter who was told a figure. A percentage that changes
    -- on the asset afterwards does not rewrite what was asked.
    deposit_amount_cents INT UNSIGNED NULL,
    deposit_due_date DATE NULL,
    balance_due_date DATE NULL,

    -- The security deposit: its OWN receivable, its OWN communication, and
    -- never rental revenue (§6.20).
    security_deposit_receivable_id INT UNSIGNED NULL,
    security_deposit_communication VARCHAR(24) NULL,
    security_deposit_amount_cents INT UNSIGNED NULL,
    security_deposit_due_date DATE NULL,
    -- 'none' | 'to_receive' | 'received' | 'to_return' | 'returned'
    -- | 'partially_withheld' | 'fully_withheld'.
    security_deposit_status VARCHAR(30) NOT NULL DEFAULT 'none',
    -- The return is tracked BY HAND here, deliberately: Finance does not
    -- reconcile outgoing payments, and inventing that would be out of
    -- scope. Documented rather than hidden (§6.20).
    security_deposit_returned_cents INT UNSIGNED NULL,
    security_deposit_withheld_cents INT UNSIGNED NULL,
    security_deposit_returned_at DATE NULL,
    -- Why something was withheld. Written by a manager about a renter's
    -- group and their damage, so it is personal data in practice and is
    -- encrypted like every other such field.
    security_deposit_note_encrypted BLOB NULL,

    -- ── The stay (§6.21, §6.23) ──────────────────────────────────────
    -- The version counter for this booking's settlements. Forward-only,
    -- never MAX(version) over the surviving rows: a deleted v2 must not
    -- make the next settlement v2 again, since v2 may already have been
    -- sent. Same reasoning as document versions and booking references.
    settlement_last_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Whether the inventory checklist has been copied in from the asset
    -- (§6.23). A flag rather than "are there rows?", because an asset with
    -- an empty checklist snapshots legitimately into zero rows, and
    -- re-snapshotting later would overwrite a completed inventory.
    inventory_snapshotted TINYINT(1) NOT NULL DEFAULT 0,

    -- ── Billing identity (§6.27) ─────────────────────────────────────
    -- Collected when it becomes relevant, not at the request form: an
    -- anonymous visitor asking about a weekend has no reason to type a VAT
    -- number, and asking for one up front loses requests. All of it is
    -- personal or commercially identifying data about the renter, so all of
    -- it is encrypted at rest like the rest of their identity.
    --
    -- Modelled with Peppol in mind without implementing it: the fields an
    -- e-invoice needs (legal name, address, country, enterprise and VAT
    -- numbers, a buyer reference) are all here, so adding it later is a new
    -- exporter rather than a migration.
    billing_name_encrypted BLOB NULL,
    billing_address_encrypted BLOB NULL,
    billing_country VARCHAR(2) NULL,
    billing_vat_number_encrypted BLOB NULL,
    billing_enterprise_number_encrypted BLOB NULL,
    billing_email_encrypted BLOB NULL,
    billing_reference_encrypted BLOB NULL,

    -- ── Renter tracking token (§13 of the conventions) ───────────────
    -- A sensitive capability token: cryptographically random, never logged,
    -- revocable by regenerating it — and ENCRYPTED rather than hashed.
    --
    -- It was a password_hash() until the module started writing to the
    -- renter itself. A hash can only ever answer "is this the token?", and
    -- every email a manager's decision sends has to ASK a different
    -- question: "what is this booking's link?". A renter with no account
    -- has exactly one way back to their booking, and a confirmation that
    -- cannot carry it is a confirmation that tells them to go and find the
    -- acknowledgement from three weeks ago.
    --
    -- The cost is real and is the reason for this note: a hash survives a
    -- database copy, this does not — it survives a database copy taken
    -- WITHOUT the application key, which is the threat SECURITY.md §5 is
    -- written against and where every other identity column in this table
    -- already stands. Verification is a decrypt and a hash_equals(),
    -- constant-time, so nothing is guessable one character at a time.
    tracking_token_encrypted BLOB NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_rental_bookings_reference (reference),
    -- The availability query: everything holding one asset over a window.
    KEY idx_rental_bookings_availability (asset_id, status, arrival_date, departure_date),
    -- The expiry sweep, and the "which holds are about to lapse" reminder.
    KEY idx_rental_bookings_hold (hold_until),
    -- Linking a booking to an identified visitor, and inbound-mail matching.
    KEY idx_rental_bookings_email (renter_email_blind_index),
    CONSTRAINT fk_rental_bookings_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference counter, one row per year.
--
-- The next LOC-YYYY-NNNN cannot be derived from the surviving bookings: a
-- booking deleted by mistake — or, from the retention policy onward, purged
-- on schedule — would free its number, and two different rentals would end
-- up quoting the same reference to two different renters. The counter only
-- ever moves forward, so a number is spent the moment it is handed out and
-- never comes back.
CREATE TABLE IF NOT EXISTS rental_reference_sequences (
    -- The year a request was MADE in, not the year of the stay: a reference
    -- has to stay stable, and a 2027 request for a 2028 camp is a 2027
    -- request.
    year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_sequence INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- Manual blocks (§6.18)
-- ─────────────────────────────────────────────────────────────────────
--
-- A period a manager takes off the market: works, a unit camp, a caretaker
-- away. Deliberately its own table rather than a booking with a special
-- status — a block has no renter, no price, no lifecycle and no email, and
-- forcing it into `rental_bookings` would put a nullable renter on every
-- row and an "is this real?" check on every query that reads one.
--
-- **A block over an already-booked period must neither fail nor overwrite
-- the booking.** The two coexist: availability adds them up, and the
-- private calendar shows both. That is why nothing here references a
-- booking and why there is no exclusion constraint.
--
-- To the public it is indistinguishable from a booking, because
-- Availability\Occupancy has no discriminator to tell them apart by.
CREATE TABLE IF NOT EXISTS rental_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,

    -- Same half-open/closed convention as a booking: which one applies is
    -- read off the asset's BillingUnit, never configured separately, or a
    -- block and a booking would disagree about the same two dates.
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    -- How much of a stock asset the block takes. A hall is one unit; five
    -- tents out of twelve leave seven bookable.
    units INT UNSIGNED NOT NULL DEFAULT 1,

    -- Internal only, and never rendered publicly. Free text a manager
    -- writes for other managers ("chantier toiture"), so it is not
    -- personal data by design — but it is not encrypted either, which is
    -- exactly why the interface must keep it about the asset and never
    -- about a person.
    reason VARCHAR(255) NULL,

    created_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_blocks_window (asset_id, start_date, end_date),
    CONSTRAINT fk_rental_blocks_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- Internal comments on a booking (§6.4, §6.6)
-- ─────────────────────────────────────────────────────────────────────
--
-- What managers write to each other about a booking. **Never visible to
-- the renter**, on the tracking page or anywhere else — the tracking page
-- does not read this table at all, which is a stronger guarantee than a
-- template remembering to hide it.
--
-- Encrypted at rest even though it is staff-written: a comment about a
-- booking is, in practice, a comment about the people making it ("le
-- groupe de Mme Martin a laissé la cuisine sale"), so it carries the same
-- protection as the renter's own fields rather than a weaker one.
CREATE TABLE IF NOT EXISTS rental_booking_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    author_member_id INT UNSIGNED NULL,
    body_encrypted BLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_booking_comments_booking (booking_id, created_at),
    CONSTRAINT fk_rental_booking_comments_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- Change requests and proposals (§6.16, §6.17)
-- ─────────────────────────────────────────────────────────────────────
--
-- **One table for both directions**, because they are the same object seen
-- from two ends: somebody proposes different dates, a different number of
-- people, or an end to the booking, and somebody else decides. Two tables
-- would mean two lifecycles, two expiry rules and two sets of tests for
-- one concept.
--
-- The rule the spec is emphatic about: a renter's request **never modifies
-- the booking silently**. It lands here as `pending` and changes nothing
-- until a manager decides. A manager's proposal is symmetric — it changes
-- nothing until the renter accepts it from their tracking page.
CREATE TABLE IF NOT EXISTS rental_change_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,

    -- 'renter' | 'manager' — who is asking, which is also who may NOT
    -- decide it.
    origin VARCHAR(20) NOT NULL,
    -- 'dates' | 'persons' | 'cancellation'.
    kind VARCHAR(20) NOT NULL,
    -- 'pending' | 'accepted' | 'refused' | 'withdrawn'.
    status VARCHAR(20) NOT NULL DEFAULT 'pending',

    proposed_arrival_date DATE NULL,
    proposed_departure_date DATE NULL,
    proposed_units INT UNSIGNED NULL,
    proposed_persons INT UNSIGNED NULL,

    -- A manager's proposal may carry a price, so the renter accepts dates
    -- and amount together rather than agreeing to dates and discovering
    -- the total afterwards. Self-contained, like every other PriceQuote
    -- snapshot.
    proposed_price_snapshot MEDIUMTEXT NULL,
    proposed_total_cents INT UNSIGNED NULL,

    -- Free text from either side. A renter writes it, so it is personal
    -- data and encrypted like every other renter field.
    message_encrypted BLOB NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    decided_by_member_id INT UNSIGNED NULL,

    KEY idx_rental_change_requests_booking (booking_id, status, created_at),
    CONSTRAINT fk_rental_change_requests_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- Documents attached to a booking (§6.24, §6.25, §6.27)
-- ─────────────────────────────────────────────────────────────────────
--
-- The file itself lives in `files` and is served only through
-- `Core\File\FileAccessGuard` / `file_url()` — never from under `public/`.
-- This table is the rental-specific metadata around it: what kind of
-- document it is, which version, and the data it was generated from.
--
-- **`is_for_renter` is an EMAIL flag, not an access right** (§6.24, §6.26).
-- An external renter downloads nothing from this site: they have no
-- account, and the tracking token is not a file-access credential. The flag
-- says "this one gets attached to an email", and nothing anywhere turns it
-- into a download permission. That is why there is no new exception to
-- SECURITY.md §6 here.
--
-- **A generated document is never overwritten.** Regenerating a contract
-- produces v2 alongside v1, because v1 may already have been sent, printed
-- and signed — and a signature refers to a text, not to a file name.
CREATE TABLE IF NOT EXISTS rental_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,

    -- 'contract' | 'signed_contract' | 'invoice' | 'inventory' | 'photo'
    -- | 'meter_reading' | 'certificate' | 'evidence' | 'unsorted' | 'other'.
    document_type VARCHAR(30) NOT NULL,
    -- 1 for the first generation of a type, 2 for the next… An uploaded
    -- document is always version 1: versioning is about regeneration.
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_for_renter TINYINT(1) NOT NULL DEFAULT 0,

    -- Who owns the bytes behind `file_id`.
    --
    -- 'manual': this module put them there (an upload, a generated PDF),
    -- and deleting the document deletes the file with it. 'email': the row
    -- points at an inbound message's OWN attachment, by the very same
    -- `files` id the message serves it from (§8.59) — deleting the bytes
    -- here would blank the message too, so only this row goes. Same
    -- invariant, and the same wording, as camp_documents.source.
    source ENUM('manual', 'email') NOT NULL DEFAULT 'manual',

    -- The values the document was rendered from, frozen (§6.25). Without
    -- it, "why does v1 say 467,50 € when the booking says 400,00 €?" has no
    -- answer six months later. Never personal data beyond what the document
    -- itself already contains, and never read back into the application —
    -- it is evidence, not state.
    generated_snapshot MEDIUMTEXT NULL,

    -- When it was last emailed to the renter, so a manager can see whether
    -- a resend is a resend.
    sent_at DATETIME NULL,

    created_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_documents_booking (booking_id, document_type, version),
    CONSTRAINT fk_rental_documents_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- The booking's own copy of a document template (§6.25, level 2)
-- ─────────────────────────────────────────────────────────────────────
--
-- Three levels, each frozen the moment the next is born: the ASSET's
-- template (in `editable_contents`), the BOOKING's copy (here), and the
-- PDF (a `rental_documents` row).
--
-- The copy is taken at the first generation and is editable on its own
-- afterwards. Editing the asset's template later touches **no existing
-- booking** — which is the whole reason this level exists: a template
-- reworded in March must not silently change what a renter agreed to in
-- February.
--
-- Holds the template text WITH its keywords still in place, not the
-- substituted result: the values are re-resolved at every generation, so a
-- corrected head count reaches v2 without anybody re-editing prose.
CREATE TABLE IF NOT EXISTS rental_booking_document_texts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(30) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,

    -- The highest version ever handed out for this booking and type — a
    -- forward-only counter, never `MAX(version)` over the surviving
    -- documents. Deleting v2 must not make the next generation v2 again:
    -- v2 may already have been emailed, and two different PDFs under one
    -- version number is exactly the confusion versioning exists to
    -- prevent. Same reasoning, and the same shape, as
    -- `rental_reference_sequences`.
    last_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_rental_booking_document_text (booking_id, document_type),
    CONSTRAINT fk_rental_booking_document_texts_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- Meters (§6.22)
-- ─────────────────────────────────────────────────────────────────────
--
-- What can be read on an asset — electricity, gas, water, anything else.
-- Each one points at the `meter`-nature fee that prices it, which is the
-- fee iteration 2 already refused to put in a quote because its amount is
-- not merely unknown before the stay, it is unknowable.
CREATE TABLE IF NOT EXISTS rental_meters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    -- 'electricity' | 'gas' | 'water' | 'other'. Presentation only: the
    -- arithmetic is identical for all of them, and inventing a kind-specific
    -- calculation is how a module ends up unable to meter something nobody
    -- thought of.
    meter_kind VARCHAR(20) NOT NULL DEFAULT 'other',
    -- "kWh", "m³". Shown beside every reading so a number is never naked.
    unit VARCHAR(20) NOT NULL DEFAULT '',
    -- The `meter` fee that prices a unit read. Null means "read it, but do
    -- not bill it" — a legitimate choice for a meter kept for evidence.
    fee_id INT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_meters_asset (asset_id, sort_order),
    CONSTRAINT fk_rental_meters_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- Meter readings (§6.22)
-- ─────────────────────────────────────────────────────────────────────
--
-- **Stored in thousandths of a unit, as an integer.** A meter index is not
-- money, but it has money's problem: 1234.567 kWh in a float, differenced
-- against another float and multiplied by a unit price, is how a bill ends
-- up a cent off in a way nobody can reproduce. Integers make the
-- subtraction exact, and the one rounding that does happen is the final
-- multiplication into cents, where it belongs.
CREATE TABLE IF NOT EXISTS rental_meter_readings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    meter_id INT UNSIGNED NOT NULL,
    -- 'arrival' | 'departure'. One of each per meter and booking: a second
    -- arrival reading is a correction, and corrections replace rather than
    -- accumulate, or consumption becomes a guess about which pair to use.
    phase VARCHAR(20) NOT NULL,
    value_milli BIGINT NOT NULL,
    read_at DATETIME NOT NULL,
    -- Optional photo of the dial. Goes through UploadHandler and is served
    -- only through FileAccessGuard, like every other rental file.
    file_id INT UNSIGNED NULL,
    -- Free text about the READING — "compteur difficile à lire", "cadran
    -- remplacé". Same rule as `rental_blocks.reason`: it is about a device,
    -- never about a person, which is why it is not encrypted and why the
    -- interface must keep it that way.
    comment VARCHAR(255) NULL,
    recorded_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_rental_meter_reading (booking_id, meter_id, phase),
    CONSTRAINT fk_rental_meter_readings_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- Inventory checklist: the asset's template (§6.23)
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rental_inventory_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    label VARCHAR(160) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_inventory_items_asset (asset_id, sort_order),
    CONSTRAINT fk_rental_inventory_items_asset
        FOREIGN KEY (asset_id) REFERENCES rental_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- Inventory checklist: the booking's snapshot (§6.23)
-- ─────────────────────────────────────────────────────────────────────
--
-- **Copied into the booking at confirmation**, label and all. Editing the
-- asset's checklist afterwards must not change an inventory somebody
-- already signed off: an item renamed from "chaises" to "chaises (x40)" in
-- June would otherwise silently rewrite what was checked in March, and an
-- item deleted would erase a finding.
--
-- One row per item and booking, carrying BOTH phases: an arrival and a
-- departure state are two observations of the same thing, and splitting
-- them across rows makes "what changed during the stay?" a join.
CREATE TABLE IF NOT EXISTS rental_booking_inventory (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    -- The label AS IT WAS at confirmation, not a reference to the template.
    label VARCHAR(160) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- 'not_checked' | 'ok' | 'issue' | 'missing'. `not_checked` is the
    -- honest default and is distinct from `ok`: "nobody looked" and
    -- "somebody looked and it was fine" are different facts, and conflating
    -- them is how a missing set of keys becomes nobody's fault.
    arrival_state VARCHAR(20) NOT NULL DEFAULT 'not_checked',
    departure_state VARCHAR(20) NOT NULL DEFAULT 'not_checked',
    arrival_note VARCHAR(255) NULL,
    departure_note VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_rental_booking_inventory (booking_id, sort_order),
    CONSTRAINT fk_rental_booking_inventory_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- Incidents and damage (§6.23)
-- ─────────────────────────────────────────────────────────────────────
--
-- **The financial decision stays human.** Nothing in this module ever
-- turns a damage into a charge on its own: a manager proposes an amount,
-- and a manager decides whether it is billed, withheld from the security
-- deposit, or waived. An automatic scale would be wrong about the one case
-- that matters — the group that broke something and immediately said so.
CREATE TABLE IF NOT EXISTS rental_incidents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,

    -- Written by a manager about what a renter's group did, so it is
    -- personal data in practice and carries the same protection as the
    -- renter's own fields (SECURITY.md §5).
    description_encrypted BLOB NOT NULL,
    proposed_amount_cents INT UNSIGNED NULL,

    -- 'pending' | 'charge' | 'withhold' | 'waive'. `pending` is a real
    -- state, not a placeholder: an assessment in progress must never leak
    -- to the renter's page (§6.26), and it is the flag that keeps it off.
    decision VARCHAR(20) NOT NULL DEFAULT 'pending',
    decided_amount_cents INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decided_by_member_id INT UNSIGNED NULL,

    -- Optional photo, through UploadHandler and FileAccessGuard.
    file_id INT UNSIGNED NULL,
    created_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_incidents_booking (booking_id, created_at),
    CONSTRAINT fk_rental_incidents_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- The final settlement (§6.21)
-- ─────────────────────────────────────────────────────────────────────
--
-- **Its own lines and its own snapshot**, deliberately separate from the
-- agreed price. The spec is explicit: a settlement must never silently
-- modify the agreed price. The two answer different questions — "what did
-- we agree?" and "what does it come to now that the stay has happened?" —
-- and a module that let the second overwrite the first would lose the
-- evidence for every dispute it exists to settle.
--
-- **Versioned, and a validated version is immutable.** Changing a
-- settlement after validation produces a new version beside it, exactly
-- like a contract: v1 may already have been sent, and "modification après
-- validation est historisée" is the requirement.
CREATE TABLE IF NOT EXISTS rental_settlements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    -- The head count that ACTUALLY turned up, which is what per-person
    -- charges are recomputed on. Distinct from the booking's estimate,
    -- which stays as the record of what was announced.
    final_persons INT UNSIGNED NULL,

    -- The settlement's own lines, self-contained like every other snapshot
    -- in this module: base, meters read, extra fees, damage. Never a
    -- reference to live configuration.
    lines_snapshot MEDIUMTEXT NULL,
    total_cents INT NOT NULL DEFAULT 0,
    already_paid_cents INT NOT NULL DEFAULT 0,
    -- May be NEGATIVE: an overpayment is a real outcome and hiding it
    -- behind a floor of zero is how a refund nobody knows about happens.
    balance_cents INT NOT NULL DEFAULT 0,

    security_deposit_withheld_cents INT UNSIGNED NULL,
    security_deposit_return_cents INT UNSIGNED NULL,

    is_validated TINYINT(1) NOT NULL DEFAULT 0,
    validated_at DATETIME NULL,
    validated_by_member_id INT UNSIGNED NULL,
    created_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_settlements_booking (booking_id, version),
    CONSTRAINT fk_rental_settlements_booking
        FOREIGN KEY (booking_id) REFERENCES rental_bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rental_compliance_items: the asset's paperwork register (§6.33).
--
-- **A neutral register, not a compliance check.** The module knows no
-- regulation, computes no compliance state and passes no judgement — it
-- remembers what expires and says so in time. Every entry is a free-text
-- label the unit chose, because the rules differ by commune, by federation
-- and by year, and hardcoding any of them would be wrong somewhere and
-- stale everywhere else.
--
-- The suggested labels shown at creation live in a module setting, never in
-- this schema and never in the code: a label that changes must not require a
-- software update.
CREATE TABLE IF NOT EXISTS rental_compliance_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    -- Free text, chosen by the unit. Not an enum, deliberately.
    label VARCHAR(200) NOT NULL,
    -- The document itself, when there is one. Optional: a register entry
    -- that only records "the commune's authorisation runs out in March" is
    -- worth having before anybody scans anything.
    file_id INT UNSIGNED NULL,
    -- Optional too: some paperwork simply does not expire.
    expires_on DATE NULL,
    -- About the asset and its paperwork, never about a person — so plain
    -- text, like rental_blocks.reason, and the same responsibility on the
    -- interface to keep it that way.
    remark TEXT NULL,
    -- The last date a reminder went out for this entry, so a daily task
    -- does not send the same warning every morning for a month.
    reminded_on DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rental_compliance_asset (asset_id),
    KEY idx_rental_compliance_expiry (expires_on),
    CONSTRAINT fk_rental_compliance_asset FOREIGN KEY (asset_id) REFERENCES rental_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rental_reminders_sent: what has already been said, and when (§6.29).
--
-- A reminder task runs daily and asks the same questions every morning
-- ("is the deposit still unpaid?"). Without this table the answer would be
-- yes every morning for a month, and the unit would learn to ignore the
-- whole channel — which is worse than not reminding at all.
--
-- Keyed by the thing reminded about plus the reminder's own key, so the
-- same booking can carry an unpaid-deposit reminder and a missing-contract
-- one without either suppressing the other.
CREATE TABLE IF NOT EXISTS rental_reminders_sent (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- 'booking' | 'compliance'. Not a foreign key: a reminder about a
    -- booking that was later deleted is history, and losing it would let
    -- the same reminder fire again if the id were reused.
    subject_type VARCHAR(20) NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    reminder_key VARCHAR(50) NOT NULL,
    sent_on DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_rental_reminder_once (subject_type, subject_id, reminder_key),
    KEY idx_rental_reminder_sent_on (sent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rental_booking_aggregates: what survives a purge (§6.35).
--
-- **One anonymous row per booking, and nothing that can be tied back to a
-- person.** No booking id, no reference, no token, no file, no name — the
-- asset, the month, the number of days and the amount, which is exactly
-- what the overview's three figures need and nothing more.
--
-- It exists because the alternative is worse in both directions: keeping
-- the booking forever to keep the statistics, or letting the year's revenue
-- drop to zero on the morning the purge runs. The aggregate is written when
-- the booking is purged, not before, so a live booking is never counted
-- twice (§6.34 reads both sources).
--
-- Deliberately carries no foreign key to rental_bookings: the row it
-- describes is gone by the time this one matters.
CREATE TABLE IF NOT EXISTS rental_booking_aggregates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    -- 'YYYY-MM' of the arrival. A month rather than a date: a date plus an
    -- asset plus an amount is close enough to a fingerprint of one letting
    -- to be worth blunting, and no statistic here needs the day.
    stay_month CHAR(7) NOT NULL,
    -- Days occupied, so §6.34's "days let this year" survives the purge.
    occupied_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- The agreed price in cents, security deposit excluded — a deposit is
    -- not revenue, it is somebody else's money held briefly.
    amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    -- Which accounting year this fell in, so a purge and a statistic agree
    -- on what "this year" means.
    scout_year_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rental_aggregate_asset_month (asset_id, stay_month),
    KEY idx_rental_aggregate_year (scout_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
