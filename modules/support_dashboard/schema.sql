-- Modules\SupportDashboard — the statistics receiver's own tables
-- (ARCHITECTURE.md §8.49).
--
-- This module only ever exists on the installation that RECEIVES usage
-- reports: `"receiver_only": true` in module.json keeps it out of
-- ModuleManager::discoverModules() everywhere else.

-- One row per reporting installation. Deliberately ONE state per
-- installation, never a per-day history: each accepted report overwrites
-- the previous one.
CREATE TABLE support_installations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Supplied by the sender (Core\Statistics\InstallationIdentityService).
    -- Opaque and random, derived from nothing about the unit.
    installation_id VARCHAR(64) NOT NULL UNIQUE,
    -- The reporting site's own URL. Neither this nor installation_id is
    -- personal data (a unit is an association, not a natural person), and
    -- both are needed in clear to filter, sort and search — see SECURITY.md.
    instance_url VARCHAR(255),
    -- password_hash() of the shared secret. The secret itself is NEVER
    -- stored, in any form, anywhere.
    secret_hash VARCHAR(255) NOT NULL,
    -- The exact JSON received, kept verbatim including fields this receiver
    -- does not understand: a newer sender's extra data must survive until
    -- the receiver is taught to read it.
    payload JSON,
    statistics_schema_version INT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Denormalised copies of the payload fields the dashboard filters,
    -- sorts and searches on, so no query ever has to parse JSON.
    -- EVERY ONE IS NULLABLE, and NULL always means "not reported" — never
    -- 0, never false. A sender that could not measure something and a
    -- sender that measured zero are different facts.
    scoutmagic_version VARCHAR(50) NULL,
    is_dev_build BOOLEAN NULL,
    active_members INT UNSIGNED NULL,
    active_sections INT UNSIGNED NULL,
    installation_method VARCHAR(30) NULL,
    auto_update_enabled BOOLEAN NULL,
    auto_update_level VARCHAR(20) NULL,
    scout_year_label VARCHAR(50) NULL,
    installed_at DATETIME NULL,
    last_upgraded_at DATETIME NULL,
    INDEX idx_support_installations_last_received (last_received_at),
    INDEX idx_support_installations_version (scoutmagic_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-IP rate limiting for the intake endpoint, modelled on
-- Core\Security\HumanCheck\HumanCheckRateLimitRepository rather than
-- invented: short-lived rows, counted over a sliding window, purged once
-- past it. The raw address is never stored — only its HMAC blind index,
-- exactly as human_check_rate_limits does, because an IP is personal data.
CREATE TABLE support_report_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_report_rate_limits_lookup (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monthly history (ARCHITECTURE.md §8.51). One row per calendar month, and
-- deliberately nothing else: how many DISTINCT installations reported at
-- least once during it.
--
-- A finalised aggregate is IMMUTABLE and kept indefinitely. There is no
-- user-facing recompute, no regeneration, and nothing anywhere rewrites a
-- finalised row — not retention, not a manual deletion, not an installation
-- that disappears. A history that gets rewritten when a contributor later
-- vanishes is a history that means nothing.
CREATE TABLE support_monthly_aggregates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- 'YYYY-MM'. A string rather than a DATE because the value IS the month,
    -- not a day inside it, and a stored day would invite arithmetic nobody
    -- wants to do here.
    month CHAR(7) NOT NULL UNIQUE,
    installation_count INT UNSIGNED NOT NULL,
    finalized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Working table, and ONLY a working table: it exists so distinct
-- contributors can be counted before a month is finalised. Its rows for a
-- month are DELETED at finalisation, which is what makes the hard rule
-- above true in practice — a finalised aggregate holds no individual
-- identifier at all, neither an installation id nor a URL.
CREATE TABLE support_monthly_contributions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month CHAR(7) NOT NULL,
    installation_id VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Several reports in one month are ONE contribution: the uniqueness is
    -- enforced here rather than by a read-then-write in PHP, which two
    -- concurrent reports could interleave past.
    UNIQUE KEY uniq_support_monthly_contribution (month, installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
