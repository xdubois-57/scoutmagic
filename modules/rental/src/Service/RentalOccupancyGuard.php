<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Service\DateInput;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingRepository;

/**
 * Exclusive dates, said once (#927, chantier §2.1): the lock every writer of
 * an occupation takes, and the check it makes under it.
 *
 * **Every write that creates, moves or lengthens an occupation** — a public
 * request, an option, a contract sent, a confirmation, a signed copy
 * received or refused, a booking's modification project — goes through
 * `onAsset()` and asks `rangeIsFree()` or `datesAreFree()` inside it, then
 * writes before letting go. There is no exception for anybody: a manager
 * confirming over a competing request's running hold is refused like a
 * visitor, because that hold was let in when the dates were free and they
 * are its own until it lapses.
 *
 * A manual block takes the same lock (`RentalBlockRepository`) and is the
 * one writer that does not ask: a period the unit closes may lie over a
 * stay, and the two coexist (specifications.md §22.8). Every booking asks
 * against it.
 *
 * Built from the same three pieces `RentalOperationsService` already holds,
 * so that service and `RentalSignedContractService` — and the modification
 * projects to come — share one implementation rather than three copies of
 * the same check.
 */
class RentalOccupancyGuard
{
    /**
     * How long, at least, the dates stay held after the unit refuses a
     * signed copy: the renter needs the time to send a correct one, and the
     * firm hold the copy gave has just gone (chantier §2.1).
     */
    public const REFUSED_COPY_GRACE_DAYS = 3;

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService
    ) {
    }

    /**
     * Runs `$work` under the asset's lock, as one transaction
     * (`Repository\AssetLock`). Re-entrant: inside a gesture that already
     * holds it, `$work` simply runs.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function onAsset(int $assetId, callable $work): mixed
    {
        return $this->bookingRepository->withAssetLocked($assetId, $work);
    }

    /**
     * The booking as it stands now — read again under the lock, since the
     * copy a caller loaded is from before it was taken.
     */
    public function fresh(RentalBooking $booking): RentalBooking
    {
        return $this->bookingRepository->findById($booking->id) ?? $booking;
    }

    /**
     * Whether `$units` of `$asset` are free over a range at `$now`, leaving
     * out every occupation carrying `$ownReference` — a booking is never
     * its own conflict, nor is its modification project, and nobody else's
     * occupation is ever left out.
     *
     * Availability only: the public form's rules (notice, horizon, arrival
     * weekdays) shape what a visitor may ask and bind no manager
     * (`RentalAvailabilityService::isRangeFree()`).
     */
    public function rangeIsFree(
        RentalAsset $asset,
        ?string $ownReference,
        string $arrivalDate,
        string $departureDate,
        int $units,
        \DateTimeImmutable $now
    ): bool {
        return $this->availabilityService->isRangeFree(
            $asset,
            $this->pricingService->loadSettings($asset->id)->billingUnit,
            DateInput::requireFromStorage($arrivalDate, 'the checked arrival date'),
            DateInput::requireFromStorage($departureDate, 'the checked departure date'),
            $units,
            $now,
            $ownReference
        );
    }

    /** Whether this booking's own dates are free of everybody else at `$now`. */
    public function datesAreFree(RentalBooking $booking, RentalAsset $asset, \DateTimeImmutable $now): bool
    {
        return $this->rangeIsFree(
            $asset,
            $booking->reference,
            $booking->arrivalDate,
            $booking->departureDate,
            $booking->units,
            $now
        );
    }

    /**
     * Whether this booking may hold its dates from `$now` on: it already
     * does — firmly, or under a hold still running, against which every
     * other writer was checked — or nobody else does.
     */
    public function mayHoldItsDates(RentalBooking $booking, RentalAsset $asset, \DateTimeImmutable $now): bool
    {
        return $booking->occupiesTheAsset($now) || $this->datesAreFree($booking, $asset, $now);
    }

    /**
     * Until when a booking's dates stay held once the unit refused its
     * signed copy at `$refusedAt` — or null when nothing is to change.
     *
     * Pure. **At least `REFUSED_COPY_GRACE_DAYS` after the refusal, capped
     * at the start of the stay, and never shorter than a hold still
     * running** (chantier §2.1). Null for a booking that holds its dates by
     * its status (confirmed, closed), for a final one, and when the stay
     * has begun: there is nothing to extend.
     */
    public static function holdAfterRefusal(
        RentalBooking $booking,
        \DateTimeImmutable $refusedAt
    ): ?\DateTimeImmutable {
        if ($booking->status->isFinal() || $booking->status->firmlyOccupiesTheAsset()) {
            return null;
        }

        $floor = RentalBookingService::capAtArrival(
            $refusedAt->modify('+' . self::REFUSED_COPY_GRACE_DAYS . ' days'),
            $refusedAt,
            $booking->arrivalDate
        );
        if ($floor === null) {
            return null;
        }

        if ($booking->holdUntil !== null && $booking->holdUntil >= $floor) {
            return null;
        }

        return $floor;
    }

    /**
     * Writes `holdAfterRefusal()` onto the booking, keeping the origin of a
     * hold still running — an option stays an option — and starting an
     * automatic one otherwise. Returns the deadline written, or null.
     *
     * To be called under `onAsset()`: it lengthens an occupation. No check
     * is made, and none is needed — until the refusal the dates were held
     * firmly by the copy, so nobody else can have taken them.
     */
    public function holdAfterRefusedCopy(RentalBooking $booking, \DateTimeImmutable $refusedAt): ?\DateTimeImmutable
    {
        $until = self::holdAfterRefusal($booking, $refusedAt);
        if ($until === null) {
            return null;
        }

        $origin = $booking->holdIsActive($refusedAt) && $booking->holdOrigin !== null
            ? $booking->holdOrigin
            : HoldOrigin::AUTOMATIC;
        $this->bookingRepository->setHold($booking->id, $until, $origin);

        return $until;
    }
}
