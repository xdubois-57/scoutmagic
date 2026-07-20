-- banner module
--
-- banners: one row per configured banner (order + active flag only). The
-- formatted text itself is NOT stored here — it lives in the core
-- editable_contents table (Core\View\EditableContentService), keyed
-- "banner_content_{id}", reusing the exact same generic rich-text
-- storage/sanitization mechanism as the rest of the site (e.g. the
-- calendar module's intro text) rather than a second one.
CREATE TABLE IF NOT EXISTS banners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
