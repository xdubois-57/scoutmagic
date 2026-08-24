<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership;

/**
 * Every threshold this module applies, in one place, with a version and a
 * verification date the pages display in their footer.
 *
 * The rules below are not ScoutMagic's: they belong to Belgian law, to the
 * ONE, and to the federation, and each of the three changes on its own
 * schedule without telling anybody. A number inlined at its point of use
 * would be found again only by somebody who already knew it was wrong, so
 * there is deliberately no magic number anywhere else in this module — a
 * reader who wants to know what the module believes reads this file, and a
 * chief who wants to know how old that belief is reads the footer.
 *
 * When a value changes: change it here, bump VERSION, set VERIFIED_ON to
 * the day the new value was actually checked against its source, and add
 * the source to the docblock of the constant. Never bump VERIFIED_ON on a
 * release date, a refactor, or a guess — the date's only job is to answer
 * "when did a human last look at this?", and a date that means anything
 * else is worse than no date.
 */
final class LeadershipRules
{
    /** Bumped whenever any threshold below changes. Shown in the page footer. */
    public const VERSION = '1';

    /**
     * The day a human last checked every threshold below against its
     * source. ISO 8601, UTC-agnostic (a calendar day, not an instant).
     */
    public const VERIFIED_ON = '2026-08-23';

    /**
     * Age at which an "extrait de casier judiciaire" (modèle 2) becomes
     * legally required of an adult supervising minors in Belgium. Stable
     * legal rule, not a federation practice.
     */
    public const ADULT_AGE = 20;

    /**
     * How far ahead the Obligations page announces a 20th birthday. A
     * federation practice rather than a legal rule, so the likeliest of
     * these constants to move.
     */
    public const ADULT_AGE_ALERT_WEEKS = 6;

    /** Minimum age the federation expects of somebody holding an intendance function. */
    public const STEWARD_MIN_AGE = 17;

    /**
     * A steward may be registered as an occasional participant, free of
     * charge, for this many days — outside the summer regime below.
     */
    public const STEWARD_FREE_DAYS = 30;

    /** Attention threshold, in days, on the stewards countdown. */
    public const STEWARD_WARNING_DAYS = 21;

    /** Critical threshold, in days, on the stewards countdown. */
    public const STEWARD_CRITICAL_DAYS = 30;

    /** First day (month, day) of the summer regime — no countdown, guest fee applies. */
    public const SUMMER_REGIME_START = [6, 1];

    /** Last day (month, day) of the summer regime. */
    public const SUMMER_REGIME_END = [8, 31];

    /** ONE supervision ratio: one animateur per this many children. */
    public const ONE_CHILDREN_PER_ANIMATOR = 12;

    /** ONE qualification ratio: one animateur in this many must hold a brevet. */
    public const ONE_ANIMATORS_PER_BREVET = 3;

    /** Alert window expressed in days, for date arithmetic. */
    public static function adultAgeAlertDays(): int
    {
        return self::ADULT_AGE_ALERT_WEEKS * 7;
    }

    /**
     * True when the given date falls inside the summer regime (1 June to
     * 31 August inclusive), which is what decides whether the stewards
     * page shows a countdown or the guest-fee reminder.
     */
    public static function isSummerRegime(\DateTimeImmutable $date): bool
    {
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        [$startMonth, $startDay] = self::SUMMER_REGIME_START;
        [$endMonth, $endDay] = self::SUMMER_REGIME_END;

        $value = $month * 100 + $day;

        return $value >= ($startMonth * 100 + $startDay)
            && $value <= ($endMonth * 100 + $endDay);
    }

    /**
     * How many animateurs the ONE subsidy rules expect for a headcount,
     * and how many of them must hold a brevet. Both round up: eleven
     * children still need one animateur, thirteen need two.
     *
     * @return array{animators: int, brevets: int}
     */
    public static function oneRequirementFor(int $headcount): array
    {
        if ($headcount <= 0) {
            return ['animators' => 0, 'brevets' => 0];
        }

        $animators = (int) ceil($headcount / self::ONE_CHILDREN_PER_ANIMATOR);
        $brevets = (int) ceil($animators / self::ONE_ANIMATORS_PER_BREVET);

        return ['animators' => $animators, 'brevets' => $brevets];
    }
}
