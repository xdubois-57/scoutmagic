<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

use Core\View\MonthGrid\DayState;
use Modules\Rental\Pricing\BillingUnit;

/**
 * Who has the asset on which day, and what a visitor may therefore pick.
 *
 * ## The half-open interval (module spec §6.8)
 *
 * In a **nights** model a stay from the 17th to the 20th occupies the nights
 * of the 17th, 18th and 19th — the 20th is free again from the morning, and
 * another rental may *start* on it. In a **full-days** model the same dates
 * occupy the 20th too, because the asset only comes back at the end of that
 * day. Which model applies is read off the billing unit and never configured
 * separately (`BillingUnit::isNightBased()`).
 *
 * The spec singles out the case that breaks implementations: **two stays
 * that touch**. One ending on the 20th and one starting on the 20th are not
 * in conflict in a nights model, and are in a days model. Both directions
 * are pinned by tests.
 *
 * ## Occupied never says why
 *
 * A booking, a temporary hold and a manual block are one `Occupancy` here,
 * with no discriminator. A public visitor cannot tell them apart because
 * there is nothing to tell apart — see `Occupancy`.
 *
 * ## Unavailable is not the same as unselectable
 *
 * A day inside the minimum-notice window is **free** and merely too late to
 * ask for. It gets the past's greyed-out treatment, never "occupé". A
 * visitor shown "taken" for a free day gives up on it, which is the failure
 * this separation exists to prevent. Occupancy and constraints are computed
 * from different inputs, so a constraint can never produce an occupied cell.
 *
 * Pure: no database, no session, and `$today` is always injected.
 */
class AvailabilityCalculator
{
    /**
     * How many units of the asset are still free on $date.
     *
     * @param Occupancy[] $occupancies
     */
    public function remainingUnitsOn(
        \DateTimeImmutable $date,
        int $totalUnits,
        array $occupancies,
        BillingUnit $billingUnit,
        int $bufferNights = 0
    ): int {
        $taken = 0;

        foreach ($occupancies as $occupancy) {
            if ($this->occupancyCoversDay($occupancy, $date, $billingUnit, $bufferNights)) {
                $taken += max(1, $occupancy->units);
            }
        }

        return max(0, $totalUnits - $taken);
    }

    /**
     * Whether an occupancy holds the asset on $date.
     *
     * The buffer extends an occupancy **after** its departure, never before
     * its arrival: it models the cleaning or the caretaker's round that
     * follows a stay. Extending both ends would leave twice the configured
     * gap between two rentals, which is not what "un battement entre deux
     * locations" means.
     */
    private function occupancyCoversDay(
        Occupancy $occupancy,
        \DateTimeImmutable $date,
        BillingUnit $billingUnit,
        int $bufferNights
    ): bool {
        $day = $date->format('Y-m-d');
        $arrival = $occupancy->arrivalDate;

        if ($day < $arrival) {
            return false;
        }

        $lastOccupiedDay = $this->lastOccupiedDay($occupancy, $billingUnit, $bufferNights);

        return $day <= $lastOccupiedDay;
    }

    /**
     * The last day an occupancy actually holds, buffer included.
     *
     * Nights model: the day before departure (the departure day frees up).
     * Days model: the departure day itself.
     *
     * An occupancy whose end date is held outright — a manual block, which
     * is a closed interval of days and not a stay — skips that rule
     * entirely (Availability\Occupancy::$endDateIsHeld).
     */
    private function lastOccupiedDay(Occupancy $occupancy, BillingUnit $billingUnit, int $bufferNights): string
    {
        $departure = new \DateTimeImmutable($occupancy->departureDate);

        $last = ($billingUnit->isNightBased() && !$occupancy->endDateIsHeld)
            ? $departure->modify('-1 day')
            : $departure;

        if ($bufferNights > 0) {
            $last = $last->modify('+' . $bufferNights . ' days');
        }

        // A same-day occupancy in a nights model would otherwise end before
        // it starts. It still holds its arrival day: somebody has the thing.
        $arrival = new \DateTimeImmutable($occupancy->arrivalDate);

        return max($last->format('Y-m-d'), $arrival->format('Y-m-d'));
    }

