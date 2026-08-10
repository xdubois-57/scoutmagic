<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Repository\AgeBracket;

/**
 * Pure translation between a "slot" (branch × year-in-branch, the module's
 * unit of measure — see module docs) and a birth year, plus the waitlist
 * tier ratio math. No database access, so this stays trivially unit
 * testable — Service\SlotService is the DB-touching orchestration layer
 * that feeds this class real data.
 *
 * The whole point of a *day-and-month* reference date (not just a
 * reference year) is that it decides WHICH of the two calendar years a
 * scout year spans (September of year Y through August of year Y+1)
 * should be used to compute age: a reference date in September–December
 * falls in year Y itself; a reference date in January–August falls in
 * year Y+1 (the scout year hasn't finished yet, but time has moved into
 * its second calendar year). The default (12-31) intentionally lands on
 * the *first* year — the same simplification Core\Member\
 * MemberYearService::referenceYearFromScoutYearLabel() already makes for
 * existing members (a scout year's reference year is always its start
 * year) — so a unit that never touches this setting gets numbers
 * consistent with how the rest of the site already treats ages.
 *
 * Given a reference month-day fixes exactly one age per birth year (this
 * deliberately never splits a birth year into two ages by exact birth day
 * — nothing about a not-yet-registered child's exact birth day is known
 * ahead of the parent filling in the form, and the module spec asks for a
 * birth-YEAR list, not day-level precision), each (branch, year-in-branch)
 * slot maps to exactly one birth year.
 */
final class SlotMath
{
    public const TIER_AVAILABLE = 'available';
    public const TIER_LIMITED = 'limited';
    public const TIER_HEAVY = 'heavy';

    /**
     * @param string $referenceMonthDay "MM-DD", e.g. "12-31"
     */
    public static function referenceCalendarYear(int $targetScoutYearStartYear, string $referenceMonthDay): int
    {
        $month = (int) substr($referenceMonthDay, 0, 2);

        return $month >= 9 ? $targetScoutYearStartYear : $targetScoutYearStartYear + 1;
    }

    public static function ageForSlot(AgeBracket $bracket, int $yearInBranch): int
    {
        return $bracket->ageForYearInBranch($yearInBranch);
    }

    public static function birthYearForSlot(AgeBracket $bracket, int $yearInBranch, int $referenceCalendarYear): int
    {
        return $referenceCalendarYear - self::ageForSlot($bracket, $yearInBranch);
    }

    /**
     * The reverse: which (branch, year-in-branch) slot a given birth date
     * falls into, for the "section souhaitée" filter on the form. Null
     * when no configured bracket covers that age (e.g. too young/old for
     * every declared branch).
     *
     * @param array<AgeBracket> $brackets
     * @return array{age_branch_id: int, year_in_branch: int}|null
     */
    public static function slotForBirthDate(array $brackets, string $birthDate, int $referenceCalendarYear): ?array
    {
        $birthYear = self::extractYear($birthDate);
        if ($birthYear === null) {
            return null;
        }
        $age = $referenceCalendarYear - $birthYear;

        foreach ($brackets as $bracket) {
            $lastAge = $bracket->entryAge + $bracket->durationYears - 1;
            if ($age >= $bracket->entryAge && $age <= $lastAge) {
                return [
                    'age_branch_id' => $bracket->ageBranchId,
                    'year_in_branch' => $age - $bracket->entryAge + 1,
                ];
            }
        }

        return null;
    }

    /**
     * The slot that "feeds" the given (branch, year-in-branch) slot: the
     * one rank below in the same branch, or — for year-in-branch 1 — the
     * last year of the branch immediately before it in $orderedBrackets
     * (canonical branch order, see Repository\AgeBracketRepository::
     * findAllOrdered()). Null when there is no such feeder (year-in-branch
     * 1 of the very first configured branch).
     *
     * @param array<AgeBracket> $orderedBrackets
     * @return array{age_branch_id: int, year_in_branch: int}|null
     */
    public static function feederSlot(array $orderedBrackets, int $ageBranchId, int $yearInBranch): ?array
    {
        if ($yearInBranch > 1) {
            return ['age_branch_id' => $ageBranchId, 'year_in_branch' => $yearInBranch - 1];
        }

        $index = null;
        foreach ($orderedBrackets as $i => $bracket) {
            if ($bracket->ageBranchId === $ageBranchId) {
                $index = $i;
                break;
            }
        }
        if ($index === null || $index === 0) {
            return null;
        }

        $previous = $orderedBrackets[$index - 1];

        return ['age_branch_id' => $previous->ageBranchId, 'year_in_branch' => $previous->lastYearInBranch()];
    }

    /**
     * Ratio-based tier — the module spec is explicit that a fixed number
     * of remaining spots means nothing without the slot's own capacity as
     * context (a 40-member unit and a 200-member unit shouldn't read the
     * same absolute number the same way). $capacity 0 is always "heavy":
     * an unconfigured or intentionally-zero slot has no room to offer.
     */
    public static function tierForRemaining(int $capacity, int $remainingEstimate, float $availableThreshold, float $limitedThreshold): string
    {
        if ($capacity <= 0) {
            return self::TIER_HEAVY;
        }

        $ratio = $remainingEstimate / $capacity;

        if ($ratio >= $availableThreshold) {
            return self::TIER_AVAILABLE;
        }
        if ($ratio >= $limitedThreshold) {
            return self::TIER_LIMITED;
        }

        return self::TIER_HEAVY;
    }

    private static function extractYear(string $date): ?int
    {
        return preg_match('/(\d{4})-\d{2}-\d{2}/', $date, $m) === 1 ? (int) $m[1] : null;
    }
}
