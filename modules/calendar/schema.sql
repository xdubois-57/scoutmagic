-- calendar module
--
-- calendar_calendars: a calendar is either a "section calendar"
-- (section_id set — one per active section, automatic, never deletable) or
-- a "supplementary calendar" (section_id NULL — the default "Animateurs"
-- calendar plus any admin-created custom ones). ics_token is only ever set
-- on supplementary calendars: section calendars are not individually
-- subscribable, only via the "unité complète" feed or a personal feed.
CREATE TABLE IF NOT EXISTS calendar_calendars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NULL,
    name VARCHAR(100) NULL,
    color VARCHAR(7) NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    visibility ENUM('public', 'chief', 'admin') NOT NULL DEFAULT 'public',
    ics_token VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_calendar_section (section_id),
    UNIQUE INDEX idx_calendar_ics_token (ics_token),
    CONSTRAINT fk_calendar_section FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    location VARCHAR(255) NULL,
    description TEXT NULL,
    -- Incremented on every edit, feeds the ICS SEQUENCE property so clients
    -- know a re-fetched event supersedes the one they already cached.
    sequence INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    -- No "ON UPDATE CURRENT_TIMESTAMP" — the migration system's
    -- ColumnDefinition doesn't model that clause, so it would silently be
    -- dropped from generated DDL. updated_at is set explicitly by
    -- CalendarEventRepository::update() instead.
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_calendar FOREIGN KEY (calendar_id) REFERENCES calendar_calendars(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per user_account. Regeneration replaces the token in place — the
-- old link stops matching immediately (real revocation, no history kept).
CREATE TABLE IF NOT EXISTS calendar_personal_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_personal_user (user_account_id),
    UNIQUE INDEX idx_personal_token (token),
    CONSTRAINT fk_personal_token_user FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single global row for the "unité complète" aggregate feed token.
CREATE TABLE IF NOT EXISTS calendar_unit_feed_token (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_unit_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
