<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * One object as a backend lists it: the key, its size, and whatever the
 * backend volunteers for free while listing.
 *
 * `$announcedChecksum` is null far more often than not, and that is the
 * point of carrying it as a separate, nullable field rather than an empty
 * string: « this destination says nothing comparable » is a different
 * answer from « this destination says the empty checksum », and a copy
 * that treats them alike either skips a verification it could have made or
 * reports every file as corrupt. Only a value directly comparable to an
 * MD5 belongs here.
 */
final class StoredObject
{
    public function __construct(
        public readonly string $key,
        public readonly int $sizeBytes,
        public readonly ?string $announcedChecksum = null,
        public readonly ?string $lastModifiedAt = null
    ) {
    }
}
