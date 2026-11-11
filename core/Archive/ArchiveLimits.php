<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Archive;

/**
 * What one kind of untrusted archive is allowed to be.
 *
 * Every limit answers a different lie a ZIP can tell: {@see $maxEntries} a
 * hundred thousand tiny members, {@see $maxEntryBytes} one member that
 * expands to gigabytes, {@see $maxTotalBytes} many members that are each
 * acceptable and together are not, and {@see $allowedExtensions} a member
 * that is not what the format says it carries. The sizes are DECOMPRESSED
 * sizes as {@see BoundedZipReader} measures them while reading, never the
 * sizes the archive declares about itself.
 */
final class ArchiveLimits
{
    /**
     * @param list<string> $allowedExtensions lower-case extensions without
     *        the dot (`json`, `jsonl`, `jpg`); an empty list accepts any
     *        name, which only a format that never names its members (a
     *        DMARC report) should ask for. A member with no extension at
     *        all is accepted only when `''` is listed.
     */
    public function __construct(
        public readonly int $maxEntries,
        public readonly int $maxEntryBytes,
        public readonly int $maxTotalBytes,
        public readonly array $allowedExtensions = []
    ) {
        if ($maxEntries < 1 || $maxEntryBytes < 1 || $maxTotalBytes < 1) {
            throw new \InvalidArgumentException('Archive limits must be positive.');
        }
    }

    public function allowsName(string $name): bool
    {
        if ($this->allowedExtensions === []) {
            return true;
        }

        $base = basename($name);
        $dot = strrpos($base, '.');
        $extension = $dot === false ? '' : strtolower(substr($base, $dot + 1));

        return in_array($extension, $this->allowedExtensions, true);
    }
}
