<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

/**
 * Finding a booking reference inside text a stranger wrote (§7.6, level 1).
 *
 * The reference is `LOC-YYYY-NNNN`, which is deliberately distinctive: it
 * is put in every subject line the module sends precisely so a reply
 * carries it back, and it looks like nothing else anybody would type by
 * accident.
 *
 * **Bracketed first, bare second.** `[LOC-2027-0042]` is a reference the
 * module itself put there; a bare `LOC-2027-0042` in a body is more likely
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
     * `LOC-` then a four-digit year, then the sequence. Anchored on a
     * non-word boundary so `XLOC-2027-0042` does not match.
     */
    private const PATTERN = '/\bLOC-(\d{4})-(\d{1,6})\b/i';
    private const BRACKETED_PATTERN = '/\[\s*(LOC-\d{4}-\d{1,6})\s*\]/i';

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
