<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Phone;

/**
 * A phone number as somebody typed it, read once: the international form
 * every link is built from, the text a page shows, and whether it can
 * receive a message at all.
 *
 * This is the only place a stored number is interpreted. The display
 * filter (`|normalize_phone`), the exports, the contact card and the
 * clickable number all go through {@see parse()}, so a number cannot be
 * read one way on a page and another way in the link under it.
 *
 * Nothing here touches the database: a number is stored as it was typed
 * and read again on every display.
 *
 * Belgium is the default country, as everywhere on the site: a number
 * written nationally (« 0472 … ») is Belgian. A leading « 00 » is the
 * international prefix as it is dialled from Belgium, the same as « + ».
 */
final class PhoneNumber
{
    private const BELGIUM = '32';

    /**
     * Belgian zones whose code is a single digit: Brussels, Antwerp, Liège
     * and Ghent. Every other landline zone has two.
     */
    private const BELGIAN_ONE_DIGIT_ZONES = ['2', '3', '4', '9'];

    private function __construct(
        /** The number in E.164 form with its « + »: « +32478123456 ». */
        public readonly string $international,
        /** The text a page shows: « +32 478 12 34 56 ». */
        public readonly string $display,
        /**
         * Whether a text message makes sense: false for a Belgian landline
         * only. A foreign number is offered everything, since nothing here
         * can tell its landlines from its mobiles.
         */
        public readonly bool $canReceiveMessages,
    ) {
    }

    /**
     * Null when the text holds no digit at all — nothing to show, nothing
     * to call.
     */
    public static function parse(string $raw): ?self
    {
        // « +32 (0)472 … »: the national trunk zero written beside the
        // country code, to be dialled from inside the country only. It is
        // dropped where it is written that way and nowhere else — an
        // Italian number keeps its leading zero after « +39 ».
        $raw = trim((string) preg_replace('/\(\s*0\s*\)/', '', $raw));
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            $international = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $international = substr($digits, 2);
        } elseif (str_starts_with($digits, self::BELGIUM)) {
            $international = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $international = self::BELGIUM . substr($digits, 1);
        } else {
            $international = self::BELGIUM . $digits;
        }

        if ($international === '') {
            return null;
        }

        if (str_starts_with($international, self::BELGIUM)) {
            $national = substr($international, strlen(self::BELGIUM));
            // No Belgian number starts with 0 after its country code:
            // « 0032 0472 … » is the trunk zero kept by habit.
            if (str_starts_with($national, '0')) {
                $national = ltrim($national, '0');
                $international = self::BELGIUM . $national;
            }

            return new self(
                '+' . $international,
                self::belgian($national),
                strlen($national) === 9 && preg_match('/^4[5-9]/', $national) === 1,
            );
        }

        return new self('+' . $international, self::foreign($international), true);
    }

    /**
     * « +32 478 12 34 56 », « +32 2 123 45 67 », « +32 81 22 33 44 ».
     */
    private static function belgian(string $national): string
    {
        $length = strlen($national);
        if ($length === 0) {
            return '+32';
        }

        if ($length === 9 && $national[0] === '4') {
            return '+32 ' . implode(' ', [
                substr($national, 0, 3),
                substr($national, 3, 2),
                substr($national, 5, 2),
                substr($national, 7, 2),
            ]);
        }

        if ($length === 8) {
            $zone = in_array($national[0], self::BELGIAN_ONE_DIGIT_ZONES, true) ? 1 : 2;

            return '+32 ' . substr($national, 0, $zone) . ' '
                . ($zone === 1 ? substr($national, 1, 3) : substr($national, 2, 2)) . ' '
                . substr($national, 4, 2) . ' '
                . substr($national, 6, 2);
        }

        return '+32 ' . self::pairs($national);
    }

    /**
     * The calling code, set apart from the rest. How the rest is grouped is
     * only known for the neighbours a Belgian unit actually calls; anywhere
     * else it is grouped in pairs, and a code the ITU list does not hold is
     * not separated at all rather than cut in the wrong place.
     */
    private static function foreign(string $international): string
    {
        $code = CountryCallingCodes::of($international);
        if ($code === null || strlen($international) === strlen($code)) {
            return '+' . $international;
        }

        $rest = substr($international, strlen($code));

        $grouped = match ($code) {
            // France: « 6 12 34 56 78 ».
            '33' => strlen($rest) === 9
                ? $rest[0] . ' ' . implode(' ', str_split(substr($rest, 1), 2))
                : self::pairs($rest),
            // Luxembourg: « 691 123 456 ».
            '352' => implode(' ', str_split($rest, 3)),
            default => self::pairs($rest),
        };

        return '+' . $code . ' ' . $grouped;
    }

    /**
     * Pairs of digits, with a leading group of three when the count is odd.
     */
    private static function pairs(string $digits): string
    {
        if ($digits === '') {
            return '';
        }

        $head = strlen($digits) % 2 === 1 ? substr($digits, 0, 3) : '';
        $tail = substr($digits, strlen($head));
        $groups = $tail === '' ? [] : str_split($tail, 2);
        if ($head !== '') {
            array_unshift($groups, $head);
        }

        return implode(' ', $groups);
    }
}
