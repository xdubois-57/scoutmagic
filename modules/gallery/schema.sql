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
    og_image_url VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gallery_albums_scout_year (scout_year_id),
    INDEX idx_gallery_albums_section (section_id),
    INDEX idx_gallery_albums_date (album_date),
    CONSTRAINT fk_gallery_albums_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
    CONSTRAINT fk_gallery_albums_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_gallery_albums_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id)
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

-- gallery_s3_secret: singleton row (id always 1) holding the S3 secret
-- access key, encrypted at rest via Core\Security\EncryptionService — same
-- BLOB-via-EncryptionService convention as Modules\LlmConnector\Repository
-- \ProviderRepository's api_key column. Kept out of the generic settings
-- system (which stores plain-text values) since this is a credential, not
-- a preference; the rest of the S3 configuration (endpoint/region/bucket/
-- access key/provider preset) has no confidentiality requirement and is
-- registered as ordinary module.json settings instead.
CREATE TABLE IF NOT EXISTS gallery_s3_secret (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    secret_key_encrypted BLOB NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
