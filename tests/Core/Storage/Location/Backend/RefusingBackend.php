<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageListing;

/**
 * A storage backend where every operation is a mistake.
 *
 * **Refusing rather than stubbing, and that is the point.** A test double
 * that quietly answered `null` or `[]` everywhere would let a subject
 * reach for the network without the suite noticing; each `LogicException`
 * below names the thing the subject had no business doing. A subclass
 * overrides the one method it is actually about.
 *
 * **Four methods answer instead of refusing**, and null is their real
 * answer rather than a stub: no local path, no URL the storage serves
 * itself, no announced checksum. That is what the local disk answers too,
 * and it is a shape every consumer already handles — raising there would
 * make this double refuse something legitimate.
 */
abstract class RefusingBackend implements StorageBackendInterface
{
    public function capabilities(): array
    {
        return [];
    }

    public function supports(StorageCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        throw new \LogicException('this double has no business writing anything');
    }

    public function get(string $key): string
    {
        throw new \LogicException('this double has no business reading anything');
    }

    public function size(string $key): ?int
    {
        throw new \LogicException('this double has no business measuring anything');
    }

    public function exists(string $key): bool
    {
        throw new \LogicException('this double has no business looking anything up');
    }

    public function delete(string $key): void
    {
        throw new \LogicException('this double has no business deleting anything');
    }

    public function deletePrefix(string $prefix): void
    {
        throw new \LogicException('this double has no business deleting anything');
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        throw new \LogicException('this double has no business listing anything');
    }

    public function localPath(string $key): ?string
    {
        return null;
    }

    public function directUrl(string $key, string $ttl = '+1 hour'): ?string
    {
        return null;
    }

    public function stableDirectUrl(string $key): ?string
    {
        return null;
    }

    public function announcedChecksum(string $key): ?string
    {
        return null;
    }

    public function testConnection(): ?string
    {
        throw new \LogicException('this double has no business writing a witness object');
    }
}
