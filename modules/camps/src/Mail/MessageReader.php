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
 * module is reserved for the three jobs a pattern genuinely cannot do —
 * recognising that two place names are one field (§ IT-06), writing a
 * summary (§ IT-09), and reading a venue's name out of a message body
 * (Mail\StayFromMailService).
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

        // "Arrivée: 18-09-26 16:30  Départ: 20-09-26 16:00" — the shape a
        // camp site's own contract uses, and the reason this reader saw
        // nothing in the documents that matter most. It is as explicit as
        // « du … au … »: the two ends are LABELLED, so reading them is not
        // a guess, and the labels are what allows a two-digit year here
        // where the free-text forms above still demand four.
        if (preg_match(
            '~\barriv[ée]e?' . self::LABEL_GAP . self::NUMERIC_DATE
            . '.{0,80}?\bd[ée]part' . self::LABEL_GAP . self::NUMERIC_DATE . '~su',
            $text,
            $m
        ) === 1) {
            return $this->range(
                self::year($m[3]),
                (int) $m[2],
                (int) $m[1],
                self::year($m[6]),
                (int) $m[5],
                (int) $m[4]
            );
        }

        return null;
    }

    /**
     * A day-first numeric date: 18-09-26, 18/09/2026, 18.09.2026.
     *
     * Day-first and never month-first. Every date a Belgian camp site
     * prints is day-first, and a reader that hedged would turn 03-04-26
     * into two plausible stays three weeks apart with no way to tell which
     * — exactly the ambiguity this class answers with silence everywhere
     * else.
     */
    /**
     * What may sit between the label and its date: a colon, « le », or
     * nothing at all. Deliberately short — a label three words away from a
     * date is not a label for it.
     */
    private const LABEL_GAP = '\s*(?::|\ble\b)?\s*';

    private const NUMERIC_DATE = '(\d{1,2})[-/.](\d{1,2})[-/.](\d{4}|\d{2})(?!\d)';
    // Four digits BEFORE two, and no digit allowed after: the other way
    // round, « 18/09/2026 » matched a year of « 20 » and read 2020.

    /**
     * A two-digit year is this century. 26 is 2026: a contract for a camp
     * in 1926 is not a case worth being wrong about in the other
     * direction.
     */
    private static function year(string $raw): int
    {
        return mb_strlen($raw) === 2 ? 2000 + (int) $raw : (int) $raw;
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
