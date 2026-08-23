<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

/**
 * What PhotoIngestionService did with one uploaded image.
 *
 * `fileId` is null for the one context that never becomes a `files` row at
 * all — `unit_logo`, which UnitLogoService stores on its own because every
 * `/files/{id}` download is fetched with no session (see that service's own
 * docblock).
 *
 * `linked` says whether the context's target was actually pointed at the new
 * file. It can be false with a perfectly valid `fileId`: a malformed key, or
 * an `account_photo` key naming somebody else's account. The caller uses it to
 * decide whether there is anything to journal — the service itself writes no
 * journal entry and no user-facing text, so that a command-line caller does
 * not have to pretend to be a request.
 *
 * `journalContext` is the payload the web caller records alongside its own
 * message, kept here so the key never has to be parsed twice.
 */
final class PhotoIngestionResult
{
    /**
     * @param array<string, int> $journalContext
     */
    public function __construct(
        public readonly ?int $fileId,
        public readonly bool $linked,
        public readonly array $journalContext = [],
    ) {
    }
}
