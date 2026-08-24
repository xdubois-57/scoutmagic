<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental;

/**
 * The three one-liners this module kept rewriting: a price for a reader, a
 * form field that may be blank, and "is that string really a date".
 *
 * They are here rather than in nine private copies for one reason each:
 *
 * - **`euros()`** wrote its own separator four times, and every one of
 *   them feeds a value into a booking's history that another reader has to
 *   recognise. `Core\Audit` compares nothing and formats nothing (§8.66),
 *   so "2 450,00 €" recorded by one service and "2450,00 €" by another are
 *   two different prices to anybody reading the timeline.
 * - **`optionalString()`** is the difference between a cleared field and a
 *   field nobody filled in. A copy that forgot the `trim()` stores a
 *   single space, which is not empty, renders as nothing, and is the
 *   reason a "required" check passes on a blank form.
 * - **`isDate()`** is `createFromFormat` PLUS the round-trip check, which
 *   is the whole point: without it PHP happily accepts `2027-02-31` and
 *   hands back the 3rd of March.
 *
 * Deliberately not a service: none of this has state, a collaborator or a
 * reason to be swapped out, and making a caller take a constructor
 * argument to format a price would be worse than the duplication.
 */
final class Support
{
    /** A price for a reader: `2 450,00 €`. Never for a form value. */
    public static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    /**
     * A submitted field as a value or as an absence — a blank box and a
     * box of spaces are both "not filled in", never `''`.
     */
    public static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * A real `Y-m-d` date, round-trip checked so an impossible day is
     * refused rather than silently rolled forward.
     */
    public static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
