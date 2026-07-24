-- news_articles: the article itself. Rich-text body is NOT stored here —
-- it lives in the core editable_contents table under key
-- "news_body_{id}" (same pattern as the banner module's
-- banner_content_{id} — see ARCHITECTURE.md §8.13), reusing
-- Core\View\EditableContentService/HtmlSanitizer instead of a second
-- rich-text storage mechanism. has_form is denormalized (kept in sync by
-- Service\ArticleService whenever a news_forms row is created/deleted)
-- purely so the news list can badge "Formulaire" without a join per row.
-- short_url_code is generated once, lazily, on an article's first save
-- (Core\Url\ShortUrlService) and cached here so the poster/QR/edit-link
-- flows never need a reverse lookup into short_urls.
CREATE TABLE IF NOT EXISTS news_articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    visibility ENUM('public', 'chief', 'admin', 'direct_link') NOT NULL DEFAULT 'public',
    has_form BOOLEAN NOT NULL DEFAULT FALSE,
    -- direct_link visibility forces is_indexed = false (Service\
    -- ArticleService, enforced server-side, not just hidden in the UI).
    is_indexed BOOLEAN NOT NULL DEFAULT FALSE,
    seo_keywords TEXT NULL,
    seo_stop_date DATE NULL,
    short_url_code VARCHAR(10) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- No "ON UPDATE CURRENT_TIMESTAMP" — the migration system's
    -- ColumnDefinition doesn't model that clause (see modules/calendar/
    -- schema.sql's calendar_events for the same note); updated_at is set
    -- explicitly by Repository\ArticleRepository::update() instead.
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_news_visibility (visibility),
    CONSTRAINT fk_news_article_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- news_forms: one-to-one with an article — lives and dies with it
-- (ON DELETE CASCADE). finance_account_id is a plain, unconstrained
-- reference (no FK) to modules/finance's finance_accounts table: the
-- finance module is an OPTIONAL dependency (ARCHITECTURE.md §7.5) and
-- may be disabled/not installed, so a hard FK to a table that might not
-- exist would break schema migration. Resolved only through
-- Modules\Finance\Api\FinanceAccountInterface when available.
CREATE TABLE IF NOT EXISTS news_forms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_article_id INT UNSIGNED NOT NULL UNIQUE,
    access ENUM('public', 'identified') NOT NULL DEFAULT 'identified',
    -- Forced to 'unlimited' whenever access = 'public' (Service\
    -- FormService), since an anonymous submission can't be tied to an
    -- account/member to enforce a per-person limit against.
    response_limit ENUM('unlimited', 'one_per_account', 'one_per_member') NOT NULL DEFAULT 'unlimited',
    opens_at DATE NULL,
    closes_at DATE NULL,
    is_force_closed BOOLEAN NOT NULL DEFAULT FALSE,
    response_role_min ENUM('intendant', 'chief', 'admin') NOT NULL DEFAULT 'chief',
    daily_digest_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    -- Bookkeeping for Task\SendResponseDigestHandler: only responses
    -- submitted after this timestamp are "new" for the next digest.
    last_digest_sent_at DATETIME NULL,
    finance_account_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_news_form_article FOREIGN KEY (news_article_id) REFERENCES news_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- news_form_fields: one row per question, ordered by sort_order
-- (drag-and-drop reordering, partials/list_editor.html.twig — see
-- ARCHITECTURE.md §8.13). options_manual is newline-separated plain
-- text (not personal data — these are the form BUILDER's own choices,
-- e.g. "Lundi\nMardi\nMercredi", never a respondent's answer).
CREATE TABLE IF NOT EXISTS news_form_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    field_type ENUM('short_text', 'long_text', 'number', 'date', 'phone', 'email', 'dropdown', 'radio', 'checkbox', 'switch', 'confirmation') NOT NULL,
    label VARCHAR(255) NULL,
    is_required BOOLEAN NOT NULL DEFAULT FALSE,
    options_source ENUM('manual', 'members') NULL,
    options_manual TEXT NULL,
    capacity_max INT UNSIGNED NULL,
    price_per_unit DECIMAL(10, 2) NULL,
    confirmation_text TEXT NULL,
    CONSTRAINT fk_news_field_form FOREIGN KEY (form_id) REFERENCES news_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- news_form_responses: one row per submission. contact_email is always
-- collected (mandatory, structural — not a configurable field, see
-- module spec) and always encrypted (SECURITY.md §5); the blind index
-- allows the "one_per_account"/"one_per_member" limit check plus a
-- chief's own lookup, without ever decrypting on a WHERE clause.
-- structured_communication/receivable_id are NOT personal data (a
-- payment reference and an integer) so stay unencrypted; receivable_id
-- has no FK for the same optional-dependency reason as news_forms.
-- finance_account_id above.
CREATE TABLE IF NOT EXISTS news_form_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    user_account_id INT UNSIGNED NULL,
    member_year_id INT UNSIGNED NULL,
    contact_email BLOB NOT NULL,
    contact_email_blind_index VARCHAR(64) NOT NULL,
    structured_communication VARCHAR(24) NULL,
    receivable_id INT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- No "ON UPDATE CURRENT_TIMESTAMP" — see news_articles.updated_at's
    -- doc comment; set explicitly by Repository\FormResponseRepository.
    updated_at DATETIME NULL,
    INDEX idx_news_response_form (form_id),
    INDEX idx_news_response_blind (form_id, contact_email_blind_index),
    CONSTRAINT fk_news_response_form FOREIGN KEY (form_id) REFERENCES news_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_response_account FOREIGN KEY (user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_news_response_member_year FOREIGN KEY (member_year_id) REFERENCES member_years(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- news_form_response_values: every answer, uniformly encrypted (module
-- spec: "no per-field judgment") — including numbers/dates/booleans,
-- stored as their string representation before encryption. One row per
-- (response, field); a checkbox field's multiple selections are joined
-- into a single encrypted value (comma-separated) rather than one row
-- per option, since they always belong to the same response+field pair.
CREATE TABLE IF NOT EXISTS news_form_response_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    value BLOB NULL,
    CONSTRAINT fk_news_value_response FOREIGN KEY (response_id) REFERENCES news_form_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_news_value_field FOREIGN KEY (field_id) REFERENCES news_form_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
