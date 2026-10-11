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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
-- destination: 'facebook' | 'instagram' | 'group:{id}' — each discussion
-- group is a destination of its own, so « once » holds group by group.
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
    -- A discussion group's name when the post was made (IT-05): the
    -- history keeps naming it after a rename.
    destination_label VARCHAR(200) NULL,
    UNIQUE KEY uq_social_publications (source_kind, source_id, destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- social_communications: what the composer publishes — a title written on
-- the card, the text of the post, and ONE image.
--
-- The image is either the communication's own (a gallery photo by media id,
-- read through the gallery's Api at publication so its visibility is asked
-- again then, or an uploaded file in core `files`, role_min chief), or it
-- comes from the SOURCE below, in which case neither column is set.
--
-- source_kind / source_id: what this communication shares, when it was
-- opened from somewhere — 'album' or 'article' today
-- (Service\ShareSource's KIND_* constants), and deliberately generic so
-- another part of the site can become a source without touching this
-- table (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01). Both NULL for
-- a communication written from nothing, which has no link either.
--
-- The source is NOT where its publications are recorded: those stay in
-- social_publications under source_kind 'communication' and this row's id,
-- which is what lets the same album be shared twice with two different
-- messages while « the same communication twice to the same destination »
-- stays refused by that table's unique key.
CREATE TABLE IF NOT EXISTS social_communications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    gallery_media_id INT UNSIGNED NULL,
    file_id INT UNSIGNED NULL,
    source_kind VARCHAR(20) NULL,
    source_id INT UNSIGNED NULL,
    -- SHA-256 of `body`, and the only reason it exists is the unique key
    -- below (issue #793, #706 IT-05). `body` is TEXT, which MySQL indexes
    -- by prefix only, and a 255-character prefix of a caption that may
    -- run to 2 200 would collide on two genuinely different long texts —
    -- refusing a share that should be allowed. A hash collides only on
    -- equal texts.
    --
    -- NULL for a communication written from nothing: there is no source
    -- to deduplicate it against, and two free communications with the
    -- same text are two posts somebody wrote twice on purpose.
    body_hash CHAR(64) NULL,
    -- How blurred this card's photo leaves, chosen with the composer's
    -- slider and frozen with the rest once one destination has been tried
    -- (issue #706, IT-02). NULL is « never chosen »: the site's own
    -- starting position applies, which is what every row written before
    -- the slider existed carries. 0 is a real answer — « Net » — and the
    -- floor that used to forbid it is gone.
    blur_ratio DECIMAL(4,3) NULL,
    -- The card the BROWSER drew and posted at « Publier », kept as it
    -- arrived (issue #706, IT-02). What is published, retried, and shown
    -- on the public page is this file — never a fresh composition, so
    -- « what you saw is what left » holds for every destination and every
    -- later attempt. NULL for every share made before IT-02, and for
    -- those the server still composes one: a retry of an old failed share
    -- has to remain possible.
    card_file_id INT UNSIGNED NULL,
    -- The address of this communication's public page, which is the whole
    -- capability: whoever holds it reads the page, with no account (issue
    -- #706, IT-04, SECURITY.md § 6). 32 bytes from `random_bytes()` in
    -- hexadecimal, never an identifier a passer-by could increment —
    -- iterating over sequential ids would harvest every communication
    -- ever shared.
    --
    -- NULL until the page is needed, and it is needed by the first share
    -- that cannot carry the card itself: an album published to Facebook,
    -- which goes as a LINK to this page. Minted at that instant and never
    -- afterwards, so a communication that only ever went to Instagram or
    -- to a discussion group has no public page at all.
    --
    -- Once minted it stays: Facebook caches a preview and comes back to
    -- read the page later, and a page that answered yesterday and 404s
    -- today breaks a post nobody can edit any more.
    public_token CHAR(64) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_social_communications_source (source_kind, source_id),
    UNIQUE KEY uq_social_communications_public_token (public_token),
    -- **The same source, the same text, the same chief is the same
    -- communication** (issue #793, #706 IT-05), and the database is what
    -- says so: a replayed POST hits this key and the caller reads the row
    -- back instead of creating a second one. It used to be a SELECT
    -- before the INSERT over a 120-second window, which closed the
    -- sequential replay and left two simultaneous POSTs both passing —
    -- `session_write_close()` runs before routing, so PHP's session lock
    -- does not serialise them either.
    --
    -- The refusal is now PERMANENT rather than a window, and that is the
    -- chantier's own rule: sharing the same source again with a DIFFERENT
    -- message is a new communication, allowed with a warning; with the
    -- same message it is the same communication, and what may still leave
    -- is decided by `social_publications`' own key, destination by
    -- destination.
    --
    -- A NULL in any column makes the row collide with nothing, on both
    -- engines. That is the behaviour wanted here, not a loophole: a
    -- communication with no source has nothing to be a duplicate of.
    UNIQUE KEY uq_social_communications_twin (source_kind, source_id, created_by, body_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
