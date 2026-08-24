<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Service;

/**
 * Normalization of user-facing text (names, totems, phones, addresses).
 *
 * The `normalize*` half is display-only: it never mutates stored data —
 * apply at render time only, typically through the Twig filters registered
 * by TextNormalizerExtension.
 *
 * `fold()` is the other half and the opposite intent: a form nobody ever
 * reads, produced so that two spellings of the same thing compare equal.
 */
class TextNormalizerService
{
    /**
     * Latin-1/Latin Extended-A characters a French, Dutch or German label
     * can realistically carry, folded to ASCII.
     *
     * @var array<string, string>
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
     * Lowercase, accent-folded, punctuation-collapsed. The one function
     * both sides of a case- and accent-insensitive comparison go through:
     * a member search, a duplicate-place detector, a Desk label this site
     * did not write.
     *
     * **The explicit map is applied unconditionally**, rather than relying
     * on ext-intl's `Normalizer`, which this project does not require in
     * composer.json: a comparison whose accent handling depends on which
     * extensions a shared host happens to enable would behave differently
     * between two installations, and the tests would only pin whichever one
     * they ran on. `Normalizer`, when present, runs afterwards for
     * characters outside the map. And **never `iconv('ASCII//TRANSLIT')`**,
     * whose output depends on the C library and the locale — the same
     * "é" comes back as `e` on glibc and as `'e` on musl.
     *
     * **Every run of non-alphanumerics collapses to one space**, which is
     * what makes a middle dot ("candidat·e" — inclusive writing, not a word
     * boundary anybody means), a hyphen, a non-breaking space and a
     * parenthesised "(asbl)" all read the same. It also means a word here
     * is a run bounded by spaces, so a needle can be matched as a whole
     * word rather than as a substring.
     *
     * `Modules\Finance\Service\CategoryRuleEngine::normalize()` is the same
     * idea with the opposite trade-off (intl first, graceful degradation);
     * it stays where it is, a private detail of a rule engine nothing here
     * has any business coupling to.
     */
    public static function fold(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, self::FOLD);

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $value = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
            }
        }

        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Name particles that stay lowercase when they are not the first word.
     *
     * @var array<int, string>
     */
    private const PARTICLES = [
        'de', 'du', 'des', 'le', 'la', 'les',
        'van', 'den', 'der', 'von', 'di', 'el',
    ];

    /**
     * Title-case a person name with French/Belgian particle handling.
     * "VAN DEN BERG" → "Van den Berg", "DE SMET" → "De Smet",
     * "JEAN-PHILIPPE" → "Jean-Philippe", "D'HONDT" → "D'Hondt".
     */
    public static function normalizeName(string $raw): string
    {
        $words = self::splitWords($raw);
        if ($words === []) {
            return '';
        }

        $out = [];
        foreach ($words as $i => $word) {
            $lower = mb_strtolower($word, 'UTF-8');
            if ($i > 0 && in_array($lower, self::PARTICLES, true)) {
                $out[] = $lower;
            } else {
                $out[] = self::titleCaseWord($word);
            }
        }

        return implode(' ', $out);
    }

    /**
     * First letter uppercase, the rest lowercase.
     * "RENARD ESPIÈGLE" → "Renard espiègle".
     */
    public static function normalizeTotem(string $raw): string
    {
        $clean = self::collapse($raw);
        if ($clean === '') {
            return '';
        }

        return self::ucfirst(mb_strtolower($clean, 'UTF-8'));
    }

    /**
     * Normalize and pretty-print a phone number (Belgian formatting by default).
     * Returns '' for empty input.
     */
    public static function normalizePhone(string $raw, string $defaultCountry = 'BE'): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }

        if ($hasPlus) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '32')) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $e164 = '32' . substr($digits, 1);
        } else {
            // No recognizable prefix: assume the default country (BE = 32).
            $e164 = ($defaultCountry === 'BE' ? '32' : '') . $digits;
        }

        if (str_starts_with($e164, '32')) {
            return self::formatBelgian(substr($e164, 2));
        }

        // Other countries: "+CC " then group the rest.
        $cc = substr($e164, 0, 2);
        $rest = substr($e164, 2);

        return '+' . $cc . ' ' . self::groupDigits($rest);
    }

    /**
     * Title-case a street/city address, keeping numeric tokens (postal code,
     * house number) untouched and particles lowercase.
     * "RUE DE LA STATION" → "Rue de la Station".
     *
     * A house number typed at the FRONT of the street field ("12 Rue de la
     * Station" instead of a separate number field) is moved to just after
     * the street name before any other formatting — matching the "street,
     * then number" order the rest of the app composes addresses in
     * (Core\Member\MemberAddress::format()), regardless of which raw
     * order the underlying data came in with.
     */
    public static function normalizeAddress(string $raw): string
    {
        $words = self::splitWords($raw);
        if ($words === []) {
            return '';
        }

        $words = self::moveLeadingNumberAfterStreet($words);

        $out = [];
        foreach ($words as $i => $word) {
            if (preg_match('/\d/', $word) === 1) {
                // Postal code, house/box number: keep as-is.
                $out[] = $word;
                continue;
            }
            $lower = mb_strtolower($word, 'UTF-8');
            if ($i > 0 && in_array($lower, self::PARTICLES, true)) {
                $out[] = $lower;
            } else {
                $out[] = self::titleCaseWord($word);
            }
        }

        return implode(' ', $out);
    }

    /**
     * If the first word starts with a digit, relocate it to just after the
     * street name: right after the word carrying MemberAddress::format()'s
     * own comma (the boundary between the street/number/box segment and
     * the postal code/city segment), or right before the next numeric
     * token (a bare "12 Rue de la Station 1000 Ville" with no comma), or
     * at the very end when neither is found (a street with no postal
     * code/city attached at all). Words are otherwise untouched — casing
     * happens in the caller's own loop afterwards.
     *
     * @param array<int, string> $words
     * @return array<int, string>
     */
    private static function moveLeadingNumberAfterStreet(array $words): array
    {
        if (count($words) < 2 || preg_match('/^\d/', $words[0]) !== 1) {
            return $words;
        }

        $leadingNumber = array_shift($words);
        $insertAt = count($words);

        foreach ($words as $i => $word) {
            if (str_ends_with($word, ',')) {
                $words[$i] = rtrim($word, ',');
                $leadingNumber .= ',';
                $insertAt = $i + 1;
                break;
            }
            if (preg_match('/\d/', $word) === 1) {
                $insertAt = $i;
                break;
            }
        }

        array_splice($words, $insertAt, 0, [$leadingNumber]);

        return $words;
    }

    /**
     * @return array<int, string>
     */
    private static function splitWords(string $raw): array
    {
        $clean = self::collapse($raw);
        if ($clean === '') {
            return [];
        }

        return explode(' ', $clean);
    }

    private static function collapse(string $raw): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
    }

    /**
     * Title-case a single word, handling hyphens and apostrophes.
     */
    private static function titleCaseWord(string $word): string
    {
        $parts = array_map(
            static fn(string $part): string => self::titleCaseSegment($part),
            explode('-', $word)
        );

        return implode('-', $parts);
    }

    private static function titleCaseSegment(string $segment): string
    {
        $subs = array_map(
            static fn(string $sub): string => self::ucfirst(mb_strtolower($sub, 'UTF-8')),
            explode("'", $segment)
        );

        return implode("'", $subs);
    }

    private static function ucfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($value, 1, null, 'UTF-8');
    }

    private static function formatBelgian(string $nat): string
    {
        $len = strlen($nat);

        // Mobile: 4XX XX XX XX
        if ($len === 9 && $nat[0] === '4') {
            return sprintf('+32 %s %s %s %s', substr($nat, 0, 3), substr($nat, 3, 2), substr($nat, 5, 2), substr($nat, 7, 2));
        }

        if ($len === 8) {
            // Brussels: 1-digit zone code (2). Others: 2-digit zone code.
            if ($nat[0] === '2') {
                return sprintf('+32 %s %s %s %s', substr($nat, 0, 1), substr($nat, 1, 3), substr($nat, 4, 2), substr($nat, 6, 2));
            }
            return sprintf('+32 %s %s %s %s', substr($nat, 0, 2), substr($nat, 2, 2), substr($nat, 4, 2), substr($nat, 6, 2));
        }

        // Doesn't match a known Belgian pattern: group generically.
        return '+32 ' . self::groupDigits($nat);
    }

    /**
     * Group a digit string in chunks of two (a leading chunk of three when the
     * length is odd), separated by spaces.
     */
    private static function groupDigits(string $digits): string
    {
        if ($digits === '') {
            return '';
        }

        $groups = [];
        $i = 0;
        if (strlen($digits) % 2 === 1) {
            $groups[] = substr($digits, 0, 3);
            $i = 3;
        }
        for (; $i < strlen($digits); $i += 2) {
            $groups[] = substr($digits, $i, 2);
        }

        return implode(' ', $groups);
    }
}
