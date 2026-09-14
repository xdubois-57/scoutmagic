<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

/**
 * A backend that can be written to in pieces and picked up again after an
 * interruption — declared as
 * {@see \Core\Storage\Location\StorageCapability::ResumableUpload}.
 *
 * **Why a location's safety copy cannot do without it.** The base
 * interface's `put()` takes a whole string, so writing a 900 MB film
 * through it means holding 900 MB in memory: on the shared hosting this
 * application exists for, that is not slow, it is a fatal on the first
 * large file. And a copy that has to start from zero every time the
 * nightly budget runs out never finishes a film at all — it transfers the
 * same first two hundred megabytes every night, for ever.
 *
 * **The state is the file, not a row** (D12 again, one level down).
 * {@see partialSize()} is the resume offset, read from the partial object
 * itself; nothing about an upload in flight is kept in the database, so
 * nothing about it can be moved backwards by restoring one. A backend that
 * cannot rediscover its own partial state — S3's multipart upload and
 * Drive's resumable session both mint an identifier instead — stores that
 * identifier beside the partial entry in the destination's inventory,
 * which is information about a file of this location and belongs with it.
 *
 * A consumer checks the capability and then narrows to this interface, the
 * same way {@see RangeReadableBackend} is reached; a destination that does
 * not declare it is refused a file too large to pass through memory, in a
 * French sentence, rather than being handed one that will kill the run.
 */
interface ResumableUploadBackend extends StorageBackendInterface
{
    /**
     * How many bytes of an interrupted upload of $key are already stored,
     * or 0 when none is in flight.
     *
     * **This is the offset the next chunk is read from at the source**, so
     * it must describe bytes that are certainly on the destination — never
     * bytes that were merely sent. A backend that cannot promise that must
     * answer 0 and let the copy start again, which costs bandwidth; the
     * alternative writes a file with a hole in it and records it as
     * complete.
     */
    public function partialSize(string $key): int;

    /**
     * Appends to the interrupted upload of $key, after everything
     * {@see partialSize()} has already counted.
     *
     * @throws \RuntimeException when the chunk cannot be stored
     */
    public function appendToPartial(string $key, string $chunk): void;

    /**
     * Turns the completed partial upload into the object itself.
     *
     * **Nothing before this call may be readable as $key.** A consumer
     * listing this location mid-copy must see no object rather than half
     * of one: half a photograph is a photograph as far as every screen is
     * concerned, and it would be copied onward, hashed, and recorded as
     * protected.
     *
     * @throws \RuntimeException when the partial upload cannot be promoted
     */
    public function promotePartial(string $key, string $mimeType): void;

    /**
     * Throws an interrupted upload away.
     *
     * Called when the copy is abandoned or when what arrived disagrees
     * with what was read (D14): an orphaned partial object is eventually
     * taken for a real file by somebody, and deleting it is the only
     * outcome that cannot be misread.
     */
    public function discardPartial(string $key): void;
}
