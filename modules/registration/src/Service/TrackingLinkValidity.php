<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * How long a request's tracking link works (issue #932): a number of
 * calendar months counted from the moment the request was filed — never
 * from its first e-mail, its decision or the scout year it targets.
 *
 * The expiry is computed ONCE, when the request is created, and stored
 * (`registration_requests.tracking_expires_at`): changing the setting later
 * only affects the requests filed after it, never an expiry already given.
 */
final class TrackingLinkValidity
{
    public const SETTING = 'registration_tracking_link_validity_months';
    public const DEFAULT_MONTHS = 18;
    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 120;

    /**
     * The configured number of months, or the default when the stored value
     * is not a whole number within the bounds — only the settings page
     * writes it, but a database is not a promise, and a link must never be
     * given an absurd lifetime by a bad value.
     */
    public static function monthsFrom(mixed $stored): int
    {
        $value = trim((string) $stored);
        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return self::DEFAULT_MONTHS;
        }
        $months = (int) $value;

        return $months >= self::MIN_MONTHS && $months <= self::MAX_MONTHS ? $months : self::DEFAULT_MONTHS;
    }

    /**
     * $receivedAt plus $months calendar months, at the same time of day, in
     * $receivedAt's own time zone. When the arrival month is shorter, the
     * last valid day of that month: 31 August + 6 months is 28 (or 29)
     * February, never the 3 March `DateTime::modify('+6 months')` overflows
     * to.
     */
    public static function expiresAt(\DateTimeImmutable $receivedAt, int $months): \DateTimeImmutable
    {
        $months = max(self::MIN_MONTHS, $months);
        $year = (int) $receivedAt->format('Y');
        $month = (int) $receivedAt->format('n') + $months;
        $year += intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;

        $lastDay = (int) $receivedAt->setDate($year, $month, 1)->format('t');
        $day = min((int) $receivedAt->format('j'), $lastDay);

        return $receivedAt->setDate($year, $month, $day);
    }
}
