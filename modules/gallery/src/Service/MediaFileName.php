<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\Gallery\Repository\Media;

/**
 * What a downloaded media is CALLED, in the one place both downloads ask.
 *
 * The whole album's zip and a single photo saved from the lightbox now
 * name the same picture identically — which is the point rather than a
 * tidiness: a family saves three photos from an album and ends up with
 * three files, and the one they already have from the zip is recognisably
 * the same one.
 *
 * The media id is part of the name, always. Phones produce colliding
 * names by the thousand (`IMG_1234.jpg` from two different phones is the
 * ordinary case, not the edge one), and a single download has no idea
 * what else is being saved into the same folder — so uniqueness cannot
 * come from a counter the way it did inside a zip, where the archive
 * could see all its own entries. It has to be a property of the name
 * itself.
 *
 * The extension follows the rendition actually handed out: every derived
 * photo is a JPEG and every derived video an MP4 (Service\
 * ImageProcessingService / VideoProcessingService), whatever was
 * uploaded — so a `.heic` original downloads as `.jpg`, which is what
 * the bytes are.
 */
final class MediaFileName
{
    /**
     * Bounded so a pathological original filename cannot produce a name
     * a filesystem refuses (255 bytes is the usual ceiling, and the id
     * plus extension is appended after this).
     */
    private const MAX_BASE_LENGTH = 60;

    public static function forMedia(Media $media): string
    {
        $base = $media->originalFilename !== null
            ? pathinfo($media->originalFilename, PATHINFO_FILENAME)
            : '';

        // ASCII only: this string travels in a Content-Disposition header
        // and into a zip entry, and neither is a good place to discover
        // that a member's phone named a photo in Cyrillic.
        $base = (string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', $base);
        $base = trim($base, '_-');
        $base = mb_substr($base, 0, self::MAX_BASE_LENGTH);
        if ($base === '') {
            $base = 'media';
        }

        return $base . '_' . $media->id . '.' . ($media->isVideo() ? 'mp4' : 'jpg');
    }
}
