-- sos_staff module
--
-- sos_provider_credentials: one row per configured telephony provider
-- (currently only 'ovh'), at most one active at a time (enforced in
-- Service\ProviderConfigService, not the DB — a partial unique index on
-- is_active isn't portable to the SQLite test DB). config_encrypted is a
-- JSON blob (application-key/secret, consumer key, billing account, line,
-- derived SOS number — see Provider\Ovh\OvhTelephonyProvider), encrypted
-- via EncryptionService like any other sensitive field. A JSON blob (not
-- per-provider columns) is what lets a future provider be added with zero
-- schema change — every provider's config shape differs.
CREATE TABLE IF NOT EXISTS sos_provider_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    config_encrypted BLOB NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_sos_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sos_settings: a single global row (id=1, application-enforced singleton —
-- same "one row, no DB-level constraint" precedent as the calendar module's
-- calendar_unit_feed_token) holding the fallback redirect number.
-- default_number_member_id, when set, is an explicit admin override — a
-- Staff d'U member whose mobile is resolved live at read time, so it always
-- follows the latest Desk import rather than going stale. When null, the
-- effective default is auto-resolved (Service\SosSettingsService) to the
-- Staff d'U section's "responsable" (trombinoscope module, if enabled) or
-- else the first Staff d'U roster member — there is deliberately no manual,
-- free-typed fallback number: the default must always be a real member,
-- so a handover is always attributable to someone reachable.
-- sections_defaults_seeded is a one-shot bookkeeping flag — the first time
-- the excluded-sections picker is read, sections outside the Baladins/
-- Louveteaux/Éclaireurs/Pionniers branches are pre-excluded; this flag makes
-- that seeding run exactly once so it never overwrites an admin's later
-- explicit choice to re-include everything.
CREATE TABLE IF NOT EXISTS sos_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    default_number_member_id INT UNSIGNED NULL,
    sections_defaults_seeded BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sos_settings_member FOREIGN KEY (default_number_member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sos_excluded_sections: sections hidden from the left "section activity"
-- columns of the duty calendar (module spec §1.4), shown to the admin as an
-- "included sections" picker (Service\SosSettingsService inverts the
-- semantics for display). The STAFFDU section is excluded unconditionally
-- in Service\SosSettingsService — it is never stored here, so there is no
-- row to accidentally delete.
CREATE TABLE IF NOT EXISTS sos_excluded_sections (
    section_id INT UNSIGNED NOT NULL PRIMARY KEY,
    CONSTRAINT fk_sos_excluded_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sos_oncall_assignments: sparse — a day with no row for a member means
-- "available" (the default, unmarked state). member_id references the
-- persistent identity (members.id), not member_years, because a duty date
-- can span a scout-year boundary and the redirect target's phone number
-- must always resolve against whichever member_year is current at read
-- time — same reasoning as calendar_events.created_by, which likewise has
-- no scout_year_id.
CREATE TABLE IF NOT EXISTS sos_oncall_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    assignment_date DATE NOT NULL,
    state ENUM('oncall', 'unavailable') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_sos_oncall_member_date (member_id, assignment_date),
    INDEX idx_sos_oncall_date (assignment_date),
    CONSTRAINT fk_sos_oncall_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- There is deliberately no calendar bookkeeping table anymore: duty
-- periods reach the calendar as VIRTUAL events computed live from
-- sos_oncall_assignments (Calendar\SosVirtualEventProvider, ARCHITECTURE.md
-- §7.6), so nothing is ever written to calendar_events and there is no copy
-- to keep in step. The former sos_calendar_sync table is dropped in
-- drops.sql.
