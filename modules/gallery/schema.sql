-- gallery module
--
-- Photo/video albums: either "local" (files uploaded and processed by this
-- module) or "external" (a link to a third-party album, with its Open
-- Graph metadata cached for display). Media are NOT personal data (scout
-- activity content) and are therefore stored unencrypted, unlike e.g.
-- Modules\Finance\Repository\Attachment — access is still restricted to
-- identified users via role_min on the `files` row / the serving route.

-- gallery_storage_locations: one row per configured storage destination —
-- local disk or an S3-compatible bucket. Several may coexist (local +
-- several S3 locations at once) so that changing/adding the "active"
-- destination for NEW albums never breaks reading OLD albums, which always
-- stay pinned to whichever location they were created in (gallery_albums.
-- storage_location_id). Declared before gallery_albums in this file
-- specifically so gallery_albums' FK to it is a backward, not forward,
-- reference (Core\Database\SqlParser builds CREATE TABLE statements in
-- file order — see gallery_albums.cover_media_id's comment for the same
-- constraint in the other direction).
--
-- Discrete columns rather than a JSON config blob: this codebase has no
-- existing precedent for JSON columns, and Core\Database\SchemaComparator
-- (the auto-migration diff engine) only understands flat column
-- definitions — keeping this consistent avoids being the first, untested
-- case for both.
CREATE TABLE IF NOT EXISTS gallery_storage_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('local', 's3') NOT NULL,
    label VARCHAR(255) NOT NULL,
    -- Exactly one location is default at a time (enforced in
    -- Repository\StorageLocationRepository::setDefault(), not here — a
    -- CHECK/trigger for "at most one TRUE row" has no clean, portable
    -- expression in this schema style) — pre-selected for new local albums
    -- (views/album_form.html.twig) instead of an ad-hoc "first/most recent"
    -- pick.
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    -- local only.
    subdir VARCHAR(255) NULL,
    -- s3 only — same fields/meaning as the single-location config this
    -- replaces (module.json's former gallery_s3_* settings).
    s3_provider VARCHAR(50) NULL,
    s3_endpoint VARCHAR(500) NULL,
    s3_region VARCHAR(100) NULL,
    s3_bucket VARCHAR(255) NULL,
    s3_access_key VARCHAR(255) NULL,
    s3_public_url VARCHAR(500) NULL,
    secret_key_encrypted BLOB NULL,
    -- Cached health-check result (Service\StorageLocationService::
    -- checkNow()) — never hit S3/the filesystem synchronously on every
    -- gallery page view; refreshed on an admin's explicit "Tester" click
    -- or lazily once the cached result goes stale.
    last_checked_at DATETIME NULL,
    last_check_ok BOOLEAN NULL,
    last_check_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_gallery_storage_locations_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    -- Which gallery_storage_locations row this album's files live in — set
    -- once at creation (type='local' only) and never changed afterward:
    -- all of an album's media are always in the same location (module
    -- spec). NULL for type='external' (no hosted files) and, transiently,
    -- for a 'local' album created before multi-location support existed —
    -- Service\StorageLocationService::ensureLegacyLocationBackfilled()
    -- self-heals those the first time anything needs to resolve one.
    storage_location_id INT UNSIGNED NULL,
    -- A delegated album: hosted and stored by gallery (any storage
    -- location, local or S3) but owned and access-controlled by another
    -- module, rather than by this module's own section/scout-year
    -- visibility rules. Deliberately mirrors files.owner_type/owner_id
    -- (schema/core.sql) in shape — same nullable VARCHAR(50)/INT UNSIGNED
    -- pair, same non-unique index, no FK (the referenced table varies by
    -- owner_type) — but is a SEPARATE registry: this pair is resolved by
    -- Service\DelegatedAlbumAccessRegistry against
    -- Api\DelegatedAlbumAccessChecker implementations, never by
    -- Core\File\FileAccessGuard, since a delegated album's media are
    -- (mostly) not `files` rows — see gallery_media's own header comment.
    -- NULL on every ordinary album. A non-null owner_type marks the album
    -- delegated: Repository\AlbumRepository::findAll()/findVisible()
    -- both exclude it, so it never appears in gallery's own listings,
    -- pickers or Api\GalleryAlbumProvider — reachable only through its
    -- owning module (Service\DelegatedAlbumService).
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
    migration_target_location_id INT UNSIGNED NULL,
    migration_error TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gallery_albums_scout_year (scout_year_id),
    INDEX idx_gallery_albums_section (section_id),
    INDEX idx_gallery_albums_date (album_date),
    INDEX idx_gallery_albums_storage_location (storage_location_id),
    INDEX idx_gallery_albums_migration_target (migration_target_location_id),
    INDEX idx_gallery_albums_owner (owner_type, owner_id),
    CONSTRAINT fk_gallery_albums_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
    CONSTRAINT fk_gallery_albums_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_gallery_albums_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id),
    CONSTRAINT fk_gallery_albums_storage_location FOREIGN KEY (storage_location_id) REFERENCES gallery_storage_locations(id),
    CONSTRAINT fk_gallery_albums_migration_target FOREIGN KEY (migration_target_location_id) REFERENCES gallery_storage_locations(id),
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

-- gallery_s3_secret: retired singleton (formerly id=1, the one-and-only S3
-- secret) — superseded by gallery_storage_locations.secret_key_encrypted
-- (per row, since there can be several S3 locations now). Left declared,
-- unused, rather than attempting a DROP TABLE: this module's drops.sql
-- mechanism (Core\Database\MigrationRunner::applyExplicitDrops()) only
-- supports column/FK drops, not whole tables — Service\
-- StorageLocationService::ensureLegacyLocationBackfilled() reads this row
-- once (to carry the existing secret into the new table) and never writes
-- to it again.
CREATE TABLE IF NOT EXISTS gallery_s3_secret (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    secret_key_encrypted BLOB NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
