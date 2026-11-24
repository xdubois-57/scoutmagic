<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

/**
 * Whether a document's text reads like an expense receipt — a till
 * ticket, an invoice, a « reçu » — decided on the site, with no model
 * (#1342, decision 2).
 *
 * **Two things, both required.** A word a receipt prints (« Total »,
 * « TVA », « Facture », « Ticket »…) AND an amount stated as money: two
 * decimals next to a currency, or on the same line as a total. Either one
 * alone is everywhere: a newsletter says « total » and a camp programme
 * says « 12,50 ». Together they are what a document asking to be paid, or
 * proving that it was, looks like.
 *
 * French, Dutch and English, because that is what a Belgian unit's
 * suppliers print. The text is folded (lower case, no accents) before it
 * is compared, so « REÇU » and « recu » are one word — and only that:
 * `TextNormalizerService::fold()` also drops punctuation and line breaks,
 * which are what an amount and a total's line are made of.
 *
 * **It decides, it does not extract.** Reading the amount, the date and
 * the merchant is `Service\ReceiptExtractionService`'s job once the
 * receipt exists, exactly as for one uploaded by hand; a second reading of
 * those fields here would be the « deux analyses équivalentes » the issue
 * asks to avoid.
 */
final class ReceiptTextRecognizer
{
    /**
     * What a receipt prints, folded. Whole words only: « total » must not
     * be found in « totalement ».
     */
    private const MARKERS = [
        // French
        'total', 'tva', 'htva', 'ttc', 'facture', 'ticket', 'recu', 'quittance',
        'montant', 'a payer', 'note de frais',
        // Dutch
        'totaal', 'btw', 'factuur', 'kassaticket', 'kasticket', 'kassabon', 'bedrag', 'te betalen',
        // English
        'invoice', 'receipt', 'vat', 'amount due',
    ];

    /** The words a total is printed next to, on the same line as its amount. */
    private const TOTAL_WORDS = ['total', 'totaal', 'montant', 'a payer', 'te betalen', 'bedrag', 'amount due'];

    /**
     * A sum with two decimals: « 12,50 », « 1 234.00 », « 1.234,00 ». Not
     * the start of a date: « 12.09.2026 » is followed by a separator and a
     * digit, which a sum never is.
     */
    private const AMOUNT = '\d{1,3}(?:[ .\x{00A0}\x{202F}]?\d{3})*[.,]\d{2}(?!\d|[.\/-]\d)';

    public function looksLikeAReceipt(string $text): bool
    {
        $folded = self::fold($text);
        if ($folded === '' || !self::hasMarker($folded)) {
            return false;
        }

        return self::hasMoneyAmount($folded) || self::hasTotalLine($folded);
    }

    private static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
            }
        }

        return trim($text);
    }

    private static function hasMarker(string $folded): bool
    {
        foreach (self::MARKERS as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    /** « 12,50 € », « € 12,50 », « 12,50 EUR », « 12,50.- EUR », « EUR 12.50 ». */
    private static function hasMoneyAmount(string $folded): bool
    {
        $currency = '(?:€|eur\b|euros?\b)';

        // « 12,50.- EUR »: the dash a Belgian price is often written with.
        return preg_match('/' . self::AMOUNT . '(?:\.-)?\s?' . $currency . '/u', $folded) === 1
            || preg_match('/' . $currency . '\s?' . self::AMOUNT . '/u', $folded) === 1;
    }

    /**
     * « TOTAL 12,50 » with no currency — what a till prints more often than
     * not — counts only on the total's own line.
     */
    private static function hasTotalLine(string $folded): bool
    {
        foreach (preg_split('/\R/u', $folded) ?: [] as $line) {
            if (preg_match('/' . self::AMOUNT . '/u', $line) !== 1) {
                continue;
            }
            foreach (self::TOTAL_WORDS as $word) {
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $line) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
