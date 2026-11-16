<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

/**
 * The text a federation invoice yields in the layout of its own export
 * (#1327), entirely invented.
 *
 * Its SHAPE is the real one, observed on an actual invoice that is not in
 * this repository and never will be — it lists children, their dates of
 * birth and the unit's bank details:
 *
 * - one row per line, the cells of a tariff row run together, a tab, then
 *   `quantity amountUNITPRICE` with the amount glued to the unit price;
 * - quantities written `9,00`, a deposit deducted as `-1,00`;
 * - NBSP thousands, `1 848,50 €TOTAL A PAYER:` with the amount first;
 * - name cells separated by tabs: `Nom⇥Prénom⇥jj/mm/aaaa Fonction`;
 * - the header row `NUMÉRO⇥jj/mm/aaaajj/mm/aaaaCLIENT`, the due date first;
 * - ten pages, each opening with the tariff row whose list it continues.
 *
 * Its FIGURES are chosen to land on the same total, 1 848,50 €, with the
 * three lines the ticket names: 43,25 × 9,00, -5,00 × 5,00 and
 * 10 017,00 × -1,00. Every name is a generated, meaningless word.
 */
final class FederationExportFixture
{
    public const TOTAL_CENTS = 184850;

    /**
     * One entry per tariff line: the reference, the cell text printed before
     * it (section code glued to the descriptor), the unit price and the
     * quantity as printed, the quantity as a number, and how many names the
     * line lists (null: none).
     *
     * @return list<array{string, string, string, string, int, ?int}>
     */
    public static function rows(): array
    {
        return [
            ['COT_COUPLE', 'SV025B1COTISATION COUPLE', '43,25', '9,00', 9, 9],
            ['COT_FAM', 'SV025B1COTISATION FAMILIALE', '36,75', '4,00', 4, 4],
            ['COT_NORM', 'SV025B1COTISATION NORMALE', '54,25', '18,00', 18, 18],
            ['COT_COUPLE', 'SV025L1COTISATION COUPLE', '43,25', '16,00', 16, 16],
            ['COT_FAM', 'SV025L1COTISATION FAMILIALE', '36,75', '6,00', 6, 6],
            ['COT_NORM', 'SV025L1COTISATION NORMALE', '54,25', '60,00', 60, 60],
            ['COT_NORM', 'SV025E1COTISATION NORMALE', '54,25', '70,00', 70, 70],
            ['COT_NORM', 'SV025P1COTISATION NORMALE', '54,25', '42,00', 42, 42],
            ['COT_ACOMPTE', 'CALCUL ACOMPTE', "10\u{00A0}017,00", '-1,00', -1, null],
            ['COT_COUPLE', "Staff d'unitéCOTISATION COUPLE", '43,25', '1,00', 1, 1],
            ['COT_FAM', "Staff d'unitéCOTISATION FAMILIALE", '36,75', '1,00', 1, 1],
            ['COT_NORM', "Staff d'unitéCOTISATION NORMALE", '54,25', '1,00', 1, 1],
            ['RED_ANIM_BREV', 'REDUCTION ANIMATEUR BREVETE', '-5,00', '5,00', 5, 5],
        ];
    }

    /** The whole extracted text, ten pages, page-top repetitions included. */
    public static function text(): string
    {
        $lines = [
            'Fédération 2025 v.12',
            "N° Document Code Client\tDate facture Echéance",
            // The due date first, as the real text layer gives it.
            "FACT-00042\t12/04/202513/03/2025AB123",
            'FACTURE',
        ];

        $rows = self::rows();
        $peopleLines = 0;
        $page = 1;
        $person = 0;
        foreach ($rows as [$reference, $left, $unitPrice, $quantity, $count, $names]) {
            $row = self::row($reference, $left, $unitPrice, $quantity, $count);
            $lines[] = $row;
            for ($i = 0; $i < ($names ?? 0); $i++) {
                // A page break every 30 names, and the row it falls in is
                // printed again at the top of the next page.
                if ($peopleLines > 0 && $peopleLines % 30 === 0 && $page < 9) {
                    $lines[] = 'Page ' . $page . ' sur 10';
                    $lines[] = '';
                    $lines[] = $row;
                    $page++;
                }
                $lines[] = self::name($person) . "\t" . self::name($person + 7) . "\t"
                    . sprintf('%02d/%02d/%04d', $person % 28 + 1, $person % 12 + 1, 2010 + $person % 8)
                    . ($reference === 'RED_ANIM_BREV' ? " Animateur d'unité" : ' Animé');
                $person++;
                $peopleLines++;
            }
        }
        while ($page < 10) {
            $lines[] = 'Page ' . $page . ' sur 10';
            $lines[] = '';
            $page++;
        }

        return implode("\n", array_merge($lines, [
            "1\u{00A0}848,50 €TOTAL A PAYER:",
            'Compte bancaire:',
            'Conditions: 30 Jours Date Facture',
            '+++123/4567/89012+++',
            'IBAN BE71096123456769',
            "Tx Base TVA\tTTC",
            "0 % 1848.5 0\t1848.5",
            'Page 10 sur 10',
        ]));
    }

    public static function row(string $reference, string $left, string $unitPrice, string $quantity, int $count): string
    {
        $unitCents = (int) str_replace([',', "\u{00A0}"], '', $unitPrice);

        return $left . $reference . "\t" . $quantity . ' ' . self::money($unitCents * $count) . $unitPrice;
    }

    /** Belgian money as the export prints it: NBSP thousands, comma, two decimals. */
    public static function money(int $cents): string
    {
        $negative = $cents < 0;
        $formatted = number_format(abs($cents) / 100, 2, ',', "\u{00A0}");

        return ($negative ? '-' : '') . $formatted;
    }

    /** A meaningless, letters-only word, distinct for every index. */
    public static function name(int $index): string
    {
        $word = '';
        $n = $index + 26;
        while ($n > 0) {
            $word = chr(ord('a') + $n % 26) . $word;
            $n = intdiv($n, 26);
        }

        return 'Nom' . $word;
    }
}
