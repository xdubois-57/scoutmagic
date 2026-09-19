<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumer;

/**
 * The off-site backup, answering « is anybody still using this
 * location? ».
 *
 * **The second consumer this application has, and the one that proves the
 * shape.** Until IT-05 the gallery was the only thing standing on a
 * storage location, which made it easy to read
 * {@see StorageLocationConsumer} as a gallery-shaped interface. It is not:
 * locations are declared centrally and chosen locally, the choice lives in
 * the chooser's own setting ({@see RemoteBackupDestination::
 * LOCATION_SETTING}), and this is what lets the storage screen refuse to
 * delete a destination without knowing what a backup is.
 *
 * Without it, an administrator tidying up the Stockage page would remove
 * the Drive folder their unit's only off-site copies go to, be told
 * nothing, and discover it on the night the server was gone.
 */
final class RemoteBackupConsumer implements StorageLocationConsumer
{
    public function __construct(private readonly RemoteBackupDestination $destination)
    {
    }

    public function usageLabel(): string
    {
        return 'Sauvegardes hors site';
    }

    /**
     * Read at call time, never cached: an administrator who re-points the
     * backup a minute ago must make the old destination deletable and the
     * new one protected.
     *
     * @return list<int>
     */
    public function locationIdsInUse(): array
    {
        $id = $this->destination->locationId();

        return $id > 0 ? [$id] : [];
    }

    /**
     * **Nothing, and the reasoning is worth writing down** so the next
     * person does not read the empty answer as an oversight.
     *
     * The case this hook exists for is a consumer stranded by a
     * reconfiguration — the gallery's delegated albums, which cannot live
     * on a location that serves publicly for ever. An off-site archive is
     * immune to exactly that: it is encrypted with a phrase that never
     * leaves this server ({@see RemotePassphrase}), so a destination that
     * became world-readable would publish ciphertext. What WOULD strand it
     * — a destination that cannot resume an interrupted upload — is a
     * property of the type, and a location's type is immutable after
     * creation, so no reconfiguration can produce it.
     *
     * The arrangement that IS worth saying something about — a destination
     * that is also a protected location, and therefore has two retention
     * mechanisms acting on the same files — is a warning rather than a
     * refusal, and it lives where the warnings are
     * ({@see \Core\Storage\Location\Protection\StorageProtectionService::
     * warningsFor()}). Refusing here would be the wrong instrument twice
     * over: it would forbid an arrangement that works, and it would be
     * side-steppable by doing the two declarations in the other order.
     */
    public function objectionTo(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): ?string {
        return null;
    }
}
