<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

/**
 * One file this application has left on a remote destination.
 *
 * `$id` is the destination's own handle and the only way anything here
 * addresses it again; `$name` is for the screen. `$createdAt` is an
 * ISO-8601 string exactly as the destination reported it, never re-parsed
 * into a local timezone — IT-09's remote retention compares these, and a
 * comparison between a remote clock and this server's would delete the
 * wrong file the first time the two disagreed.
 */
final class RemoteFile
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $sizeBytes,
        public readonly string $createdAt
    ) {
    }
}
