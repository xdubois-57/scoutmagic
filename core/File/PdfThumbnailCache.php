<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The on-disk cache of rasterized PDF first pages that
 * Core\Http\Controller\FileController::thumbnail() writes — one JPEG per
 * PDF ever previewed, named by file id. Nothing ever deleted them: a
 * thumbnail outlived its PDF (deleting the file left the JPEG orphaned)
 * and the directory grew forever.
 *
 * purgeStale() is age-based rather than reference-counted on purpose: a
 * thumbnail is a pure cache (rebuilt on the next preview), so deleting a
 * still-referenced one costs a single re-rasterization, while tracking
 * which file ids still exist would couple this directory to the files
 * table for no gain. Called from public/cron.php, next to the journal's
 * own cleanup — as it used to be from public/index.php's poor man's cron
 * too, before a real crontab became a requirement and that tail went.
 */
final class PdfThumbnailCache
{
    public const SUBDIRECTORY = 'temp/pdf_thumb';

    /**
     * Generous on purpose: a document being consulted weekly keeps its
     * thumbnail warm forever (each purge pass only removes files not
     * touched since the cutoff, and a rebuild refreshes the mtime).
     */
    public const RETENTION_DAYS = 30;

    /**
     * Which rendering of Core\File\PdfRasterizer a cached thumbnail and a
     * thumbnail URL belong to. Raise it whenever the rendering changes:
     * the disk cache is keyed by it (an old render is never served again,
     * and purgeStale() removes it at once), and so is the URL
     * (FilePublicIds::urlFor()), which browsers hold for a week
     * (FileController sends `max-age=604800`).
     *
     * 2 — #1342: a transparent page came out black.
     */
    public const RENDER_VERSION = 2;

    /** Where the thumbnail of $fileId, in the current rendering, is cached. */
    public static function pathFor(string $storagePath, int $fileId): string
    {
        return $storagePath . '/' . self::SUBDIRECTORY . '/' . $fileId . '.v' . self::RENDER_VERSION . '.jpg';
    }

    /**
     * Deletes cached thumbnails whose file modification time is older
     * than RETENTION_DAYS, and every thumbnail of an older rendering
     * whatever its age — nothing serves it any more. Returns how many
     * were removed. Silent on a missing directory (nothing was ever
     * previewed) and on individual unlink failures — a cache purge must
     * never take a request down.
     */
    public static function purgeStale(string $storagePath, int $retentionDays = self::RETENTION_DAYS): int
    {
        $directory = $storagePath . '/' . self::SUBDIRECTORY;
        if (!is_dir($directory)) {
            return 0;
        }

        $cutoff = time() - $retentionDays * 86400;
        $removed = 0;
        $current = '.v' . self::RENDER_VERSION . '.jpg';
        foreach (glob($directory . '/*.jpg') ?: [] as $path) {
            $outdated = !str_ends_with($path, $current);
            $mtime = @filemtime($path);
            if (($outdated || ($mtime !== false && $mtime < $cutoff)) && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
