<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Phone;

/**
 * The country calling codes ITU-T E.164 assigns, and the one question asked
 * of them: which code does an international number start with.
 *
 * Source: « List of ITU-T Recommendation E.164 assigned country codes »,
 * Annex to ITU Operational Bulletin No. 1114 (15.XII.2016) — every code of
 * its numerical list, spare and reserved ones left out. Taken from that
 * document rather than from memory: a code cut one digit short is exactly
 * the defect this table replaces (« +352 » shown as « +35 2 »).
 *
 * E.164 codes are prefix-free — no assigned code is the start of another —
 * so the code of a number is the one of its first three prefixes that is in
 * the table, and there is never more than one.
 */
final class CountryCallingCodes
{
    /** @var list<string> */
    private const CODES = [
        '1',
        '7',
        '20', '27',
        '30', '31', '32', '33', '34', '36', '39',
        '40', '41', '43', '44', '45', '46', '47', '48', '49',
        '51', '52', '53', '54', '55', '56', '57', '58',
        '60', '61', '62', '63', '64', '65', '66',
        '81', '82', '84', '86',
        '90', '91', '92', '93', '94', '95', '98',
        '211', '212', '213', '216', '218', '220', '221', '222', '223', '224', '225',
        '226', '227', '228', '229', '230', '231', '232', '233', '234', '235', '236',
        '237', '238', '239', '240', '241', '242', '243', '244', '245', '246', '247',
        '248', '249', '250', '251', '252', '253', '254', '255', '256', '257', '258',
        '260', '261', '262', '263', '264', '265', '266', '267', '268', '269', '290',
        '291', '297', '298', '299',
        '350', '351', '352', '353', '354', '355', '356', '357', '358', '359', '370',
        '371', '372', '373', '374', '375', '376', '377', '378', '379', '380', '381',
        '382', '383', '385', '386', '387', '388', '389',
        '420', '421', '423',
        '500', '501', '502', '503', '504', '505', '506', '507', '508', '509', '590',
        '591', '592', '593', '594', '595', '596', '597', '598', '599',
        '670', '672', '673', '674', '675', '676', '677', '678', '679', '680', '681',
        '682', '683', '685', '686', '687', '688', '689', '690', '691', '692',
        '800', '808', '850', '852', '853', '855', '856', '870', '878', '880', '881',
        '882', '883', '886', '888',
        '960', '961', '962', '963', '964', '965', '966', '967', '968', '971', '972',
        '973', '974', '975', '976', '977', '979', '991', '992', '993', '994', '995',
        '996', '998',
    ];

    /**
     * The calling code an international number's digits start with, or null
     * when none of its first three prefixes is assigned.
     */
    public static function of(string $digits): ?string
    {
        static $codes = null;
        $codes ??= array_flip(self::CODES);

        for ($length = 1; $length <= 3 && $length <= strlen($digits); $length++) {
            $prefix = substr($digits, 0, $length);
            if (isset($codes[$prefix])) {
                return $prefix;
            }
        }

        return null;
    }
}
