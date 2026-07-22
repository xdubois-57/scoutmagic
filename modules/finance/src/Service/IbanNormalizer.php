<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * Shared IBAN normalization/validation for both an account's own IBAN
 * (Service\FinanceService::createAccount()/updateAccount() — always
 * expected to be a complete, real IBAN) and a rule's counterparty
 * account condition (Controller\ConfigRuleController — the config
 * page's own help text explicitly allows a partial fragment, e.g. just
 * a bank's sort-code prefix, not only a full IBAN). normalize() is
 * always safe to apply (strips spaces, uppercases); isValidFullIban()
 * is a real ISO 13616 mod-97 checksum check and is only meaningful —
 * and only ever called — against a value that is actually meant to be
 * a complete IBAN, never a deliberate fragment.
 */
class IbanNormalizer
{
    /**
     * Official IBAN lengths by country (ISO 13616 / SWIFT registry) —
     * covers every SEPA country plus the other countries commonly seen
     * on international scout federation payments. Used to require an
     * exact length for a recognized country prefix; an unrecognized
     * prefix still goes through the mod-97 checksum, just without an
     * extra length check.
     *
     * @var array<string, int>
     */
    private const IBAN_LENGTHS_BY_COUNTRY = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16,
        'BG' => 22, 'BH' => 22, 'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24,
        'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18,
        'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23,
        'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32,
        'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'LY' => 25, 'MC' => 27, 'MD' => 24,
        'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15,
        'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22,
        'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'ST' => 25,
        'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24,
        'XK' => 20,
    ];

    /**
     * Uppercases and strips everything but letters/digits — safe to
     * apply to any value, including a deliberate partial fragment.
     */
    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');
    }

    /**
     * True length-and-checksum ISO 13616 validation — $iban must already
     * be normalize()d. Only call this against a value meant to be a
     * complete IBAN.
     */
    public static function isValidFullIban(string $iban): bool
    {
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{6,30}$/', $iban) !== 1) {
            return false;
        }

        $countryCode = substr($iban, 0, 2);
        $expectedLength = self::IBAN_LENGTHS_BY_COUNTRY[$countryCode] ?? null;
        if ($expectedLength !== null && strlen($iban) !== $expectedLength) {
            return false;
        }

        return self::mod97($iban) === 1;
    }

    /**
     * Whether $iban (already normalize()d) is long enough to plausibly
     * be a complete IBAN rather than a deliberate fragment — the
     * shortest real IBAN in circulation (Norway) is 15 characters.
     * Controller\ConfigRuleController uses this to decide whether a
     * rule's counterparty account condition should be checksum-validated
     * at all.
     */
    public static function looksLikeFullIban(string $iban): bool
    {
        return strlen($iban) >= 15;
    }

    /**
     * ISO 13616 mod-97 check: move the first 4 characters to the end,
     * convert each letter to two digits (A=10 … Z=35), then compute the
     * resulting decimal number modulo 97 — a valid IBAN always yields 1.
     * Done piecewise (never building the full, potentially 30+ digit
     * number as a single int) since PHP integers can't safely hold it.
     */
    private static function mod97(string $iban): int
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - ord('A') + 10) : $char;
        }

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }
}
