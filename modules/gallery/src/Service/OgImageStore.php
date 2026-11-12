<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\File\UploadException;
use Core\File\UploadHandler;

/**
 * Stores an external album's og:image, already downloaded, as the album's
 * own `files` row — the one way the gallery keeps a third-party image.
 *
 * Through `Core\File\UploadHandler` like any upload: the real type is
 * checked against the four image types, EXIF is stripped, the disk budget
 * is consulted, and the file is served to identified members only, under
 * `gallery/{album}/og`. Best effort, always: an image that is too big, of
 * another type or that does not fit leaves the album without a picture,
 * never without the album (spec §33.1).
 *
 * Shared by a chief's own external album (`AlbumService::cacheOgImage()`,
 * which downloads the bytes itself) and an imported one
 * (`ExternalAlbumImportService`, whose caller already holds them).
 */
class OgImageStore
{
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private UploadHandler $uploadHandler)
    {
    }

    /** The new `files` id, or null when the image was not kept. */
    public function store(int $albumId, string $bytes, int $createdBy): ?int
    {
        if ($bytes === '') {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'gallery_og_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            file_put_contents($tmpPath, $bytes);

            return $this->uploadHandler->handle(
                ['tmp_name' => $tmpPath, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, 'name' => 'og_image'],
                "gallery/{$albumId}/og",
                self::ALLOWED_MIMES,
                self::MAX_BYTES,
                'identified',
                'gallery',
                $createdBy
            );
        } catch (UploadException) {
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }
}
