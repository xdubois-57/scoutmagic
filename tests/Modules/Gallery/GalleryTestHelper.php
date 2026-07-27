<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery;

class GalleryTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE gallery_albums (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            subtitle TEXT NULL,
            album_date TEXT NOT NULL,
            section_id INTEGER NULL,
            scout_year_id INTEGER NOT NULL,
            cover_media_id INTEGER NULL,
            external_url TEXT NULL,
            og_title TEXT NULL,
            og_description TEXT NULL,
            og_image_url TEXT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (section_id) REFERENCES sections(id),
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE gallery_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            album_id INTEGER NOT NULL,
            media_type TEXT NOT NULL,
            file_id INTEGER NOT NULL,
            thumb_path TEXT NULL,
            medium_path TEXT NULL,
            large_path TEXT NULL,
            original_path TEXT NULL,
            processing_status TEXT NOT NULL DEFAULT "pending",
            width INTEGER NULL,
            height INTEGER NULL,
            duration_seconds INTEGER NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            original_filename TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (album_id) REFERENCES gallery_albums(id)
        )');

        $pdo->exec('CREATE TABLE gallery_s3_secret (
            id INTEGER PRIMARY KEY,
            secret_key_encrypted BLOB NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
