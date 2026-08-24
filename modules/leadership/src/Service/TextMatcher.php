<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Core\Service\TextNormalizerService;

/**
 * Case- and accent-insensitive matching for the two places this module
 * compares free text it did not write: a Desk function label (does it say
 * "candidat"?) and a Desk formation level (which step is that?).
 *
 * Both inputs are federation wording, typed by hand somewhere upstream, and
 * arrive with whatever casing, accents and spacing that entailed. Folding
 * both sides of every comparison is what stops "Candidat·e Animateur" and
 * "CANDIDAT ANIMATEUR" from being two different things.
 *
 * The folding itself is `Core\Service\TextNormalizerService::fold()`, which
 * is where its trade-offs are written down. This module is where that rule
 * was first got right, not where it belongs; what stays here is the three
 * questions this module asks of a folded string.
 */
final class TextMatcher
{
    /**
     * Lowercase, accent-folded, punctuation-collapsed — the one function
     * both sides of every comparison in this module go through.
     */
    public static function fold(?string $value): string
    {
        return TextNormalizerService::fold($value);
    }

    /**
     * True when $needle (already plain ASCII, lowercase) appears anywhere in
     * the folded $haystack.
     */
    public static function contains(?string $haystack, string $needle): bool
    {
        $folded = self::fold($haystack);

        return $folded !== '' && str_contains($folded, $needle);
    }

    /**
     * True when $needle appears in the folded $haystack **as a whole word**.
     *
     * Folding collapses every run of non-alphanumerics to one space, so a
     * word here is a run bounded by spaces or by the ends of the string —
     * and "POST2015" therefore does NOT contain the word "t2", though it
     * plainly contains the letters. That distinction is the whole point:
     * a two-character needle matched as a substring fires on export
     * wordings nobody thought about, and a confidently-wrong training step
     * never announces itself the way an unrecognised one does.
     */
    public static function containsWord(?string $haystack, string $needle): bool
    {
        $folded = self::fold($haystack);
        if ($folded === '' || $needle === '') {
            return false;
        }

        return preg_match('/(^| )' . preg_quote($needle, '/') . '( |$)/', $folded) === 1;
    }

    /**
     * Locale-independent comparison of two names for display order.
     *
     * `strcasecmp()` compares bytes, so "Émilie" sorts after "Zoé" and a
     * unit's list of animateurs reads as if the accented names had been
     * appended at the end. Comparing the folded forms puts them where a
     * reader looks for them; ties fall back to the raw strings so two
     * names that differ only by an accent still have a stable order.
     */
    public static function compareNames(string $a, string $b): int
    {
        return (self::fold($a) <=> self::fold($b)) ?: strcmp($a, $b);
    }
}
