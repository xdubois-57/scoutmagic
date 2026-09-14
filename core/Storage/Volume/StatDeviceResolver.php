<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Volume;

/**
 * The real answer: `stat()`'s device number.
 *
 * It is what separates a volume from a path, and the two disagree exactly
 * where it matters — a symbolic link, a bind mount, or a `/mnt/nas/photos`
 * that is really a folder on the system disk all read as somewhere else by
 * name and are one filesystem underneath. Grouping by path prefix would
 * therefore report two volumes with a free-space figure each, and an
 * administrator adding them together would believe in twice the room they
 * have.
 */
final class StatDeviceResolver implements DeviceResolver
{
    /**
     * `stat()` on the nearest EXISTING ancestor rather than on the path
     * itself: a location whose folder has not been created yet is still on
     * a volume — the one its parent is on — and answering null for it
     * would put a brand-new local location on a volume of its own, next to
     * the very directory it is about to be created inside.
     */
    public function deviceIdOf(string $path): ?string
    {
        $candidate = rtrim($path, '/');
        if ($candidate === '') {
            $candidate = '/';
        }

        while (!file_exists($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }
            $candidate = $parent;
        }

        $stat = @stat($candidate);

        return is_array($stat) ? (string) $stat['dev'] : null;
    }
}
