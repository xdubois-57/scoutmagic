<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Service\DateInput;

/**
 * A recurring yearly date, stored as a bare "MM-DD" and edited through a
 * native date picker — the scheduled opening and closing of the public
 * form (`registration_scheduled_open_at` / `_close_at`).
 *
 * **The storage never carries a year, the picker always does.** A native
 * `<input type="date">` cannot hold a day without a year, so the page
 * shows the NEXT occurrence of the stored day (nextOccurrence()) and keeps
 * only its month and day on the way back (fromSubmitted()). What the
 * picker displays is therefore a real, upcoming date — the one
 * Task\OpenRegistrationHandler / CloseRegistrationHandler will act on —
 * and choosing any year in it changes nothing but the day and the month.
 *
 * **The 29th of February is refused at input, and handled when read.**
 * A recurring date on a day that exists one year in four would fire one
 * year in four: Task\OpenRegistrationHandler::dueDateForYear() goes
 * through DateInput, which refuses 02-29 on a non-leap year rather than
 * rolling it over to 1 March. So fromSubmitted() refuses it, exactly as
 * the text field this picker replaces already did. The setting is also
 * editable as plain text on the generic Configuration › Paramètres page,
 * which validates nothing, so a stored 02-29 can still exist: rather than
 * showing an empty picker (as if nothing were scheduled) or a 1 March
 * that will never happen, nextOccurrence() answers the next leap year's
 * 29 February — the day the handler would really act on.
 *
 * Pure date arithmetic, no state: static, and tested directly.
 */
final class RecurringMonthDay
{
    /**
     * The longest gap between two 29ths of February (1896 → 1904): looking
     * that far ahead always finds one.
     */
    private const MAX_YEARS_TO_NEXT_LEAP_DAY = 8;

    /**
     * Normalises what the schedule form submits into what the setting
     * stores.
     *
     * - `''` (a field left or made empty) → `''`: no automatic transition.
     * - `YYYY-MM-DD` — what a native date picker posts — → `MM-DD`, once
     *   the full date has been checked to exist (2027-02-30 is refused, not
     *   rolled over).
     * - `MM-DD` — what a browser without a date picker lets somebody type
     *   into the plain text box it falls back to — → itself, once checked
     *   to exist in an ordinary year.
     *
     * Null for anything else, and for the 29th of February in every form.
     */
    public static function fromSubmitted(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            if (DateInput::iso($value) === null) {
                return null;
            }
            $monthDay = $matches[1] . '-' . $matches[2];
        } elseif (preg_match('/^\d{2}-\d{2}$/', $value) === 1) {
            $monthDay = $value;
        } else {
            return null;
        }

        if ($monthDay === '02-29') {
            return null;
        }

        // A common (non-leap) year: everything but 02-29, already refused
        // above, exists in it.
        return DateInput::parse('!Y-m-d', '2023-' . $monthDay) !== null ? $monthDay : null;
    }

    /**
     * The next date, on or after $today, that falls on the stored MM-DD —
     * today itself included, since an occurrence due today has not been
     * missed. Null when the stored value is empty or is not a day of the
     * year at all.
     */
    public static function nextOccurrence(string $monthDay, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{2}-\d{2}$/', $monthDay) !== 1) {
            return null;
        }

        $todayIso = $today->format('Y-m-d');
        $year = (int) $today->format('Y');
        for ($offset = 0; $offset <= self::MAX_YEARS_TO_NEXT_LEAP_DAY; $offset++) {
            $candidate = DateInput::parse('!Y-m-d', sprintf('%04d-%s', $year + $offset, $monthDay));
            if ($candidate !== null && $candidate->format('Y-m-d') >= $todayIso) {
                return $candidate;
            }
        }

        return null;
    }
}
