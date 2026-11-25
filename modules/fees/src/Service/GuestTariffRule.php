<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Import\RosterMembershipFacts;
use Core\Service\DateInput;
use Modules\Fees\Value\GuestEligibility;

/** The same rule applies to current households and frozen invoice cohorts. */
final class GuestTariffRule
{
    /** @param array{from: \DateTimeImmutable, until: \DateTimeImmutable}|null $period */
    public static function evaluate(RosterMembershipFacts $facts, string $year, ?array $period): GuestEligibility
    {
        if ($facts->presentPreviousYear === true) {
            return new GuestEligibility(false);
        }
        $first = $facts->firstFunctionStart?->setTime(0, 0);
        if ($first !== null && self::excludesEveryWindow($first, $facts, $year, $period)) {
            return new GuestEligibility(false);
        }
        if ($first === null || $facts->allFunctionStartsKnown !== true) {
            return new GuestEligibility(null, GuestEligibility::UNKNOWN_STARTS);
        }
        if ($period === null) {
            return new GuestEligibility(null, GuestEligibility::UNKNOWN_PERIOD);
        }
        if ($facts->presentPreviousYear !== false) {
            return new GuestEligibility(null, GuestEligibility::UNKNOWN_PREVIOUS_YEAR);
        }

        return new GuestEligibility(true);
    }

    /** @param array{from: \DateTimeImmutable, until: \DateTimeImmutable}|null $period */
    private static function excludesEveryWindow(
        \DateTimeImmutable $first,
        RosterMembershipFacts $facts,
        string $year,
        ?array $period
    ): bool {
        if ($period !== null) {
            $from = $period['from']->setTime(0, 0);
            $until = $period['until']->setTime(0, 0);
        } else {
            $year = FederalScaleLookupService::normalizeYear($year);
            if ($year === null) {
                return false;
            }
            $endingYear = substr($year, 5, 4);
            $from = DateInput::parse('!Y-m-d', $endingYear . '-01-01');
            $until = DateInput::parse('!Y-m-d', $endingYear . '-12-31');
            if ($from === null || $until === null) {
                return false;
            }
        }

        return $first < $from || ($facts->allFunctionStartsKnown === true && $first > $until);
    }
}
