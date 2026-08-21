<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * Reassembles a large upload sent as a sequence of small chunks, so the
 * document-root-wide `post_max_size` can stay small (audit M2).
 *
 * `.user.ini` limits are per-directory and every route runs through
 * public/index.php, so a `post_max_size` big enough for a 2 GB gallery video
 * also applied to /login and every other unauthenticated endpoint — and PHP
 * buffers a urlencoded body into memory *before* application code runs, which
 * made the global ceiling an anonymous memory-DoS budget. With the two large
 * consumers (gallery media, backup-restore upload) sending ~8 MB chunks
 * through their normal, RBAC/CSRF-guarded routes instead, the global limit
 * drops to tens of MB.
 *
 * Design constraints, all enforced here rather than trusted to callers:
 * - The upload id is client-generated (32 hex chars) but the on-disk name is
 *   HMAC-like: sha256(session id + ':' + upload id) — so the id can never be
 *   a path, and one session can never append to (or consume) another
 *   session's partial upload, even if it guesses the id.
 * - Chunks append strictly in order: the declared offset must equal the
 *   current file size, checked under an exclusive lock, so a retried or
 *   duplicated chunk is refused instead of corrupting the file — and a retry
 *   after a lost response can resume by asking for the current size.
 * - The assembled size is capped as it grows (the caller passes its own
 *   ceiling), never only at the end — a client cannot stream unbounded data
 *   by simply not sending the "last" flag.
 * - Abandoned partials are purged opportunistically whenever a new upload
 *   starts, so a crashed client doesn't leak disk forever.
 *
 * The assembled file lives under storage/temp and is handed to the caller as
 * a plain path; the caller consumes it (Core\File\UploadHandler accepts a
 * non-uploaded tmp path — its moveFile() falls back to copy()) and then
 * discard()s it.
 */
final class ChunkedUploadStore
{
    private const DIR = '/temp/chunked_uploads';
    private const STALE_AFTER_SECONDS = 24 * 3600;

    public function __construct(private string $storagePath)
    {
    }

    /**
     * Appends one chunk and returns the assembled file's path when $isLast,
     * null otherwise.
     *
     * @param string $chunkTmpPath the chunk's own uploaded tmp file
     * @throws UploadException on a malformed id, an out-of-order offset, or
     *                          an assembled size past $maxTotalBytes
     */
    public function appendChunk(
        string $uploadId,
        string $sessionId,
        int $offset,
        string $chunkTmpPath,
        bool $isLast,
        int $maxTotalBytes
    ): ?string {
        $path = $this->pathFor($uploadId, $sessionId);

        if ($offset === 0) {
            $this->purgeStalePartials();
        }

        $chunk = @fopen($chunkTmpPath, 'rb');
        if ($chunk === false) {
            throw new UploadException('Fragment illisible.');
        }

        $dest = @fopen($path, 'c+b');
        if ($dest === false) {
            fclose($chunk);
            throw new UploadException('Impossible d\'écrire le fichier temporaire.');
        }

        try {
            if (!flock($dest, LOCK_EX)) {
                throw new UploadException('Impossible de verrouiller le fichier temporaire.');
            }

            fseek($dest, 0, SEEK_END);
            $currentSize = ftell($dest);
            if ($currentSize === false || $currentSize !== $offset) {
                // Out-of-order, duplicated, or resumed-with-wrong-offset chunk
                // — report the real size so a client can resume correctly.
                throw new UploadException(
                    'Fragment hors séquence (reçu : ' . (int) $currentSize . ' octets).'
                );
            }

            $written = stream_copy_to_stream($chunk, $dest);
            if ($written === false) {
                throw new UploadException('Écriture du fragment impossible.');
            }

            $newSize = $offset + $written;
            if ($newSize > $maxTotalBytes) {
                flock($dest, LOCK_UN);
                fclose($dest);
                $dest = null;
                @unlink($path);
                $maxMb = round($maxTotalBytes / 1024 / 1024, 1);
                throw new UploadException("Le fichier dépasse la taille maximale autorisée ({$maxMb} Mo).");
            }

            flock($dest, LOCK_UN);
        } finally {
            fclose($chunk);
            if (isset($dest) && is_resource($dest)) {
                fclose($dest);
            }
        }

        return $isLast ? $path : null;
    }

    /**
     * Current assembled byte count, for a client resuming after a lost
     * response. 0 when nothing has been received (or after a purge).
     */
    public function receivedBytes(string $uploadId, string $sessionId): int
    {
        $path = $this->pathFor($uploadId, $sessionId);
        $size = is_file($path) ? filesize($path) : false;

        return $size === false ? 0 : $size;
    }

    /**
     * The assembled file for a completed upload, or null when the id doesn't
     * correspond to this session's finished upload. Used by a consumer that
     * receives the id in a LATER request (the restore form posts the id after
     * the chunks), never by the chunk endpoint itself.
     */
    public function assembledPath(string $uploadId, string $sessionId): ?string
    {
        $path = $this->pathFor($uploadId, $sessionId);

        return is_file($path) ? $path : null;
    }

    public function discard(string $uploadId, string $sessionId): void
    {
        @unlink($this->pathFor($uploadId, $sessionId));
    }

    /**
     * @throws UploadException on a malformed id
     */
    private function pathFor(string $uploadId, string $sessionId): string
    {
        if (preg_match('/^[0-9a-f]{32}$/', $uploadId) !== 1) {
            throw new UploadException('Identifiant d\'envoi invalide.');
        }

        $dir = $this->storagePath . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException('Impossible de créer le dossier temporaire.');
        }

        return $dir . '/' . hash('sha256', $sessionId . ':' . $uploadId) . '.part';
    }

    private function purgeStalePartials(): void
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;
        foreach (glob($this->storagePath . self::DIR . '/*.part') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }
}
