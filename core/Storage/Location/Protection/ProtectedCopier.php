<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Storage\Location\Backend\RangeReadableBackend;
use Core\Storage\Location\Backend\ResumableUploadBackend;
use Core\Storage\Location\Backend\StorageBackendInterface;

/**
 * Carrying one file from a source location to its safety copy.
 *
 * **The transfer does not go through memory, and that is the constraint
 * everything here is shaped by.** `StorageBackendInterface::get()` hands
 * back a string: a 900 MB film through it is not slow, it is a fatal on
 * the shared hosting this application exists for. So above a threshold the
 * bytes are read in slices ({@see RangeReadableBackend}) and appended at
 * the destination ({@see ResumableUploadBackend}), and a destination that
 * cannot be appended to is REFUSED such a file in a French sentence rather
 * than handed one that will kill the run.
 *
 * **No operation state in the database.** The partial object's own size is
 * the resume offset — the state is the file (D12). A copy interrupted at
 * two in the morning resumes from exactly where the destination stops,
 * whatever any table says, and a restore of this site's database cannot
 * move it.
 *
 * **The digest is computed while reading, never by reading again.** Hashing
 * a film afterwards would double the transfer, which is the one cost this
 * class exists to avoid. `hash_init`/`hash_update`/`hash_final` run over
 * the slices as they pass.
 *
 * **MD5, not SHA-256**, and that is not an oversight. What is being
 * defended against is a truncated or garbled transfer, not an adversary
 * rewriting a file — anybody who can rewrite the destination can rewrite
 * the inventory beside it. And MD5 is the only digest directly comparable
 * to what the storages themselves announce: Drive's `md5Checksum`, and S3's
 * ETag on a single-part upload.
 */
class ProtectedCopier
{
    /**
     * How much is read and appended at a time.
     *
     * 8 MiB: large enough that a film is not ten thousand round trips,
     * small enough to sit inside any `memory_limit` this application runs
     * under, including the 128 MB that shared hosting still ships.
     */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * The largest file that may go through memory in one piece, when the
     * destination cannot be appended to.
     *
     * 64 MiB, which is half of the smallest `memory_limit` worth
     * supporting — the object is held once as the source's answer and
     * again as the destination's argument, so the real high-water mark is
     * twice this.
     */
    public const WHOLE_OBJECT_LIMIT_BYTES = 64 * 1024 * 1024;

    /**
     * Copies $key, resuming an interrupted attempt if one is there.
     *
     * @param callable():bool $hasTimeLeft the pass's own budget, asked
     *        between slices. A copy that runs out of time PAUSES: what
     *        arrived stays as a partial object, and the next run continues
     *        from its size rather than from zero.
     */
    public function copy(
        StorageBackendInterface $source,
        StorageBackendInterface $destination,
        string $key,
        int $expectedSizeBytes,
        string $mimeType,
        callable $hasTimeLeft
    ): CopyOutcome {
        $chunked = $source instanceof RangeReadableBackend
            && $destination instanceof ResumableUploadBackend;

        if (!$chunked) {
            return $this->copyWhole($source, $destination, $key, $expectedSizeBytes, $mimeType);
        }

        return $this->copyInSlices($source, $destination, $key, $expectedSizeBytes, $mimeType, $hasTimeLeft);
    }

    /**
     * @param RangeReadableBackend&StorageBackendInterface $source
     * @param ResumableUploadBackend&StorageBackendInterface $destination
     */
    private function copyInSlices(
        RangeReadableBackend $source,
        ResumableUploadBackend $destination,
        string $key,
        int $expectedSizeBytes,
        string $mimeType,
        callable $hasTimeLeft
    ): CopyOutcome {
        $offset = $destination->partialSize($key);
        if ($offset > $expectedSizeBytes) {
            // The partial object is longer than the file it is a copy of,
            // so it describes a source that changed under it. Nothing can
            // be salvaged from it and keeping it would resume into the
            // middle of something else.
            $destination->discardPartial($key);
            $offset = 0;
        }

        // **The hash context is disposable, and losing it costs the
        // DIGEST rather than the transfer.** A copy that spans two nights
        // cannot carry a `hash_init` context across them, and re-reading
        // what already arrived in order to rebuild one would double the
        // transfer — the single cost this class exists to avoid. So a
        // resumed copy records no digest and is verified on size, exactly
        // as a copy to a destination that announces nothing comparable is.
        $resumed = $offset > 0;
        $context = $resumed ? null : hash_init('md5');

        try {
            // **The destination is told the size before the first byte,
            // and only when nothing is in flight.** A filesystem ignores
            // it; a destination that mints a session needs it, because
            // nothing downstream can tell it which chunk is the last one
            // ({@see ResumableUploadBackend::beginPartial()}). Calling it
            // on a RESUMED copy would open a second transfer and throw
            // away everything the first one had already delivered — which
            // is the one cost this whole class exists to avoid.
            if (!$resumed) {
                $destination->beginPartial($key, $expectedSizeBytes);
            }

            // **An empty file still has to be materialised**, and this is
            // not a theoretical key: `list()` reports a zero-byte object
            // like any other. The loop below never runs for it, so nothing
            // would ever create the partial object, and `promotePartial()`
            // would then throw « no partial upload to promote ». The pass
            // records that as a failure, removes the entry, and meets the
            // same file again tomorrow — a file that can never be
            // protected and that fails every night for ever, silently.
            if ($expectedSizeBytes === 0) {
                $destination->appendToPartial($key, '');
            }

            while ($offset < $expectedSizeBytes) {
                if (!$hasTimeLeft()) {
                    return CopyOutcome::paused($offset);
                }

                $slice = $source->getRange($key, $offset, self::CHUNK_BYTES);
                if ($slice === '') {
                    // The source stopped answering before the size it
                    // announced. Not a copy that can be completed, and not
                    // one whose partial object means anything.
                    $destination->discardPartial($key);

                    return CopyOutcome::failed(sprintf(
                        'La source a cessé de répondre après %d octets sur %d.',
                        $offset,
                        $expectedSizeBytes
                    ));
                }

                $destination->appendToPartial($key, $slice);
                if ($context !== null) {
                    hash_update($context, $slice);
                }
                $offset += strlen($slice);
            }

            $destination->promotePartial($key, $mimeType);
        } catch (\Throwable $e) {
            // The partial object is left in place on purpose: it is the
            // resume offset, and the next run continues from it. What is
            // NOT left in place is a disagreement — see verify() below.
            return CopyOutcome::failed($this->shortReason($e));
        }

        $md5 = $context !== null ? hash_final($context) : null;

        return $this->verify($destination, $key, $expectedSizeBytes, $md5);
    }

