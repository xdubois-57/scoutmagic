<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumer;
use Core\Storage\Location\StorageLocationRepository;

/**
 * A protection stands on its DESTINATION, and says so.
 *
 * **The first consumer that is part of the storage subsystem itself**, and
 * it is registered like any other rather than special-cased: deleting a
 * location that somebody's safety copy is written to must be refused with
 * the same sentence, on the same screen, as deleting the folder a gallery
 * writes to. A foreign key would have refused it too — `RESTRICT` on the
 * destination is there for exactly that — but it would have refused it as
 * a database error in front of an administrator, which is what this
 * interface exists to prevent.
 *
 * **The SOURCE is deliberately not declared in use.** A location is not
 * kept alive by being protected: deleting it means its content stops being
 * copied, and the relation goes with it (`ON DELETE CASCADE`). What is
 * already at the destination stays, exactly as deleting a location has
 * never deleted its files.
 */
final class StorageProtectionConsumer implements StorageLocationConsumer
{
    public function __construct(
        private readonly StorageProtectionRepository $protections,
        private readonly StorageLocationRepository $locations
    ) {
    }

    public function usageLabel(): string
    {
        return 'Copies de secours';
    }

    /**
     * @return list<int>
     */
    public function locationIdsInUse(): array
    {
        return $this->protections->destinationLocationIds();
    }

    /**
     * **The other half of the refusal that {@see
     * StorageProtectionService::refuseExposure()} makes at declaration.**
     *
     * That one asks, when a protection is declared, whether the
     * destination publishes what the source does not. It answers once and
     * never again — and the same exposure is reachable one screen later,
     * from the other end: declare A (private) protected to B while B
     * publishes nothing, let a pass copy A's files into B, then edit B
     * and give it a public URL. Nothing about that edit concerns A, no
     * consumer stands on B, and every file copied out of A becomes
     * readable by whoever has the address — « published for ever,
     * silently », which is the outcome the declaration-time refusal
     * exists to prevent.
     *
     * An earlier version of this method returned null with a docblock
     * arguing that a protection « holds bytes it has no opinion about »
     * and cannot name the source at risk. The second half was simply
     * wrong: the relation is in this subsystem's own table, and asking it
     * from the destination's side is one query
     * ({@see StorageProtectionRepository::sourceLocationIdsFor()}).
     *
     * **$wouldBeDefault is not consulted, unlike the gallery's.** A
     * protection pins its destination by identifier and never follows the
     * site's default, so promoting a location changes nothing about what
     * copies reach it.
     */
    public function objectionTo(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): ?string {
        if (!$proposedConfig->servesPubliclyWithoutExpiry()) {
            return null;
        }

        $exposed = [];
        foreach ($this->protections->sourceLocationIdsFor($location->id) as $sourceId) {
            $source = $this->locations->findById($sourceId);
            // A source that already publishes loses nothing by being
            // copied somewhere that publishes too — the same asymmetry
            // refuseExposure() is built on. A source that cannot be read
            // at all counts as exposed: « I could not find out » and
            // « this exposes nothing » are opposite conclusions, and only
            // one of them may end in a saved configuration.
            if ($source === null) {
                $exposed[] = null;
                continue;
            }
            if (!$source->config->servesPubliclyWithoutExpiry()) {
                $exposed[] = $source->label;
            }
        }

        if ($exposed === []) {
            return null;
        }

        $named = array_values(array_filter($exposed, static fn(?string $l): bool => $l !== null));

        return sprintf(
            'Cet emplacement contient la copie de secours de %s, qui ne distribue pas d\'adresses publiques. '
                . 'Lui en donner une rendrait accessible à quiconque en a l\'adresse tout ce qui y a déjà été '
                . 'copié. Retirez cette copie de secours avant de publier cet emplacement.',
            $named === []
                ? 'un emplacement'
                : '« ' . implode(' », « ', $named) . ' »'
        );
    }
}