    /**
     * Whether a stay of $units from $arrival to $departure fits.
     *
     * Availability only — the constraints are `validateRange()`'s job, and
     * keeping them apart is what stops a notice-period rejection from ever
     * being reported as "occupied".
     *
     * @param Occupancy[] $occupancies
     */
    public function isRangeAvailable(
        \DateTimeImmutable $arrival,
        \DateTimeImmutable $departure,
        int $units,
        int $totalUnits,
        array $occupancies,
        BillingUnit $billingUnit,
        int $bufferNights = 0
    ): bool {
        $days = $this->daysCoveredByStay($arrival, $departure, $billingUnit);

        if ($days === []) {
            // A range holding no day at all — a departure before the
            // arrival, or a same-day range on a night-based asset. The loop
            // below would report "available" by never running, which is how
            // a zero-night stay used to be written onto a fully booked
            // asset. Nothing is available for a stay that is not one.
            return false;
        }

        // The buffer has to bite in BOTH directions, or the answer for an
        // identical pair of stays would depend on which was created first:
        // lastOccupiedDay() pushes a stored occupancy forward, so a
        // candidate arriving after one is held off — but a candidate
        // *ending* where a stored one begins met no buffer at all. Testing
        // the candidate's own trailing buffer nights closes that half.
        if ($bufferNights > 0) {
            $lastDay = $days[count($days) - 1];
            for ($i = 1; $i <= $bufferNights; $i++) {
                $days[] = $lastDay->modify('+' . $i . ' days');
            }
        }

        foreach ($days as $day) {
            if ($this->remainingUnitsOn($day, $totalUnits, $occupancies, $billingUnit, $bufferNights) < $units) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every day a stay from $arrival to $departure actually holds.
     *
     * This is the one place the half-open rule is expressed for a *candidate*
     * stay, mirroring lastOccupiedDay() for an existing one — a divergence
     * between the two is exactly the off-by-one that lets a departure day be
     * double-booked.
     *
     * @return \DateTimeImmutable[]
     */
    public function daysCoveredByStay(
        \DateTimeImmutable $arrival,
        \DateTimeImmutable $departure,
        BillingUnit $billingUnit
    ): array {
        $start = $arrival->setTime(0, 0);
        $end = $departure->setTime(0, 0);

        if ($end < $start) {
            return [];
        }

        $last = $billingUnit->isNightBased() ? $end->modify('-1 day') : $end;
        if ($last < $start) {
            // Same-day, nights model: no night is taken, so nothing is held.
            return $billingUnit->isNightBased() ? [] : [$start];
        }

        $days = [];
        for ($day = $start; $day <= $last; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * Full validation of a requested range: constraints first, then
     * availability.
     *
     * Returns every reason it failed rather than the first, so a visitor is
     * told everything they need to change in one go instead of discovering
     * the rules one refusal at a time.
     *
     * @param Occupancy[] $occupancies
     * @return string[] User-facing French reasons; empty means the range is valid.
     */
    public function validateRange(
        \DateTimeImmutable $arrival,
        \DateTimeImmutable $departure,
        int $units,
        int $totalUnits,
        array $occupancies,
        BillingUnit $billingUnit,
        BookingConstraints $constraints,
        \DateTimeImmutable $today,
        ?int $persons = null
    ): array {
        $errors = [];
        $start = $arrival->setTime(0, 0);
        $end = $departure->setTime(0, 0);

        if ($end < $start) {
            return ['La date de départ doit suivre la date d\'arrivée.'];
        }

        $nights = (int) $start->diff($end)->format('%a');

        if ($billingUnit->isNightBased() && $nights < 1) {
            $errors[] = 'Le séjour doit couvrir au moins une nuit.';
        }

        if ($constraints->minNights > 0 && $nights < $constraints->minNights) {
            $errors[] = sprintf('La durée minimum est de %d nuit%s.', $constraints->minNights, $constraints->minNights > 1 ? 's' : '');
        }

        if ($constraints->maxNights > 0 && $nights > $constraints->maxNights) {
            $errors[] = sprintf('La durée maximum est de %d nuit%s.', $constraints->maxNights, $constraints->maxNights > 1 ? 's' : '');
        }

        if ($start < $constraints->earliestArrival($today)) {
            // Deliberately phrased as "too early to ask", never as
            // "unavailable": the asset may well be free.
            $errors[] = $constraints->minNoticeDays > 0
                ? sprintf('Une demande doit être introduite au moins %d jour%s à l\'avance.', $constraints->minNoticeDays, $constraints->minNoticeDays > 1 ? 's' : '')
                : 'Cette date est déjà passée.';
        }

        $latest = $constraints->latestArrival($today);
        if ($latest !== null && $start > $latest) {
            $errors[] = 'Cette date est trop lointaine pour être réservée dès maintenant.';
        }

        if (!$constraints->allowsArrivalOn($start)) {
            $errors[] = 'Une location ne peut pas commencer ce jour de la semaine.';
        }

        if ($persons !== null && $constraints->maxPersons !== null && $persons > $constraints->maxPersons) {
            $errors[] = sprintf('La capacité maximum est de %d personnes.', $constraints->maxPersons);
        }

        if ($units < 1) {
            $errors[] = 'La quantité demandée doit valoir au moins 1.';
        } elseif ($units > $totalUnits) {
            $errors[] = sprintf('Seul%s %d exemplaire%s existe%s.', $totalUnits > 1 ? 's' : '', $totalUnits, $totalUnits > 1 ? 's' : '', $totalUnits > 1 ? 'nt' : '');
        } elseif (
            $this->daysCoveredByStay($start, $end, $billingUnit) !== []
            && !$this->isRangeAvailable($start, $end, $units, $totalUnits, $occupancies, $billingUnit, $constraints->bufferNights)
        ) {
            // Says nothing about WHY the period is taken. Skipped outright
            // for a range covering no day: the duration rules above have
            // already said what is wrong with it, and adding "cette période
            // n'est pas disponible" on top would blame the asset for what
            // is really a zero-night request.
            $errors[] = 'Cette période n\'est pas disponible.';
        }

        return $errors;
    }

    /**
     * One `DayState` per day of a month, ready for
     * `Core\View\MonthGrid\DayStateGridBuilder`.
     *
     * The precedence between states is the whole confidentiality contract:
     *
     * 1. **Selected** — what the visitor is currently choosing, always shown.
     * 2. **Outside the bookable window** (past, notice period, horizon) —
     *    greyed out, and deliberately ranked ABOVE occupied so a free day the
     *    visitor simply cannot ask for is never mislabelled as taken. It also
     *    means occupancy is not disclosed for days nobody can book anyway.
     * 3. **Occupied / partially available** — from real occupancy, no reason.
     * 4. **Departing** — held in the morning, free from midday.
     * 5. **Free**.
     *
     * `$discloseOccupancy` swaps 2 and 3 — and only for the managed space.
     * The bookable window is a rule about what a *visitor may ask for*, not
     * about what is happening in the hall: applied to a manager's calendar it
     * greyed out every past month and every day inside the notice period,
     * hiding the very bookings that calendar exists to show. A manager has
     * already passed the per-asset authority check, so there is nothing left
     * to withhold from them; nothing else about the occupancy is disclosed
     * either way.
     *
     * @param Occupancy[] $occupancies
     * @param array{0: string, 1: string|null}|null $selection [arrival, departure|null] as `Y-m-d`.
     * @return array<string, DayState> Keyed by `Y-m-d`.
     */
    public function monthDayStates(
        int $year,
        int $month,
        int $totalUnits,
        array $occupancies,
        BillingUnit $billingUnit,
        BookingConstraints $constraints,
        \DateTimeImmutable $today,
        ?array $selection = null,
        bool $discloseOccupancy = false
    ): array {
        // The grid the caller will render spans whole weeks, so states are
        // produced for the padding days of the adjacent months too —
        // otherwise a booking running across the month boundary would appear
        // to stop at the first of the month.
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $from = $first->modify('-7 days');
        $to = $first->modify('last day of this month')->modify('+7 days');

        $selectedDays = $this->selectedDays($selection, $billingUnit);
        $states = [];

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $states[$day->format('Y-m-d')] = $this->stateFor(
                $day,
                $totalUnits,
                $occupancies,
                $billingUnit,
                $constraints,
                $today,
                $selectedDays,
                $discloseOccupancy
            );
        }

        return $states;
    }

    /**
     * @param Occupancy[] $occupancies
     * @param array<string, true> $selectedDays
     */
    private function stateFor(
        \DateTimeImmutable $day,
        int $totalUnits,
        array $occupancies,
        BillingUnit $billingUnit,
        BookingConstraints $constraints,
        \DateTimeImmutable $today,
        array $selectedDays,
        bool $discloseOccupancy = false
    ): DayState {
        $key = $day->format('Y-m-d');

        if (isset($selectedDays[$key])) {
            return new DayState(DayState::STATE_SELECTED, 'Sélectionné', null, true);
        }

        $remaining = $this->remainingUnitsOn($day, $totalUnits, $occupancies, $billingUnit, $constraints->bufferNights);

        if ($discloseOccupancy && $remaining <= 0) {
            // A manager's grid: a booked day reads "Occupé" even in a past
            // month, where the public rule below would have greyed it out
            // and shown nothing at all.
            return new DayState(DayState::STATE_OCCUPIED, 'Occupé', null, false);
        }

        if ($constraints->isOutsideBookableWindow($day, $today)) {
            // NOT "occupé" — the asset may be perfectly free, this day is
            // just not one a visitor can ask for.
            $label = $day < $today->setTime(0, 0)
                ? 'Date passée'
                : ($constraints->latestArrival($today) !== null && $day > $constraints->latestArrival($today)
                    ? 'Trop lointain pour réserver'
                    : 'Trop tôt pour réserver');

            return new DayState(DayState::STATE_UNSELECTABLE, $label, null, false);
        }

        if ($remaining <= 0) {
            return new DayState(DayState::STATE_OCCUPIED, 'Occupé', null, false);
        }

        if ($remaining < $totalUnits) {
            return new DayState(
                DayState::STATE_PARTIAL,
                sprintf('%d disponible%s sur %d', $remaining, $remaining > 1 ? 's' : '', $totalUnits),
                null,
                true,
                ['remaining' => (string) $remaining]
            );
        }

        // Free, but somebody leaves this morning: worth showing distinctly,
        // because it is pickable as an arrival while still being the tail of
        // another stay.
        if ($billingUnit->isNightBased() && $this->isDepartureDay($day, $occupancies, $constraints->bufferNights)) {
            return new DayState(
                DayState::STATE_DEPARTING,
                'Départ le matin, libre ensuite',
                null,
                true
            );
        }

        return new DayState(DayState::STATE_FREE, 'Libre', null, true);
    }

    /**
     * @param Occupancy[] $occupancies
     */
    private function isDepartureDay(\DateTimeImmutable $day, array $occupancies, int $bufferNights): bool
    {
        // With a buffer configured there is no "free from midday" day at all
        // — the buffer already holds it — so the state would be a lie.
        if ($bufferNights > 0) {
            return false;
        }

        $key = $day->format('Y-m-d');
        foreach ($occupancies as $occupancy) {
            if ($occupancy->departureDate === $key && $occupancy->arrivalDate < $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{0: string, 1: string|null}|null $selection
     * @return array<string, true>
     */
    private function selectedDays(?array $selection, BillingUnit $billingUnit): array
    {
        if ($selection === null || $selection[0] === '') {
            return [];
        }

        $arrival = new \DateTimeImmutable($selection[0]);
        $departureRaw = $selection[1] ?? null;

        if ($departureRaw === null || $departureRaw === '') {
            // First tap: only the arrival is chosen yet.
            return [$arrival->format('Y-m-d') => true];
        }

        $days = [];
        foreach ($this->daysCoveredByStay($arrival, new \DateTimeImmutable($departureRaw), $billingUnit) as $day) {
            $days[$day->format('Y-m-d')] = true;
        }

        // The departure day is shown as part of the selection even in a
        // nights model, where it is not billed: a visitor who picked "17 to
        // 20" expects to see the 20th highlighted, and a gap there reads as
        // a bug rather than as a subtlety of night counting.
        $days[(new \DateTimeImmutable($departureRaw))->format('Y-m-d')] = true;

        return $days;
    }

    /**
     * Whether a candidate range crosses anything unavailable — the check
     * behind "impossible de traverser une indisponibilité" (§6.7).
     *
     * @param Occupancy[] $occupancies
     */
    public function rangeCrossesUnavailability(
        \DateTimeImmutable $arrival,
        \DateTimeImmutable $departure,
        int $units,
        int $totalUnits,
        array $occupancies,
        BillingUnit $billingUnit,
        int $bufferNights = 0
    ): bool {
        return !$this->isRangeAvailable($arrival, $departure, $units, $totalUnits, $occupancies, $billingUnit, $bufferNights);
    }
}
