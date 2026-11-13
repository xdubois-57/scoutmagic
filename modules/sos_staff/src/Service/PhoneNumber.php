<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

/**
 * The one reading of a phone number this module has (issue #1125): E.164,
 * « + », country code, national number, nothing else.
 *
 * A GSM arrives here as somebody typed it into Desk — « 0470 12 34 56 »,
 * « 0470/12.34.56 », « +32 470 12 34 56 », « 0032470123456 », « +32 (0)470
 * 12 34 56 » — and the operator hands back whatever format it stores. The
 * redirect is sent normalised, and every « is the line where it should
 * be » compares normalised numbers on both sides: two spellings of one
 * number are one number, or a redirect that worked is reported as failed
 * and « Resynchroniser » never goes away.
 *
 * A national number (one leading 0) is Belgian: this is a Belgian unit's
 * Desk export. Anything that does not reduce to a plausible number is
 * refused (null) rather than guessed at — a guess is a call ringing at a
 * stranger's.
 */
final class PhoneNumber
{
    private const DEFAULT_COUNTRY_CODE = '32';

    /**
     * Belgian significant numbers (after +32): a mobile starts with 4 and
     * has 9 digits, every other number has 8. Telling the two apart is what
     * catches a mobile typed one digit short, which would otherwise pass
     * for a land line.
     */
    private const BELGIAN_MOBILE_LENGTH = 9;
    private const BELGIAN_OTHER_LENGTH = 8;

    private function __construct()
    {
    }

    /**
     * The number in E.164 (« +32470123456 »), or null when it cannot be one.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // « +32 (0)470… »: the bracketed trunk prefix is dialled only from
        // inside the country, and is never part of the international form.
        $compact = str_replace('(0)', '', $raw);
        $compact = (string) preg_replace('/[\s\/.\-()]+/u', '', $compact);
        if ($compact === '') {
            return null;
        }

        if (str_starts_with($compact, '+')) {
            $digits = substr($compact, 1);
        } elseif (str_starts_with($compact, '00')) {
            $digits = substr($compact, 2);
        } elseif (str_starts_with($compact, '0')) {
            $digits = self::DEFAULT_COUNTRY_CODE . substr($compact, 1);
        } else {
            return null;
        }

        // E.164: digits only, at most 15, and never a country code that
        // starts with 0.
        if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        if (str_starts_with($digits, self::DEFAULT_COUNTRY_CODE)) {
            $significant = substr($digits, strlen(self::DEFAULT_COUNTRY_CODE));
            $expectedLength = str_starts_with($significant, '4')
                ? self::BELGIAN_MOBILE_LENGTH
                : self::BELGIAN_OTHER_LENGTH;
            if (str_starts_with($significant, '0') || strlen($significant) !== $expectedLength) {
                return null;
            }
        }

        return '+' . $digits;
    }

    /**
     * Whether two spellings are the same number. A number that cannot be
     * normalised is never the same as anything: « the line is where it
     * should be » must not be concluded from two unreadable strings.
     */
    public static function same(?string $a, ?string $b): bool
    {
        $normalizedA = self::normalize($a);

        return $normalizedA !== null && $normalizedA === self::normalize($b);
    }
}
