-- social module
--
-- social_connections: the unit's own Meta accounts, one row per platform
-- ('facebook', 'instagram'). Each unit creates its own Meta app, so the
-- app id travels with the row.
--
-- secrets is encrypted at rest via EncryptionService (context
-- 'social_connections.secrets'): a JSON document holding the app secret,
-- the access token in use and, between the Facebook consent and the
-- choice of a Page, the long-lived user token that lists the Pages. It is
-- a column rather than an entry of secrets.enc because writing that file
-- rewrites every secret of the site, and a token renewed every week has
-- no business doing so (docs/chantiers/partage-social.md).
CREATE TABLE IF NOT EXISTS social_connections (
    platform VARCHAR(20) NOT NULL PRIMARY KEY,
    app_id VARCHAR(64) NOT NULL DEFAULT '',
    account_id VARCHAR(64) NULL,
    account_name VARCHAR(255) NULL,
    secrets BLOB NULL,
    connected_at DATETIME NULL,
    token_refreshed_at DATETIME NULL,
    token_expires_at DATETIME NULL,
    checked_at DATETIME NULL,
    check_ok TINYINT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
