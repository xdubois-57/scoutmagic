<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

/**
 * A backend that can duplicate an object to another key of ITS OWN
 * storage without the bytes travelling through this process — declared as
 * {@see \Core\Storage\Location\StorageCapability::ServerSideCopy}.
 *
 * A server-side `CopyObject` on S3, a `copy()` on a filesystem. The
 * difference it makes is the whole reason it is a capability rather than a
 * convenience: a 900 MB video changing album costs one API call instead of
 * a download and an upload through a shared host's bandwidth.
 *
 * **Same backend only.** It cannot move an object from a local location to
 * a bucket, or between two buckets. A caller spanning two locations reads
 * and re-writes the bytes itself — and should refuse outright rather than
 * pretend this covers it.
 */
interface ServerSideCopyBackend extends StorageBackendInterface
{
    /**
     * @throws \RuntimeException when $fromKey cannot be read
     */
    public function copy(string $fromKey, string $toKey): void;
}
