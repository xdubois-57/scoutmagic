<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

/**
 * What can be read out of an inbound message's text: dates, a price.
 *
 * Deliberately conservative and deliberately NOT a model. Everything
 * here answers "did the sender write this unambiguously", and the answer
 * is null the moment it is not obvious — because what this produces
 * either fills an empty field on its own or argues with a value a chief
 * typed, and both deserve a high bar.
 *
 * No AI: a date in "du 12 au 19 juillet 2028" is a pattern, and a
 * pattern is cheaper, faster, offline and reviewable. The AI in this
 * module is reserved for the two jobs a pattern genuinely cannot do —
 * recognising that two place names are one field (§ IT-06) and writing a
 * summary (§ IT-09).
 */
class MessageReader
{
    private const MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12, 'décembre' => 12,
    ];

    /**
     * A stay's dates, when the message states them as a range.
     *
     * Only ranges: a single date in a message is far more often the date
     * of a meeting, an invoice or a deadline than the day a camp starts.
     *
     * @return array{start: string, end: string}|null
     */
    public function readDateRange(string $text): ?array
    {
        $text = $this->normalise($text);

        // "du 12 au 19 juillet 2028" — one month, one year, stated once.
        if (preg_match(
            '/\bdu\s+(\d{1,2})\s+au\s+(\d{1,2})\s+([a-zéû]+)\s+(\d{4})\b/u',
            $text,
            $m
        ) === 1) {
            $month = self::MONTHS[$m[3]] ?? null;
            if ($month !== null) {
                return $this->range((int) $m[4], $month, (int) $m[1], (int) $m[4], $month, (int) $m[2]);
            }
        }

        // "du 30 avril au 3 mai 2026" — two months, the year stated once.
        if (preg_match(
            '/\bdu\s+(\d{1,2})\s+([a-zéû]+)\s+au\s+(\d{1,2})\s+([a-zéû]+)\s+(\d{4})\b/u',
            $text,
            $m
        ) === 1) {
            $startMonth = self::MONTHS[$m[2]] ?? null;
            $endMonth = self::MONTHS[$m[4]] ?? null;
            if ($startMonth !== null && $endMonth !== null) {
                $year = (int) $m[5];
                // December → January means the range crosses new year.
                $startYear = $startMonth > $endMonth ? $year - 1 : $year;

                return $this->range($startYear, $startMonth, (int) $m[1], $year, $endMonth, (int) $m[3]);
            }
        }

        // "du 12/07/2028 au 19/07/2028"
        if (preg_match(
            '~\bdu\s+(\d{1,2})/(\d{1,2})/(\d{4})\s+au\s+(\d{1,2})/(\d{1,2})/(\d{4})\b~',
            $text,
            $m
        ) === 1) {
            return $this->range((int) $m[3], (int) $m[2], (int) $m[1], (int) $m[6], (int) $m[5], (int) $m[4]);
        }

        return null;
    }

    /**
     * A price in cents, when the message states exactly ONE amount.
     *
     * Two amounts means no reading at all: a quote naming a deposit and a
     * total is precisely the message where guessing wrong is most
     * expensive, and the chief reading it has the document in front of
     * them anyway.
     */
    public function readPriceCents(string $text): ?int
    {
        $text = $this->normalise($text);

        preg_match_all(
            '/(\d{1,3}(?:[  \.]\d{3})*|\d+)(?:[.,](\d{1,2}))?\s*(?:€|eur\b|euros?\b)/u',
            $text,
            $matches,
            PREG_SET_ORDER
        );
        if (count($matches) !== 1) {
            return null;
        }

        $whole = (string) preg_replace('/[  \.]/u', '', $matches[0][1]);
        $decimals = $matches[0][2] ?? '';
        if (!ctype_digit($whole)) {
            return null;
        }

        return (int) $whole * 100 + (int) str_pad($decimals, 2, '0');
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function range(int $y1, int $m1, int $d1, int $y2, int $m2, int $d2): ?array
    {
        if (!checkdate($m1, $d1, $y1) || !checkdate($m2, $d2, $y2)) {
            return null;
        }

        $start = sprintf('%04d-%02d-%02d', $y1, $m1, $d1);
        $end = sprintf('%04d-%02d-%02d', $y2, $m2, $d2);

        // A range running backwards was misread, not written backwards.
        return $end >= $start ? ['start' => $start, 'end' => $end] : null;
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }
}
