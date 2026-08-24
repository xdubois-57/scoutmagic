<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

/**
 * Case- and accent-insensitive folding for the two places this module
 * compares free text it did not write: a Desk function label (does it say
 * "candidat"?) and a Desk formation level (which step is that?).
 *
 * Both inputs are federation wording, typed by hand somewhere upstream, and
 * arrive with whatever casing, accents and spacing that entailed. Folding
 * both sides of every comparison is what stops "Candidat·e Animateur" and
 * "CANDIDAT ANIMATEUR" from being two different things.
 *
 * The explicit character map is applied unconditionally rather than relying
 * on ext-intl's Normalizer, which this project does not require in
 * composer.json: a matcher whose accent handling depends on which
 * extensions a shared host happens to enable would behave differently
 * between two installations, and the tests would only pin whichever one
 * they ran on. Normalizer, when present, runs afterwards for characters
 * outside the map. Modules\Finance\Service\CategoryRuleEngine::normalize()
 * is the same idea with the opposite trade-off (intl-first, graceful
 * degradation) — kept separate rather than shared because that one is a
 * private detail of a rule engine this module has no business coupling to.
 */
final class TextMatcher
{
    /**
     * Latin-1/Latin Extended-A characters a French or Dutch Desk label can
     * realistically carry, folded to ASCII.
     */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ñ' => 'n', 'ń' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
    ];

    /**
     * Lowercase, accent-folded, whitespace-collapsed. The one function both
     * sides of every comparison in this module go through.
     */
    public static function fold(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::FOLD);

        // Anything the map did not cover (a Slavic or Turkish diacritic in
        // a name-derived label, say) folds here when intl is available.
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $value = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
            }
        }

        // A middle dot is inclusive-writing punctuation ("candidat·e"), not
        // a word boundary anybody means; collapsing every run of
        // non-alphanumerics to one space makes it, the hyphen and the
        // non-breaking space all read the same.
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
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
