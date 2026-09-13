<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Storage\Location\Backend\StorageBackendInterface;

/**
 * The one place a missing capability turns into a refusal.
 *
 * Without it the failure mode is a `Error: Call to undefined method` — a
 * white page, a 500 in the log, and an administrator who learns that
 * « quelque chose s'est mal passé » when what actually happened is that
 * their destination cannot seek inside a video. A capability that is
 * absent is a fact about the storage they chose, so it is answered as
 * one, in French, naming their own location.
 *
 * Call {@see require()} BEFORE the method, not around it: the point is to
 * refuse before anything has been written, read or half-transferred.
 */
final class StorageCapabilities
{
    /**
     * @throws UnsupportedCapabilityException when $backend does not declare $capability
     */
    public static function require(
        StorageBackendInterface $backend,
        StorageCapability $capability,
        string $locationLabel
    ): void {
        if ($backend->supports($capability)) {
            return;
        }

        throw new UnsupportedCapabilityException(sprintf(
            "L'emplacement « %s » ne sait pas %s.",
            $locationLabel,
            $capability->frenchDescription()
        ));
    }
}