    private function copyWhole(
        StorageBackendInterface $source,
        StorageBackendInterface $destination,
        string $key,
        int $expectedSizeBytes,
        string $mimeType
    ): CopyOutcome {
        if ($expectedSizeBytes > self::WHOLE_OBJECT_LIMIT_BYTES) {
            return CopyOutcome::refused(sprintf(
                'Ce fichier fait %s et la destination ne sait pas reprendre un envoi interrompu : il ne peut '
                . 'pas être copié sans tenir en mémoire en entier. Choisissez une destination qui sait le '
                . 'faire, ou laissez ce fichier hors de la copie.',
                \Core\Storage\ByteFormatter::format($expectedSizeBytes)
            ));
        }

        try {
            $contents = $source->get($key);
            $destination->put($key, $contents, $mimeType);
        } catch (\Throwable $e) {
            return CopyOutcome::failed($this->shortReason($e));
        }

        return $this->verify($destination, $key, $expectedSizeBytes, md5($contents));
    }

    /**
     * What arrived, against what was read.
     *
     * **The size is always checked; the digest only when the destination
     * announces something comparable.**
     *
     * **The S3 trap, which is worth naming because getting it wrong looks
     * like total corruption.** An ETag equals the object's MD5 only for an
     * upload sent in one piece. For a multipart upload it is a digest OF
     * THE PART DIGESTS with the part count after a hyphen —
     * `"d41d8cd98f00b204e9800998ecf8427e-12"`. Comparing that to an MD5
     * fails every single time, and somebody reading the result would
     * conclude that every copy this site ever made is corrupt. So a
     * checksum carrying a hyphen is not comparable, and the size is what
     * the verification rests on.
     */
    private function verify(
        StorageBackendInterface $destination,
        string $key,
        int $expectedSizeBytes,
        ?string $md5
    ): CopyOutcome {
        $storedSize = $destination->size($key);
        if ($storedSize !== null && $storedSize !== $expectedSizeBytes) {
            $reason = sprintf(
                'La copie fait %d octets alors que la source en annonce %d.',
                $storedSize,
                $expectedSizeBytes
            );

            return $this->disagree($destination, $key, $reason);
        }

        $announced = $this->comparableChecksum($destination, $key);
        if ($md5 !== null && $announced !== null && !hash_equals($announced, $md5)) {
            return $this->disagree($destination, $key, 'L\'empreinte de la copie ne correspond pas à la source.');
        }

        return CopyOutcome::completed($expectedSizeBytes, $md5);
    }

    /**
     * The destination's own checksum when it is an MD5, null otherwise.
     */
    private function comparableChecksum(StorageBackendInterface $destination, string $key): ?string
    {
        try {
            $announced = $destination->announcedChecksum($key);
        } catch (\Throwable) {
            return null;
        }

        if ($announced === null) {
            return null;
        }
        $announced = strtolower(trim($announced, "\" \t\n\r\0\x0B"));

        // A multipart ETag, or anything else that is not 32 hex
        // characters, is not an MD5 and must never be compared to one.
        return preg_match('/^[0-9a-f]{32}$/', $announced) === 1 ? $announced : null;
    }

    /**
     * **On disagreement the copy is removed, not left in place** (D14).
     *
     * A wrong object under the right key is worse than no object: the next
     * pass sees the key, sees the size it expects or a size it cannot
     * check, and records the file as protected. Deleting it is the only
     * outcome that cannot be misread — the file is copied again on the
     * next pass, which is what a failure should cost.
     */
    private function disagree(StorageBackendInterface $destination, string $key, string $reason): CopyOutcome
    {
        try {
            $destination->delete($key);
            if ($destination instanceof ResumableUploadBackend) {
                $destination->discardPartial($key);
            }
        } catch (\Throwable) {
            // Best effort: the outcome is a failure either way, and the
            // next pass copies the file again.
        }

        return CopyOutcome::failed($reason);
    }

    /**
     * One line for the journal, from an exception this class did not
     * write.
     *
     * Truncated and never shown to an administrator as-is: a driver's own
     * words can carry a path, a bucket, or a host, and this string ends up
     * in a journal that a support package carries.
     */
    private function shortReason(\Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 200);
    }
}
