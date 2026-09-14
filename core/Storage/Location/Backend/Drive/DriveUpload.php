<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend\Drive;

/**
 * How far an upload has got, and whether there is anything left to do.
 *
 * **A send that is not finished is not a failure**, and this type exists
 * so the two cannot be confused. A backup of a whole site does not fit in
 * one request on shared hosting — `max_execution_time` is thirty to a
 * hundred and twenty seconds there — so the ordinary outcome of a run is
 * "some of it went, come back for the rest". An exception would say
 * something went wrong; a `null` id would invite a caller to treat
 * progress as nothing.
 *
 * **Where the pair lives is the one thing IT-05 changed about it.** It
 * used to travel in the scheduled task's payload between runs, which made
 * the database authoritative about a transfer in flight — so restoring a
 * backup moved an upload backwards. Now the session URI is kept beside the
 * partial object at the DESTINATION ({@see GoogleDriveBackend}), and the
 * offset is asked of Google rather than remembered: D12, one level down.
 * Google keeps a session for about a week, which is the real deadline on
 * finishing.
 */
final class DriveUpload
{
    private function __construct(
        public readonly string $sessionUrl,
        public readonly int $offset,
        public readonly string $fileId
    ) {
    }

    /** More to send: this is where the next run picks up. */
    public static function inProgress(string $sessionUrl, int $offset): self
    {
        return new self($sessionUrl, $offset, '');
    }

    /** Google took the last byte and named the file. */
    public static function completed(string $sessionUrl, string $fileId): self
    {
        return new self($sessionUrl, 0, $fileId);
    }

    public function isComplete(): bool
    {
        return $this->fileId !== '';
    }
}
