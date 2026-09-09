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

    /**
     * Holds the headroom reading pinned before an upload's first fragment.
     * A sidecar rather than a field, because each fragment arrives in its
     * own request and nothing else survives between them.
     */
    private const BUDGET_SUFFIX = '.budget';

    /**
     * @param \Core\Storage\DiskBudget|null $diskBudget checked before each
     *        chunk is appended, against the headroom pinned before the
     *        first one. This is the largest single write the site performs
     *        — a restore archive can reach half a gigabyte — and it grows
     *        one chunk at a time, so per-chunk is where the refusal
     *        belongs: filling the quota half-way through leaves a partial
     *        archive that a restore would then read as corrupt.
     */
    public function __construct(
        private string $storagePath,
        private ?\Core\Storage\DiskBudget $diskBudget = null
    ) {
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

        // The CUMULATIVE size, against a headroom PINNED before the first
        // fragment. Both halves are needed, and each one alone is wrong.
        //
        // Cumulative, because a single chunk proves nothing: checking eight
        // megabytes sixty times lets a half-gigabyte archive through one
        // fragment at a time, which is exactly the mid-write overshoot this
        // guard exists to refuse. `$maxTotalBytes` does not close it either
        // — it bounds the assembled file, not the disk. `$offset` is where
        // this chunk starts, so `$offset + its size` is what the assembled
        // file will weigh.
        //
        // Pinned, because the headroom MOVES while the upload writes:
        // `DiskBudget::availableBytes()` reads `disk_free_space()` live on
        // every call, and each `.part` byte already on disk has already
        // shrunk it. Measuring again and charging the cumulative size would
        // charge `$offset` twice — once because the partial file is already
        // subtracted from the reading, once because it is added to the
        // demand — roughly doubling the room asked for and refusing an
        // upload that fits, on precisely the nearly-full hosts this exists
        // for. So one reading is taken before any byte of this upload lands
        // and is held for its duration, in a sidecar next to the partial
        // because each fragment arrives in a separate request.
        //
        // With no pinned reading — a resumed upload whose sidecar was
        // purged, a temp directory that refused the write, or a host that
        // said nothing in the first place — the fallback is the ordinary
        // live check on this fragment alone. Weaker, never wrong: it can
        // still only under-charge, and it never double-counts.
        //
        // Re-stated as an UploadException, with the sentence written here
        // and the shortfall carried by $previous — the caller catches this
        // type and nothing else (AGENTS.md § Exception messages that reach
        // a visitor).
        $chunkBytes = (int) @filesize($chunkTmpPath);
        $pinnedAvailable = $offset === 0
            ? $this->pinAvailableBytes($path)
            : $this->readPinnedAvailableBytes($path);
        try {
            if ($pinnedAvailable !== null) {
                $this->diskBudget?->ensureRoomAgainst($offset + $chunkBytes, $pinnedAvailable);
            } else {
                $this->diskBudget?->ensureRoom($chunkBytes);
            }
        } catch (\Core\Storage\InsufficientDiskSpaceException $e) {
            throw new UploadException(
                'L\'espace disque disponible ne suffit pas pour recevoir ce fichier. Libérez de la place, '
                . 'puis recommencez le téléversement.',
                0,
                $e
            );
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
        $path = $this->pathFor($uploadId, $sessionId);
        @unlink($path);
        @unlink(self::budgetPathFor($path));
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
                @unlink(self::budgetPathFor($file));
            }
        }
        // A pinned reading whose partial is gone is dead weight, and it
        // would be read back by an upload that reused the same id after a
        // purge. Swept on its own, not only alongside a `.part`, so a
        // failure to unlink one does not strand the other.
        foreach (glob($this->storagePath . self::DIR . '/*' . self::BUDGET_SUFFIX) ?: [] as $file) {
            if (!is_file(substr($file, 0, -strlen(self::BUDGET_SUFFIX)))) {
                @unlink($file);
            }
        }
    }

    /**
     * Takes the headroom reading this upload will be held against and stores
     * it next to the partial, returning it. Null when nothing said — which
     * is also what a host with neither a declared quota nor a readable
     * volume returns, and is not an error.
     *
     * Deliberately silent on a failed write: a temp directory that refuses
     * the sidecar degrades to the live per-fragment check, never to a
     * refused upload.
     */
    private function pinAvailableBytes(string $partialPath): ?int
    {
        $available = $this->diskBudget?->availableBytes();
        $budgetPath = self::budgetPathFor($partialPath);

        if ($available === null) {
            @unlink($budgetPath);

            return null;
        }

        @file_put_contents($budgetPath, (string) $available);

        return $available;
    }

    /** The reading pinned when this upload started, or null when there is none. */
    private function readPinnedAvailableBytes(string $partialPath): ?int
    {
        $raw = @file_get_contents(self::budgetPathFor($partialPath));

        return is_string($raw) && preg_match('/^[0-9]+$/', trim($raw)) === 1 ? (int) trim($raw) : null;
    }

    private static function budgetPathFor(string $partialPath): string
    {
        return $partialPath . self::BUDGET_SUFFIX;
    }
}
