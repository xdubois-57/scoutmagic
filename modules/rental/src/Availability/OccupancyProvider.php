<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

/**
 * A source of things that hold an asset: bookings, temporary holds, manual
 * blocks. Each arrives in its own iteration and registers its own provider;
 * Service\RentalAvailabilityService asks all of them and merges the answers.
 *
 * **One bounded call per window, never one per day or per booking.** A month
 * view asks once for the whole month (plus the padding weeks the grid
 * renders); an ICS feed asks once for its bounded window. An implementation
 * that queried per day would turn a calendar render into forty queries and
 * an ICS feed into hundreds.
 *
 * An implementation returns `Occupancy` objects, which deliberately carry no
 * notion of *why* the asset is held — that is what makes a booking, a hold
 * and a block indistinguishable to every public surface (§6.7/§6.14),
 * structurally rather than by remembering to hide it.
 */
interface OccupancyProvider
{
    /**
     * Everything this source knows holding $assetId that overlaps
     * [$from, $to].
     *
     * The bounds are inclusive and generous: the caller widens them to cover
     * the grid it renders, so an implementation should return any occupancy
     * that touches the window at all rather than only those wholly inside it.
     *
     * @return Occupancy[]
     */
    public function findOccupancies(int $assetId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
