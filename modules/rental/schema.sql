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
