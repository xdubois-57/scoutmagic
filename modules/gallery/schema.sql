-- gallery module
--
-- Photo/video albums: either "local" (files uploaded and processed by this
-- module) or "external" (a link to a third-party album, with its Open
-- Graph metadata cached for display). Media are NOT personal data (scout
-- activity content) and are therefore stored unencrypted, unlike e.g.
-- Modules\Finance\Repository\Attachment — access is still restricted to
-- identified users via role_min on the `files` row / the serving route.

-- gallery_albums: one row per album, local or external.
CREATE TABLE IF NOT EXISTS gallery_albums (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('local', 'external') NOT NULL,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(255) NULL,
    -- Used for year-based filtering/sorting, independent of created_at
    -- (an album can be entered after the fact for a past activity).
    album_date DATE NOT NULL,
    section_id INT UNSIGNED NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    -- Points at a gallery_media row belonging to this same album — no FK
    -- constraint (Core\Database\SqlParser only recognizes FKs declared
    -- inline inside a CREATE TABLE, and gallery_media doesn't exist yet at
    -- this point in the file; the two tables would form a circular
    -- dependency otherwise). Service\AlbumService::setCover() is the only
    -- writer and always checks the media row belongs to the album first.
    cover_media_id INT UNSIGNED NULL,
    -- External albums only.
    external_url VARCHAR(500) NULL,
    og_title VARCHAR(255) NULL,
    og_description TEXT NULL,
    -- Raw og:image URL as scraped — kept for reference/debugging only,
    -- never rendered directly (the CSP img-src is deliberately narrow and
    -- can't whitelist an arbitrary third-party domain per album, and
    -- hotlinking would leak the viewer's IP/UA to that third party without
    -- consent). og_image_file_id below is the actual served copy.
    og_image_url VARCHAR(500) NULL,
    -- A local, EXIF-stripped copy of og:image downloaded once (best-effort,
    -- same "never blocks album creation" philosophy as the metadata scrape
    -- itself — Service\AlbumService::cacheOgImage()) and served the normal
    -- way via file_url()/`/files/{id}`.
    og_image_file_id INT UNSIGNED NULL,
    -- Which storage_locations row (schema/core.sql) this album's files
    -- live in — set once at creation (type='local' only) and never
    -- changed afterward except by a migration: all of an album's media
    -- are always in the same location (module spec). NULL for
    -- type='external' (no hosted files) and, transiently, for an album
    -- whose location has not been resolved yet — Service\
    -- GalleryLocationService::resolveLocationForAlbum() self-heals those
    -- onto the default location the first time anything needs one.
    --
    -- A NEW column rather than the location_id it replaces, and
    -- that is not cosmetic: the old column's values were identifiers of
    -- the module's own retired table, so a foreign key into the core one
    -- would have been rejected by every row already there. The old column
    -- and its two constraints go in drops.sql; the configuration is
    -- declared again rather than migrated, which is the project's
    -- recorded decision for this whole change (docs/chantiers/
    -- emplacements-de-stockage.md, D16).
    location_id INT UNSIGNED NULL,
    -- A delegated album: hosted and stored by gallery (any storage
    -- location, local or S3) but owned and access-controlled by another
    -- module, rather than by this module's own section/scout-year
    -- visibility rules. Deliberately mirrors files.owner_type/owner_id
    -- (schema/core.sql) in shape — same nullable VARCHAR(50)/INT UNSIGNED
    -- pair, no FK (the referenced table varies by owner_type) — but is a
    -- SEPARATE registry: this pair is resolved by Service\
    -- DelegatedAlbumAccessRegistry against Api\DelegatedAlbumAccessChecker
    -- implementations, never by Core\File\FileAccessGuard, since a
    -- delegated album's media are (mostly) not `files` rows — see
    -- gallery_media's own header comment. NULL on every ordinary album. A
    -- non-null owner_type marks the album delegated: Repository\
    -- AlbumRepository::findAll()/findVisible() both exclude it, so it
    -- never appears in gallery's own listings, pickers or Api\
    -- GalleryAlbumProvider — reachable only through its owning module
    -- (Service\DelegatedAlbumService).
    --
    -- UNIQUE, unlike files.owner_type/owner_id (which stays non-unique —
    -- a file's owner may legitimately have several files): an owner may
    -- have at most one delegated album, and Service\DelegatedAlbumService
    -- ::ensureAlbum() relies on this constraint, not just its own
    -- find-then-create check, to stay correct when two requests race to
    -- create the same owner's album at once — the loser's INSERT fails
    -- fast on this index instead of silently creating a second album, and
    -- ensureAlbum() re-fetches the winner's row instead. MySQL and SQLite
    -- both treat NULL as distinct from every other NULL in a UNIQUE index,
    -- so every ordinary album (owner_type/owner_id both NULL) is
    -- unaffected — only a genuine (owner_type, owner_id) collision is
    -- rejected.
    owner_type VARCHAR(50) NULL,
    owner_id INT UNSIGNED NULL,
    -- Background storage migration (Task\MigrateAlbumStorageHandler,
    -- triggered from the album edit page) — 'in_progress' makes the album
    -- unavailable everywhere its media would otherwise be read (a partially
    -- copied media set could render inconsistently); 'failed' does NOT
    -- block availability, since the source location is always left fully
    -- intact until the very last step of a successful migration, so a
    -- failed attempt simply leaves the album working as before, with the
    -- error surfaced for a retry.
    migration_status ENUM('none', 'in_progress', 'failed') NOT NULL DEFAULT 'none',
    -- Renamed with location_id above, and for the same reason.
    migration_target_id INT UNSIGNED NULL,
    migration_error TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gallery_albums_scout_year (scout_year_id),
    INDEX idx_gallery_albums_section (section_id),
    INDEX idx_gallery_albums_date (album_date),
    INDEX idx_gallery_albums_location (location_id),
    INDEX idx_gallery_albums_migration_target_location (migration_target_id),
    UNIQUE INDEX idx_gallery_albums_owner (owner_type, owner_id),
    CONSTRAINT fk_gallery_albums_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
    CONSTRAINT fk_gallery_albums_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_gallery_albums_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id),
    CONSTRAINT fk_gallery_albums_location FOREIGN KEY (location_id) REFERENCES storage_locations(id),
    CONSTRAINT fk_gallery_albums_migration_target_loc FOREIGN KEY (migration_target_id) REFERENCES storage_locations(id),
    CONSTRAINT fk_gallery_albums_og_image FOREIGN KEY (og_image_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- gallery_media: one row per uploaded photo/video, always tied to a local
-- album (external albums never have media rows). Original is uploaded via
-- Core\File\UploadHandler into the `files` table (file_id); the derived
-- sizes below are written directly to the configured storage backend
-- (local disk or S3) by Task\ProcessPhotoHandler / Task\ProcessVideoHandler
-- and are plain relative keys/paths, not `files` rows — they're always
-- regenerated from the original, never referenced from outside this
-- module, so they don't need the generic file-access machinery.
CREATE TABLE IF NOT EXISTS gallery_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id INT UNSIGNED NOT NULL,
    media_type ENUM('photo', 'video') NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    -- Photo: 300px JPEG. Video: poster frame (JPEG, extracted at 1s).
    thumb_path VARCHAR(255) NULL,
    -- Photo: 1200px JPEG. Video: 720p MP4.
    medium_path VARCHAR(255) NULL,
    -- Photo: max-dimension-capped JPEG. Video: 1080p MP4.
    large_path VARCHAR(255) NULL,
    -- Video only, and only when gallery_keep_original_video is enabled.
    original_path VARCHAR(255) NULL,
    processing_status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    duration_seconds INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    original_filename VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gallery_media_album (album_id, sort_order),
    CONSTRAINT fk_gallery_media_album FOREIGN KEY (album_id) REFERENCES gallery_albums(id) ON DELETE CASCADE,
    CONSTRAINT fk_gallery_media_file FOREIGN KEY (file_id) REFERENCES files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- gallery_link_preview_cache: the SCRAPE RESULT (title/description/raw
-- og:image URL) for a URL, keyed by a plain SHA-256 of the URL — a URL is
-- not personal data (SECURITY.md §5), so unlike files.owner_member_id-style
-- lookups this needs no HMAC blind index, just a fixed-length dedup key.
-- Consulted by Service\LinkPreviewService (the sole implementation of
-- Api\LinkPreviewFetcher) so that two posts linking the same page — in the
-- same group or different ones — don't each re-scrape it. Deliberately
-- caches metadata only, never a downloaded image: the served preview image
-- is a `files` row the CALLING module stores itself, scoped to its own
-- access-control domain (e.g. groups' owner_type 'discussion_group' —
-- Modules\Groups\File\GroupFileOwnershipChecker) — a shared, ungated image
-- cached here once and reused across groups would leak a private group's
-- link preview to a viewer with no membership in it. Re-downloading the
-- (SSRF-protected, size-capped) image on every cache hit costs one bounded
-- outbound fetch; it is the price of keeping each group's copy properly
-- gated.
--
-- No scheduled purge task: Repository\LinkPreviewCacheRepository deletes
-- rows past TTL_HOURS as a side effect of every write instead (same
-- "purge opportunistically" choice as Service\StorageLocationService's own
-- health-check caching), which is enough to bound this table's size given
-- how infrequently group members post links — see the repository for why
-- retro_rate_limits' dedicated scheduled task (§ that module's schema.sql)
-- was not worth replicating here.
CREATE TABLE IF NOT EXISTS gallery_link_preview_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url_hash CHAR(64) NOT NULL,
    title VARCHAR(255) NULL,
    description TEXT NULL,
    image_url VARCHAR(500) NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_glpc_url_hash (url_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
