<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Core\View\MonthGrid\DayState;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Availability\BookingConstraints;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Availability\OccupancyProvider;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalConstraintsRepository;

/**
 * Everything around `Availability\AvailabilityCalculator` that needs a
 * database: an asset's constraints, and who currently holds it.
 *
 * **Where occupancies come from.** Bookings and manual blocks arrive in
 * later iterations, so this service collects them through
 * `Availability\OccupancyProvider` implementations registered at wiring
 * time. Today there are none and every asset reads as entirely free — which
 * is honest, and lets the calendar, the price estimate and the whole public
 * page be finished and tested now rather than waiting on the booking model.
 * Iteration 4's bookings and iteration 5's manual blocks each add a provider
 * and nothing here changes.
 *
 * That indirection also preserves the confidentiality rule: a provider
 * yields `Occupancy` objects with no notion of *why* the asset is held, so
 * there is no reason for a public surface to leak (§6.7/§6.14).
 */
class RentalAvailabilityService
{
    /**
     * The grid a month view renders spans whole weeks either side, so the
     * window never narrows below this however short the asset's buffer is.
     */
    private const MINIMUM_WINDOW_PAD_DAYS = 7;

    /**
     * @param OccupancyProvider[] $occupancyProviders
     */
    public function __construct(
        private AvailabilityCalculator $calculator,
        private RentalConstraintsRepository $constraintsRepository,
        private array $occupancyProviders = [],
        /**
         * Optional and trailing so the many constructions that only ever
         * *read* availability — the public page, the calendar, the ICS
         * feeds, the task handlers, the test suite — keep working with
         * three arguments. Only the one call site that writes needs it.
         */
        private ?JournalService $journal = null
    ) {
    }

    public function constraintsFor(int $assetId): BookingConstraints
    {
        return $this->constraintsRepository->load($assetId);
    }

    /**
     * How far either side of a range the occupancy query has to reach.
     *
     * A week used to be hard-coded, which was quietly wrong for any asset
     * whose turnaround buffer is longer than that: the conflicting stay fell
     * outside the window, was never returned, and the range came back free.
     * `bufferNights` has no configured ceiling, so the pad has to follow it —
     * plus one day, because the buffer is counted from the day AFTER the last
     * one held.
     */
    private function windowPadDays(BookingConstraints $constraints): int
    {
        return max(self::MINIMUM_WINDOW_PAD_DAYS, $constraints->bufferNights + 1);
    }

    /**
     * Everything holding $assetId between two dates.
     *
     * One bounded call per provider for the whole window — never one per
     * day and never one per booking. A month view asks once; an ICS feed
     * asks once.
     *
     * @param string|null $excludeReference Drops the occupancy carrying this
     *   reference — the "is this range free if I move THIS booking into it?"
     *   question, which must not count a booking against itself.
     * @return Occupancy[]
     */
    public function occupanciesFor(
        int $assetId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now,
        ?string $excludeReference = null
    ): array {
        $all = [];
        foreach ($this->occupancyProviders as $provider) {
            foreach ($provider->findOccupancies($assetId, $from, $to, $now) as $occupancy) {
                if ($excludeReference !== null && $occupancy->reference === $excludeReference) {
                    continue;
                }

                $all[] = $occupancy;
            }
        }

        return $all;
    }

    /**
     * Whether $units of $asset are free over the range — **availability
     * only**, with none of the public booking rules applied.
     *
     * Deliberately not `validateRange()`. Minimum notice, booking horizon
     * and allowed arrival weekdays are rules for the *public form*: they
     * shape what a visitor may ask for. A manager confirming a request that
     * arrived three months ago for a stay next week must not be refused
     * because the asset asks visitors for two weeks' notice — the rule was
     * never about them. What still binds a manager is physical reality:
     * the asset cannot be in two places at once, and there are only so many
     * tents.
     */
    public function isRangeFree(
        RentalAsset $asset,
        BillingUnit $billingUnit,
        \DateTimeImmutable $arrival,
        \DateTimeImmutable $departure,
        int $units,
        \DateTimeImmutable $now,
        ?string $excludeReference = null,
        bool $firmOnly = false
    ): bool {
        $constraints = $this->constraintsFor($asset->id);
        $pad = $this->windowPadDays($constraints);

        $occupancies = $this->occupanciesFor(
            $asset->id,
            $arrival->modify('-' . $pad . ' days'),
            $departure->modify('+' . $pad . ' days'),
            $now,
            $excludeReference
        );

        if ($firmOnly) {
            // A competing request's temporary hold does not stand in the way
            // of a confirmation: two requests for the same week are exactly
            // what a manager is there to arbitrate, and if a soft hold
            // blocked the confirmation neither could ever be accepted.
            // Confirmed bookings and manual blocks are commitments and do
            // stop it (Availability\Occupancy).
            $occupancies = array_values(array_filter(
                $occupancies,
                static fn(Occupancy $occupancy) => $occupancy->isFirm
            ));
        }

        return $this->calculator->isRangeAvailable(
            $arrival,
            $departure,
            max(1, $units),
            max(1, $asset->quantity),
            $occupancies,
            $billingUnit,
            $constraints->bufferNights
        );
    }

