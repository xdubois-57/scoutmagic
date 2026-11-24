<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

use Core\Service\DateInput;
use Core\Service\TextNormalizerService;

/**
 * Reads the five tariffs and paid guest window off the federal fees page,
 * deterministically — no model involved, which is the point: this is the
 * check that tells whether the page still says what the AI-assisted
 * « Chercher les montants » and the shipped scale assume it says.
 *
 * What the page looked like on 24 November 2026, and what the patterns
 * are written for:
 *
 *     COTISATIONS 2026-2027
 *     Cotisation normale : 57,50 €.
 *     Cotisation couple pour deux membres d'un même ménage […] : 46 € par personne.
 *     Cotisation familiale pour trois membres et plus […] : 39 € par personne.
 *     Cotisation invités et demi-année […] : 18,25 €.
 *     IAmA liés à ton unité […] devront payer une cotisation de 25€.
 *     Entre le 1er juin et le 31 aout […] coute une cotisation invité.
 *
 * Decimals are optional (« 46 € » beside « 57,50 € »). Earlier years
 * printed last year's amount in parentheses after this year's; the first
 * amount after the colon is the one taken, so that shape reads correctly
 * too. Local IAmA and the paid guest clause are selected explicitly:
 * federal-only IAmA and the neighbouring free window are different facts.
 */
final class FederalScaleExtractor
{
    private const AMOUNT = '(\d{1,4}(?:[.,]\d{1,2})?)\s*€';

    /** @var array<string, string> */
    private const CATEGORY_WORDS = [
        'normal' => 'normale',
        'couple' => 'couple',
        'family' => 'familiale',
        'guest' => 'invit[eé]s?',
    ];

    private const MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    /**
     * A complete scale, or null: the deterministic watch must never call a
     * partial reading conforming. The AI proposal may be partial instead.
     */
    public static function extract(string $html): ?FederalScale
    {
        [$year, $text] = self::latestSeason(self::text($html));
        $cents = [];

        foreach (self::CATEGORY_WORDS as $key => $word) {
            $pattern = '/cotisation\s+' . $word . '\b[^:\n]*:\s*' . self::AMOUNT . '/iu';
            if (preg_match($pattern, $text, $matches) !== 1) {
                return null;
            }
            $cents[$key] = self::toCents($matches[1]);
        }

        $localIama = '/\biama\s+li[eé](?:s|es)?\s+[aà]\s+(?:ton|ta|une?|l[\'’])\s*(?:unit[eé]|clan)\b'
            . '[^\n]*?\b(?:devront|doivent|doit|devra)\s+payer\s+une?\s+cotisation(?:\s+de)?\s*'
            . self::AMOUNT . '/iu';
        if (preg_match($localIama, $text, $iama) !== 1) {
            return null;
        }
        $period = self::paidGuestPeriod($text, $year);
        if ($period === null) {
            return null;
        }

        return new FederalScale(
            $cents['normal'],
            $cents['couple'],
            $cents['family'],
            self::toCents($iama[1]),
            $cents['guest'],
            $period[0],
            $period[1],
            $year
        );
    }

    /** The requested year's complete text, including its IAmA/guest subheadings. */
    public static function sectionForYear(string $html, string $year): ?string
    {
        return self::seasonSection(self::text($html), $year);
    }

    /**
     * The most recent season's section, and its year. Around a rollover
     * the page can carry two « Cotisations YYYY-YYYY » headings at once,
     * in either order; reading the first one would report last season's
     * amounts and year, and a new season with the same amounts would never
     * be noticed. Without any heading, the whole text and no year.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function latestSeason(string $text): array
    {
        $matches = self::seasonHeadings($text);
        $years = [];
        foreach ($matches as $match) {
            if ((int) $match[2][0] === (int) $match[1][0] + 1) {
                $years[] = $match[1][0] . '-' . $match[2][0];
            }
        }
        if ($years === []) {
            return [null, $text];
        }

        $year = max($years);

        return [$year, self::seasonSection($text, $year) ?? ''];
    }

    /** @return list<array<int, array{string, int}>> */
    private static function seasonHeadings(string $text): array
    {
        preg_match_all(
            '/^cotisations[ \t]+(\d{4})[ \t]*[-–—\/][ \t]*(\d{4})[ \t]*$/imu',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        return $matches;
    }

    private static function seasonSection(string $text, string $year): ?string
    {
        if (
            preg_match('/^(\d{4})-(\d{4})$/', $year, $years) !== 1
            || (int) $years[2] !== (int) $years[1] + 1
        ) {
            return null;
        }

        $start = null;
        $end = strlen($text);
        foreach (self::seasonHeadings($text) as $match) {
            $matchesYear = $match[1][0] . '-' . $match[2][0] === $year;
            if ($start === null && $matchesYear) {
                $start = $match[0][1];
            } elseif ($start !== null && !$matchesYear) {
                $end = $match[0][1];
                break;
            }
        }

        return $start === null ? null : trim(substr($text, $start, $end - $start));
    }

    /** @return array{string, string}|null */
    private static function paidGuestPeriod(string $text, ?string $year): ?array
    {
        $months = 'janvier|f[eé]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[eé]cembre';
        $date = '(\d{1,2})(?:er)?\s+(' . $months . ')';
        $pattern = '/\bentre\s+le\s+' . $date . '\s+et\s+le\s+' . $date
            . '[^\n]*?\bco[uû]te\s+une?\s+cotisation\s+invit[eé]\b/iu';
        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        $calendarYear = $year === null ? '2000' : substr($year, 5, 4);
        $from = sprintf('%02d/%02d', (int) $match[1], self::MONTHS[TextNormalizerService::fold($match[2])]);
        $until = sprintf('%02d/%02d', (int) $match[3], self::MONTHS[TextNormalizerService::fold($match[4])]);
        $start = DateInput::parse('!d/m/Y', $from . '/' . $calendarYear);
        $end = DateInput::parse('!d/m/Y', $until . '/' . $calendarYear);
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        return [$from, $until];
    }

    /**
     * The page as plain text, one block per line, entities decoded and
     * non-breaking spaces flattened — « 57,50&nbsp;€ » is how a CMS
     * writes an amount.
     *
     * The markup goes through PHP's bundled HTML5 parser first, for
     * the reason FederalScaleLookupService::extractText() documents at
     * length: the federal fees page carries an unbalanced quote and an
     * unterminated end tag just before the cotisations block, and
     * `strip_tags()` on the raw bytes silently drops everything after
     * them — amounts and year included. This was seen again here, on the
     * live page, the first time this checker ran.
     */
    public static function text(string $html): string
    {
        $text = \Core\Template\HtmlTextExtractor::extract($html);
        $text = str_replace(["\u{a0}", "\u{202f}"], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text);
    }

    private static function toCents(string $amount): int
    {
        $parts = explode('.', str_replace(',', '.', $amount));
        $euros = (int) $parts[0];
        $decimals = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';

        return $euros * 100 + (int) $decimals;
    }
}
