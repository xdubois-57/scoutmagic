<?php

declare(strict_types=1);

namespace Core\Service;

/**
 * Display-only normalization of user-facing text (names, totems, phones,
 * addresses). Never mutates stored data — apply at render time only, typically
 * through the Twig filters registered by TextNormalizerExtension.
 */
class TextNormalizerService
{
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
     */
    public static function normalizeAddress(string $raw): string
    {
        $words = self::splitWords($raw);
        if ($words === []) {
            return '';
        }

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
