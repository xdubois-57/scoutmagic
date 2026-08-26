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
 * table for no gain. Called from the two scheduler tails (public/cron.php
 * and index.php's poor-man's cron), next to the journal's own cleanup.
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
     * Deletes cached thumbnails whose file modification time is older
     * than RETENTION_DAYS. Returns how many were removed. Silent on a
     * missing directory (nothing was ever previewed) and on individual
     * unlink failures — a cache purge must never take a request down.
     */
    public static function purgeStale(string $storagePath, int $retentionDays = self::RETENTION_DAYS): int
    {
        $directory = $storagePath . '/' . self::SUBDIRECTORY;
        if (!is_dir($directory)) {
            return 0;
        }

        $cutoff = time() - $retentionDays * 86400;
        $removed = 0;
        foreach (glob($directory . '/*.jpg') ?: [] as $path) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
