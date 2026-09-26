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

-- social_cards: the images composed for publication (CardService), each
-- reachable for a short while at /partage/carte/{token} so that Meta's
-- servers can fetch it — Instagram takes a media by its public URL, never
-- as an upload. Only the token's SHA-256 is stored: the table alone opens
-- nothing. The JPEG lives under storage/social/cards/ and goes with its
-- row once expired (SECURITY.md § the ephemeral card route).
CREATE TABLE IF NOT EXISTS social_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    file_name VARCHAR(80) NOT NULL,
    blurred TINYINT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    served_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_social_cards_token (token_hash),
    KEY idx_social_cards_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- social_publications: what left, where, and how it went — one row per
-- content and destination, never more (docs/chantiers/
-- CHANTIER-partage-social.md, « Une publication, une seule fois »). The
-- unique key is the rule itself, enforced by the database: a second
-- publication of the same album to the same Page is refused by the INSERT,
-- not only hidden by the dialog. A row in `failed` can be taken again
-- — that is a retry — and a row left in `pending` by an interrupted
-- request becomes retryable after a quarter of an hour.
--
-- source_kind: 'album' | 'article' | 'communication'.
-- destination: 'facebook' | 'instagram'.
CREATE TABLE IF NOT EXISTS social_publications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_kind VARCHAR(20) NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    destination VARCHAR(40) NOT NULL,
    status VARCHAR(12) NOT NULL,
    remote_id VARCHAR(100) NULL,
    error_message VARCHAR(500) NULL,
    attempted_at DATETIME NOT NULL,
    published_at DATETIME NULL,
    user_account_id INT UNSIGNED NULL,
    -- What was sent, kept for the history and so a retry resends the same
    -- (IT-04): the content's title at the time, the caption, and where the
    -- post can be seen once published.
    source_title VARCHAR(200) NULL,
    caption TEXT NULL,
    remote_url VARCHAR(500) NULL,
    UNIQUE KEY uq_social_publications (source_kind, source_id, destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- social_communications: a free communication (IT-04, « Nouvelle
-- communication ») — a title written on the card, the text of the post, and
-- ONE image: a gallery photo (by media id, read through the gallery's Api at
-- publication, so its visibility is asked again then) or an uploaded file
-- (core `files`, role_min chief). Its publications live in
-- social_publications with source_kind 'communication'.
CREATE TABLE IF NOT EXISTS social_communications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    gallery_media_id INT UNSIGNED NULL,
    file_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
