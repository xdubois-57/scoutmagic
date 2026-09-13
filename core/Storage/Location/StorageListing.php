<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * One page of a listing, plus how to ask for the next one.
 *
 * Paged rather than exhaustive because the caller that needs every object
 * of a location — the copy that keeps a backup location up to date — runs
 * under a time budget and has to be able to stop between two pages and
 * resume where it left off. A method returning « everything » would put a
 * quarter of a million keys in memory and could not be interrupted at all.
 *
 * `$cursor` is opaque and belongs to the backend that produced it: an S3
 * continuation token, a WebDAV path, an offset. A caller stores it and
 * hands it back, and never reads it.
 */
final class StorageListing
{
    /**
     * @param list<StoredObject> $objects
     */
    public function __construct(
        public readonly array $objects,
        public readonly ?string $cursor = null
    ) {
    }

    public function isComplete(): bool
    {
        return $this->cursor === null;
    }
}
