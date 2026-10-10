<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Modules\Rental\Booking\BookingReference;

/**
 * Finding a booking reference inside text a stranger wrote (§7.6, level 1).
 *
 * The reference is `LOC-XXXXXX` ({@see BookingReference}), which is
 * deliberately distinctive: it is put in every subject line the module
 * sends precisely so a reply carries it back, and — six characters mixing
 * digits and letters — it looks like nothing else anybody would type by
 * accident. The earlier forms, with a year, are not recognised: nothing
 * migrates them, and their bookings' replies are matched by the other rules.
 *
 * **Bracketed first, bare second.** `[LOC-K7Q2MX]` is a reference the
 * module itself put there; a bare `LOC-K7Q2MX` in a body is more likely
 * to be someone quoting a number, which is still usually right but is not
 * the same claim. Both are accepted — a client that strips the brackets
 * from a subject should not cost the unit the match — and the subject is
 * searched before the body for the same reason.
 *
 * **Two different references mean no match.** A renter forwarding one
 * booking's email while asking about another leaves both in the text, and
 * guessing which one they meant is how a message lands on the wrong file.
 */
class BookingReferenceMatcher
{
    /**
     * `LOC-` then the six random characters. Anchored on word boundaries
     * so `XLOC-K7Q2MX` and `LOC-K7Q2MXA` do not match; case-insensitive,
     * because a renter retyping a reference does not keep its capitals.
     */
    private const PATTERN = '/\b' . BookingReference::PATTERN . '\b/i';
    private const BRACKETED_PATTERN = '/\[\s*(' . BookingReference::PATTERN . ')\s*\]/i';

    /**
     * The single reference this text names, or null when it names none or
     * more than one.
     */
    public function match(string $subject, string $bodyText): ?string
    {
        foreach ([$subject, $bodyText] as $haystack) {
            $bracketed = self::uniqueMatch(self::BRACKETED_PATTERN, $haystack);
            if ($bracketed !== null) {
                return $bracketed;
            }
        }

        foreach ([$subject, $bodyText] as $haystack) {
            $bare = self::uniqueMatch(self::PATTERN, $haystack);
            if ($bare !== null) {
                return $bare;
            }
        }

        return null;
    }

    /**
     * The one reference this pattern finds, uppercased, or null when there
     * are none or several distinct ones.
     */
    private static function uniqueMatch(string $pattern, string $haystack): ?string
    {
        if (preg_match_all($pattern, $haystack, $matches) === 0) {
            return null;
        }

        $found = array_unique(array_map(
            static fn(string $reference) => strtoupper(trim($reference, "[] \t")),
            $matches[0]
        ));

        return count($found) === 1 ? (string) reset($found) : null;
    }
}
