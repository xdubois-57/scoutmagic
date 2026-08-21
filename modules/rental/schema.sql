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
    -- Visible to the public at all. An asset can be public without being
    -- pinned to the menu (show_in_menu below) — it is then reachable from
    -- the /locations index page, which is exactly why that page exists
    -- unconditionally once any public asset exists.
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    -- "Afficher ce bien dans le menu Notre unité". Only ever honoured for a
    -- public, non-archived asset — a private asset with the box ticked must
    -- not appear, so the filter lives in the query, not in the template.
    show_in_menu TINYINT(1) NOT NULL DEFAULT 0,
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
    KEY idx_rental_assets_menu (show_in_menu, is_public, is_archived)
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

    -- ── Renter tracking token (§13 of the conventions) ───────────────
    -- A sensitive capability token: cryptographically random, stored ONLY
    -- as a hash (password_hash, same technique as the registration module's
    -- tracking token), never logged, revocable by regenerating it.
    tracking_token_hash VARCHAR(255) NOT NULL,

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
-- History of what happened to a booking (§6.15)
-- ─────────────────────────────────────────────────────────────────────
--
-- The visible, per-booking counterpart to the audit journal: who moved
-- this booking, when, and from what to what. The journal answers "what
-- happened on this site"; this answers "what happened to this rental", and
-- a manager needs the second without being handed the first.
--
-- **No personal data, ever.** A summary here is about the booking — a
-- status, a date range, a total — never about the renter. That rule is the
-- reason this column is plain text while the comment table's is a BLOB.
CREATE TABLE IF NOT EXISTS rental_booking_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,

    -- 'status_changed' | 'hold_placed' | 'hold_cleared' | 'price_changed'
    -- | 'dates_changed' | 'change_requested' | 'change_decided' |
    -- 'comment_added'.
    event_type VARCHAR(40) NOT NULL,
    from_value VARCHAR(120) NULL,
    to_value VARCHAR(120) NULL,
    summary VARCHAR(255) NULL,

    -- Null for anything the system did on its own (a hold lapsing), which
    -- is a fact worth keeping distinct from "a manager did it".
    actor_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rental_booking_events_booking (booking_id, created_at),
    CONSTRAINT fk_rental_booking_events_booking
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
