<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Storage\Location\Backend\StorageBackendInterface;

/**
 * Reading and writing a destination's inventory file.
 *
 * **Three rules, and each of them is a failure this would otherwise
 * have.**
 *
 * 1. **Never overwritten in place.** A document of several megabytes
 *    written over itself and interrupted half way is a document that
 *    parses as nothing — and an inventory that parses as nothing makes the
 *    next pass believe the destination holds no copy of anything, which
 *    means copying the whole source again at best and, once entries are
 *    gone, never deleting anything that should be. So a write goes to a
 *    NEW key carrying a timestamp, and only then is the previous one
 *    removed. `rename` is not available, let alone atomic, on a bucket or
 *    a WebDAV share; a fresh key and a delete is the one sequence every
 *    backend can do.
 *
 * 2. **If it breaks between the two, the reader keeps the most recent
 *    COMPLETE one.** Two documents on the destination is the expected
 *    outcome of an interrupted write, not a corruption: the reader sorts
 *    by the timestamp in the name, parses the newest, and falls back to
 *    the one before it when that fails. Only after a successful read does
 *    it remove the ones it did not keep.
 *
 * 3. **Invisible from the content.** If this destination ever also serves
 *    a gallery, the inventory must not be listed as a medium. Hence the
 *    reserved prefix below, which no consumer writes under and which every
 *    pass skips when it lists the destination.
 *
 * The document is gzip-compressed: it is long, highly repetitive JSON, and
 * on a metered bucket the difference is paid for every night.
 */
class StorageInventoryStore
{
    /**
     * The prefix the storage subsystem keeps for its own bookkeeping.
     *
     * Dot-led and named, so that it sorts apart, reads as deliberate to
     * anybody browsing the destination with a file manager, and cannot
     * collide with a gallery key, which is always `{albumId}/…`.
     */
    public const RESERVED_PREFIX = '.scoutmagic/';

    public function __construct(private readonly ?\Closure $now = null)
    {
    }

    /**
     * The newest complete inventory for one source, or an empty one.
     *
     * **An empty document is also what an unreadable one produces**, and
     * the caller must treat the two the same: « the destination holds
     * nothing I can account for ». That is the safe reading — it copies
     * again, which costs bandwidth — where trusting a half-read document
     * would under-report what is there, and under-reporting is exactly
     * what makes a pass delete a file it should have kept.
     */
    public function load(
        StorageBackendInterface $destination,
        int $sourceLocationId,
        string $sourceLabel,
        int $destinationLocationId
    ): StorageInventory {
        foreach ($this->documentKeysNewestFirst($destination, $sourceLocationId) as $key) {
            $inventory = $this->read($destination, $key);
            if ($inventory !== null) {
                return $inventory;
            }
        }

        return StorageInventory::empty($sourceLocationId, $sourceLabel, $destinationLocationId);
    }

    /**
     * Writes the inventory, unless nothing in it changed.
     *
     * @param string|null $previousFingerprint what {@see StorageInventory::fingerprint()}
     *        answered when this document was loaded. Passing it is what
     *        turns « rewrite several megabytes every night » into « rewrite
     *        when something moved ».
     * @return bool whether a document was actually written
     */
    public function save(
        StorageBackendInterface $destination,
        StorageInventory $inventory,
        ?string $previousFingerprint = null
    ): bool {
        if ($previousFingerprint !== null && $previousFingerprint === $inventory->fingerprint()) {
            return false;
        }

        $now = $this->now();
        $key = $this->documentKey($inventory->sourceLocationId, $now);
        $payload = gzencode($inventory->toJson($now), 6);
        if ($payload === false) {
            // Compression is not optional here: a reader only knows how to
            // decompress. Writing the plain document would produce a file
            // the next pass cannot read, which is worse than not writing.
            return false;
        }

        $destination->put($key, $payload, 'application/gzip');

        // Only now: until the new document is written, the old one is the
        // only description of what is at this destination.
        foreach ($this->documentKeysNewestFirst($destination, $inventory->sourceLocationId) as $existing) {
            if ($existing !== $key) {
                $destination->delete($existing);
            }
        }

        return true;
    }

    /**
     * Whether a key belongs to the storage subsystem rather than to the
     * content — what every pass and every consumer listing a destination
     * has to skip.
     */
    public static function isReservedKey(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_PREFIX);
    }

    /**
     * @return list<string>
     */
    private function documentKeysNewestFirst(
        StorageBackendInterface $destination,
        int $sourceLocationId
    ): array {
        // **Listed on the reserved prefix, then filtered here**, and the
        // difference is not cosmetic: a bucket's `list` takes a genuine
        // string prefix, while `LocalStorageBackend`'s walks a DIRECTORY.
        // Asking either for `…/protection-source-3.` would work on one and
        // silently answer nothing on the other — which reads exactly like
        // an empty destination, and an empty destination is what makes a
        // pass copy everything again. So the listing asks for the one
        // prefix that is a directory on both, and the name match is done
        // where it means the same thing everywhere.
        $name = 'protection-source-' . $sourceLocationId . '.';
        $keys = [];
        $cursor = null;

        do {
            $listing = $destination->list(self::RESERVED_PREFIX, $cursor);
            foreach ($listing->objects as $object) {
                $basename = substr($object->key, strlen(self::RESERVED_PREFIX));
                if (str_starts_with($basename, $name) && str_ends_with($basename, '.json.gz')) {
                    $keys[] = $object->key;
                }
            }
            $cursor = $listing->cursor;
        } while ($cursor !== null);

        // The timestamp is fixed-width and in the name, so a plain string
        // sort is a chronological one — which is why it is written that
        // way rather than as something a locale could reorder.
        rsort($keys);

        return $keys;
    }

    private function read(StorageBackendInterface $destination, string $key): ?StorageInventory
    {
        try {
            $raw = $destination->get($key);
        } catch (\Throwable) {
            return null;
        }

        $json = @gzdecode($raw);

        return $json !== false ? StorageInventory::fromJson($json) : null;
    }

    /**
     * The key one document is written under.
     *
     * **The timestamp is fixed-width and leads, so a string sort is a
     * chronological one** — which is what lets the reader pick the newest
     * without opening any of them.
     *
     * **The random tail is not decoration.** Two saves inside the same
     * millisecond would otherwise produce the same key, and writing to a
     * key that already exists is overwriting a document in place — the one
     * thing this whole class is arranged never to do. It costs four
     * characters and removes the case entirely.
     */
    private function documentKey(int $sourceLocationId, \DateTimeImmutable $at): string
    {
        return sprintf(
            '%sprotection-source-%d.%s-%s.json.gz',
            self::RESERVED_PREFIX,
            $sourceLocationId,
            $at->format('Ymd-His-v'),
            bin2hex(random_bytes(2))
        );
    }

    private function now(): \DateTimeImmutable
    {
        $now = $this->now !== null ? ($this->now)() : new \DateTimeImmutable();

        return $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable();
    }
}
