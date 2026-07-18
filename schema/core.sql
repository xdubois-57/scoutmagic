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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_desk_id (desk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    first_name_encrypted BLOB,
    last_name_encrypted BLOB,
    password_hash VARCHAR(255),
    is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    UNIQUE INDEX idx_path (relative_path),
    CONSTRAINT fk_file_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
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
    UNIQUE INDEX idx_desk_code (desk_code)
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_member_year (member_id, scout_year_id),
    INDEX idx_scout_year (scout_year_id),
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
    CONSTRAINT fk_ma_member_year FOREIGN KEY (member_year_id) REFERENCES member_years(id) ON DELETE CASCADE
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

CREATE TABLE import_journal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    user_account_id INT UNSIGNED,
    line_count INT UNSIGNED NOT NULL,
    member_count INT UNSIGNED NOT NULL,
    new_functions_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ij_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ij_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
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
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email_blind_index, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(50),
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
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
    level ENUM('info', 'security') NOT NULL DEFAULT 'info',
    description VARCHAR(500) NOT NULL,
    context JSON,
    INDEX idx_logged_at (logged_at),
    INDEX idx_category (category),
    INDEX idx_level (level),
    INDEX idx_user (user_account_id),
    INDEX idx_ip (ip_address),
    CONSTRAINT fk_el_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
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

-- Transversal roles assignable to chiefs/chief-d'unité (e.g. Infirmier,
-- Trésorier), configured once in Configuration générale and displayed on the
-- trombinoscope. See Core\Badge.
CREATE TABLE badges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    -- Default badges (Infirmier, Trésorier) are seeded automatically and can
    -- never be deleted — only deactivated.
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    -- A deactivated badge is invisible everywhere and no longer assignable,
    -- but existing member_badges assignments are preserved.
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_name (name)
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME,
    INDEX idx_status_run (status, run_at),
    INDEX idx_module_task (module_id, task_key),
    INDEX idx_module_ref (module_id, task_key, reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
