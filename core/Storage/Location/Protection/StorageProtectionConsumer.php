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
    public function __construct(private readonly StorageProtectionRepository $protections)
    {
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
     * **Nothing to object to, and the reason is worth stating rather than
     * returning a bare null.**
     *
     * This question asks what a reconfiguration would do to what the
     * consumer HOLDS. A protection holds bytes it copied and has no
     * opinion about their shape: it does not read them, serve them, or
     * hand out URLs for them.
     *
     * The objection that does matter here — a private source copied to a
     * destination that publishes — is not a reconfiguration of a location
     * a protection stands on; it is the CHOICE of destination, and it is
     * refused at that moment by
     * {@see StorageProtectionService::refuseExposure()}. Putting it here
     * as well would answer the wrong question: by the time this is asked,
     * the location being reconfigured is the destination, and what is at
     * risk is the source's content, which this consumer cannot name.
     */
    public function objectionTo(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): ?string {
        return null;
    }
}
