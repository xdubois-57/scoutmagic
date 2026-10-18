<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Repository\AgeBracket;

/**
 * Pure translation between a "slot" (branch × year-in-branch, the module's
 * unit of measure — see module docs) and a birth year, plus the occupancy
 * level math. No database access, so this stays trivially unit
 * testable — Service\SlotService is the DB-touching orchestration layer
 * that feeds this class real data.
 *
 * A slot is reached by the birth year alone, counted from the target
 * scout year's START year — the same rule Core\Member\MemberYearService::
 * getEffectiveAge() applies to existing members, through
 * referenceYearFromScoutYearLabel(). A child's exact birth date, or their
 * age on any given day, plays no part (issue #891): a request and a
 * member born the same year always land in the same slot, otherwise the
 * capacity check would weigh one cohort's requests against another's
 * occupancy. A request has no scout-year offset — that is a chief's
 * correction to a member's course, applied once they are one.
 *
 * So each (branch, year-in-branch) slot maps to exactly one birth year,
 * and the public page lists birth years, never ages.
 */
final class SlotMath
{
    /**
     * The three levels of a slot. The recommendation says them as Oui /
     * Peut-être / Non, the public page as « Disponible » / « Limitée » /
     * « Complet » — one value each, read through the same two thresholds.
     */
    public const LEVEL_YES = 'yes';
    public const LEVEL_MAYBE = 'maybe';
    public const LEVEL_NO = 'no';

    /** The bounds of a threshold, in percent of the places occupied. */
    public const THRESHOLD_MIN_PERCENT = 1;
    public const THRESHOLD_MAX_PERCENT = 120;

    public static function ageForSlot(AgeBracket $bracket, int $yearInBranch): int
    {
        return $bracket->ageForYearInBranch($yearInBranch);
    }

    public static function birthYearForSlot(AgeBracket $bracket, int $yearInBranch, int $referenceYear): int
    {
        return $referenceYear - self::ageForSlot($bracket, $yearInBranch);
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
    public static function slotForBirthDate(array $brackets, string $birthDate, int $referenceYear): ?array
    {
        $birthYear = self::extractYear($birthDate);
        if ($birthYear === null) {
            return null;
        }
        $age = $referenceYear - $birthYear;

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
     * The level of a slot at a given occupancy — the ONE reading of the
     * thresholds, shared by the public page (Oui = « Disponible »,
     * Peut-être = « Limitée », Non = « Complet »), the chief's capacity
     * check, and the recommendation built on top of them. Two readings of
     * the same two percentages would let a family read « Disponible » for
     * a slot the chief is told is already in « Peut-être ».
     *
     * The model is an OCCUPANCY ratio, `occupied / capacity`, compared to
     * two percentages with the bound INCLUDED: « Oui jusqu'à 70 % » still
     * answers Oui at exactly 70 %, « Peut-être jusqu'à 100 % » still
     * answers Peut-être with every place taken, and Non starts strictly
     * above the second one. The comparison is done in integers
     * (`occupied × 100 ≤ percent × capacity`), never on a float ratio, so
     * a bound that falls exactly on a number of places cannot be missed by
     * a rounding error.
     *
     * **A null capacity is NOT a zero one**, and this signature is where
     * that rule is enforced for the whole module:
     *
     * - `null` — no limit recorded for this slot: always Oui, whatever the
     *   occupancy. It is never announced full.
     * - `0` — the slot is deliberately CLOSED: always Non, even empty.
     *
     * Taking `int` here would let PHP coerce a null to 0 at the call site
     * and quietly announce an unconfigured branch as complete.
     */
    public static function levelForOccupancy(
        ?int $capacity,
        int $occupied,
        int $yesPercent,
        int $maybePercent
    ): string {
        if ($capacity === null) {
            return self::LEVEL_YES;
        }
        if ($capacity <= 0) {
            return self::LEVEL_NO;
        }

        $occupiedHundredths = $occupied * 100;
        if ($occupiedHundredths <= $yesPercent * $capacity) {
            return self::LEVEL_YES;
        }
        if ($occupiedHundredths <= $maybePercent * $capacity) {
            return self::LEVEL_MAYBE;
        }

        return self::LEVEL_NO;
    }

    /**
     * One threshold as a chief types it: a whole number of percent between
     * THRESHOLD_MIN_PERCENT and THRESHOLD_MAX_PERCENT. Null for anything
     * else — empty, decimal, negative, out of range, not a number — so the
     * caller refuses it rather than storing a value that would silently
     * change how full every slot looks.
     */
    public static function parseThresholdPercent(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);
        if (preg_match('/^\d{1,3}$/', $trimmed) !== 1) {
            return null;
        }
        $percent = (int) $trimmed;

        return $percent >= self::THRESHOLD_MIN_PERCENT && $percent <= self::THRESHOLD_MAX_PERCENT
            ? $percent
            : null;
    }

    /**
     * The two thresholds only make sense in order: « Oui » must stop
     * strictly before « Peut-être » does, or the Peut-être band would be
     * empty or inverted.
     */
    public static function isValidThresholdPair(int $yesPercent, int $maybePercent): bool
    {
        return $yesPercent < $maybePercent;
    }

    private static function extractYear(string $date): ?int
    {
        return preg_match('/(\d{4})-\d{2}-\d{2}/', $date, $m) === 1 ? (int) $m[1] : null;
    }
}
