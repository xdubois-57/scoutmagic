<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

/**
 * Turning what a member typed into the search box into a LIKE pattern.
 *
 * LIKE rather than a FULLTEXT index, deliberately: a group's posts are
 * counted in hundreds, not millions, the retention purge keeps it that
 * way, and MATCH … AGAINST would tie the module to MySQL alone — the
 * repository tests run on SQLite. If a group ever grows past what a scan
 * can serve, that is the moment to add an index, not before.
 *
 * The escaping below is the part that actually matters. A member typing
 * "100%" or "prix_final" means those characters literally; unescaped they
 * are LIKE wildcards, and "%" alone would silently match every message in
 * the group. `!` is the escape character rather than the customary
 * backslash so nothing here depends on how a given driver treats a
 * backslash inside a string literal.
 */
final class SearchTerm
{
    /**
     * Anything shorter is refused rather than run: a one- or two-letter
     * LIKE '%..%' matches most of a group and answers no question the
     * member was actually asking.
     */
    public const MIN_LENGTH = 3;

    /**
     * A ceiling on what is scanned, not on what may be typed — the input
     * is truncated to this, never rejected for being long.
     */
    public const MAX_LENGTH = 100;

    public const ESCAPE_CHARACTER = '!';

    /**
     * The member's raw input, trimmed and capped — what is echoed back
     * into the search box and shown in "aucun résultat pour …".
     */
    public static function normalise(string $raw): string
    {
        return mb_substr(trim($raw), 0, self::MAX_LENGTH);
    }

    public static function isUsable(string $normalised): bool
    {
        return mb_strlen($normalised) >= self::MIN_LENGTH;
    }

    /**
     * The `%needle%` pattern, with the member's own `%`, `_` and `!`
     * neutralised so they match themselves.
     */
    public static function pattern(string $normalised): string
    {
        $escaped = str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [
                self::ESCAPE_CHARACTER . self::ESCAPE_CHARACTER,
                self::ESCAPE_CHARACTER . '%',
                self::ESCAPE_CHARACTER . '_',
            ],
            $normalised
        );

        return '%' . $escaped . '%';
    }
}
