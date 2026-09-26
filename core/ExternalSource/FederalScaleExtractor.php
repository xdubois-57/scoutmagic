<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * Reads the three household tariffs off the federal fees page,
 * deterministically — no model involved, which is the point: this is the
 * check that tells whether the page still says what the AI-assisted
 * « Chercher les montants » and the shipped scale assume it says.
 *
 * What the page looked like on 24 September 2026, and what the patterns
 * are written for:
 *
 *     COTISATIONS 2026-2027
 *     Cotisation normale : 57,50 €.
 *     Cotisation couple pour deux membres d'un même ménage […] : 46 € par personne.
 *     Cotisation familiale pour trois membres et plus […] : 39 € par personne.
 *
 * Decimals are optional (« 46 € » beside « 57,50 € »). Earlier years
 * printed last year's amount in parentheses after this year's; the first
 * amount after the colon is the one taken, so that shape reads correctly
 * too. The other amounts on the page (solidarité, invités, IAmA) are
 * never matched, because each pattern is anchored on its category word.
 */
final class FederalScaleExtractor
{
    private const AMOUNT = '(\d{1,4}(?:[.,]\d{1,2})?)\s*€';

    /** @var array<string, string> */
    private const CATEGORY_WORDS = [
        'normal' => 'normale',
        'couple' => 'couple',
        'family' => 'familiale',
    ];

    /**
     * The scale, or null when any of the three amounts cannot be found —
     * a page that lost one of them has changed in a way a human must read.
     */
    public static function extract(string $html): ?FederalScale
    {
        $text = self::text($html);
        $cents = [];

        foreach (self::CATEGORY_WORDS as $key => $word) {
            $pattern = '/cotisation\s+' . $word . '\b[^:\n]*:\s*' . self::AMOUNT . '/iu';
            if (preg_match($pattern, $text, $matches) !== 1) {
                return null;
            }
            $cents[$key] = self::toCents($matches[1]);
        }

        $year = null;
        if (preg_match('/cotisations\s+(\d{4})\s*[-–]\s*(\d{4})/iu', $text, $matches) === 1) {
            $year = $matches[1] . '-' . $matches[2];
        }

        return new FederalScale($cents['normal'], $cents['couple'], $cents['family'], $year);
    }

    /**
     * The page as plain text, one block per line, entities decoded and
     * non-breaking spaces flattened — « 57,50&nbsp;€ » is how a CMS
     * writes an amount.
     *
     * The markup goes through libxml's error-recovering parser first, for
     * the reason FederalScaleLookupService::extractText() documents at
     * length: the federal fees page carries an unbalanced quote and an
     * unterminated end tag just before the cotisations block, and
     * `strip_tags()` on the raw bytes silently drops everything after
     * them — amounts and year included. This was seen again here, on the
     * live page, the first time this checker ran.
     */
    public static function text(string $html): string
    {
        $html = self::repairMarkup($html);
        $stripped = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $stripped = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6]|/title)\s*/?>#i', "\n", $stripped) ?? $stripped;
        $text = html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{a0}", "\u{202f}"], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text);
    }

    /**
     * Third-party markup, re-emitted well-formed by libxml; the input
     * untouched when libxml refuses it outright. The XML encoding
     * declaration stops `loadHTML()` from reading a page with no
     * `<meta charset>` as ISO-8859-1.
     */
    private static function repairMarkup(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $repaired = $document->saveHTML();

        return is_string($repaired) && trim($repaired) !== '' ? $repaired : $html;
    }

    private static function toCents(string $amount): int
    {
        $parts = explode('.', str_replace(',', '.', $amount));
        $euros = (int) $parts[0];
        $decimals = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';

        return $euros * 100 + (int) $decimals;
    }
}