    /**
     * The day states for one month of an asset's public calendar.
     *
     * @param array{0: string, 1: string|null}|null $selection
     * @return array<string, DayState>
     */
    public function monthDayStates(
        RentalAsset $asset,
        BillingUnit $billingUnit,
        int $year,
        int $month,
        \DateTimeImmutable $today,
        ?array $selection = null,
        bool $discloseOccupancy = false
    ): array {
        // Widened either side, matching the whole-weeks grid the caller
        // renders — a stay crossing the month boundary must not appear to
        // stop at the 1st — and never by less than the asset's own buffer.
        $constraints = $this->constraintsFor($asset->id);
        $pad = $this->windowPadDays($constraints);

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $occupancies = $this->occupanciesFor(
            $asset->id,
            $first->modify('-' . $pad . ' days'),
            $first->modify('last day of this month')->modify('+' . $pad . ' days'),
            $today
        );

        return $this->calculator->monthDayStates(
            $year,
            $month,
            max(1, $asset->quantity),
            $occupancies,
            $billingUnit,
            $constraints,
            $today,
            $selection,
            $discloseOccupancy
        );
    }

    /**
     * Validates a requested range against both the constraints and real
     * availability.
     *
     * @return string[] User-facing French reasons; empty means valid.
     */
    public function validateRange(
        RentalAsset $asset,
        BillingUnit $billingUnit,
        \DateTimeImmutable $arrival,
        \DateTimeImmutable $departure,
        int $units,
        \DateTimeImmutable $today,
        ?int $persons = null,
        ?string $excludeReference = null
    ): array {
        $constraints = $this->constraintsFor($asset->id);

        // The asset's own capacity is the ceiling when no explicit booking
        // maximum is configured: an operator who filled in "60 places" has
        // already said what the hall holds, and making them repeat it in a
        // second field is how the two end up disagreeing.
        if ($constraints->maxPersons === null && $asset->capacity !== null) {
            $constraints = new BookingConstraints(
                minNights: $constraints->minNights,
                maxNights: $constraints->maxNights,
                minNoticeDays: $constraints->minNoticeDays,
                maxHorizonDays: $constraints->maxHorizonDays,
                allowedArrivalWeekdays: $constraints->allowedArrivalWeekdays,
                maxPersons: $asset->capacity,
                bufferNights: $constraints->bufferNights
            );
        }

        return $this->calculator->validateRange(
            $arrival,
            $departure,
            $units,
            max(1, $asset->quantity),
            $this->occupanciesFor(
                $asset->id,
                $arrival->modify('-' . $this->windowPadDays($constraints) . ' days'),
                $departure->modify('+' . $this->windowPadDays($constraints) . ' days'),
                $today,
                $excludeReference
            ),
            $billingUnit,
            $constraints,
            $today,
            $persons
        );
    }

    /**
     * @param int[] $allowedArrivalWeekdays ISO weekdays (1 = Monday … 7 = Sunday).
     * @param ?int $userId Who is writing — journalled, since these rules are no longer chief-only.
     * @throws RentalException
     */
    public function saveConstraints(
        int $assetId,
        int $minNights,
        int $maxNights,
        int $minNoticeDays,
        int $maxHorizonDays,
        array $allowedArrivalWeekdays,
        ?int $maxPersons,
        int $bufferNights,
        ?int $userId = null
    ): void {
        foreach ([$minNights, $maxNights, $minNoticeDays, $maxHorizonDays, $bufferNights] as $value) {
            if ($value < 0) {
                throw new RentalException('Une contrainte de réservation ne peut pas être négative.');
            }
        }

        if ($maxNights > 0 && $minNights > $maxNights) {
            // Silently accepting this would make every possible range
            // invalid, with nothing on the page to explain why.
            throw new RentalException('La durée minimum ne peut pas dépasser la durée maximum.');
        }

        if ($maxPersons !== null && $maxPersons < 1) {
            throw new RentalException('La capacité maximum doit valoir au moins 1.');
        }

        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', $allowedArrivalWeekdays),
            static fn(int $day) => $day >= 1 && $day <= 7
        )));
        sort($weekdays);

        // All seven selected is the same rule as none selected, and storing
        // it as "none" keeps one representation of "any day".
        if (count($weekdays) === 7) {
            $weekdays = [];
        }

        $this->constraintsRepository->save(
            $assetId,
            new BookingConstraints(
                minNights: $minNights,
                maxNights: $maxNights,
                minNoticeDays: $minNoticeDays,
                maxHorizonDays: $maxHorizonDays,
                allowedArrivalWeekdays: $weekdays,
                maxPersons: $maxPersons,
                bufferNights: $bufferNights
            )
        );

        // Journalled now that these rules are written from the asset's own
        // managed space rather than from an admin-only page: what a visitor
        // may ask for decides what the unit can be held to, and the person
        // who changed it is no longer necessarily a chief. Ids and numbers
        // only — nothing here is personal data (SECURITY.md §11).
        $this->journal?->log(
            'rental',
            'rental_constraints_updated',
            'info',
            'Règles de réservation modifiées',
            ['asset_id' => $assetId, 'min_nights' => $minNights, 'max_nights' => $maxNights],
            $userId
        );
    }
}
