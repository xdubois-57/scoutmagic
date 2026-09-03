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
    public function readDateRange(string $text, ?\DateTimeImmutable $reference = null): ?array
    {
        $text = $this->normalise($text);

        // « du 12 au 19 juillet 2028 » — one month, one year, stated once.
        // « 1er » and a weekday before the day are read the same: « du
        // vendredi 1er au dimanche 3 mai 2026 » is how a contract is
        // written, and it used to read as nothing.
        if (preg_match(
            '/\bdu\s+' . self::DAY . '\s+au\s+' . self::DAY . '\s+([a-zéû]+)\s+(\d{4})\b/u',
            $text,
            $m
        ) === 1) {
            $month = self::MONTHS[$m[3]] ?? null;
            if ($month !== null) {
                return $this->range((int) $m[4], $month, (int) $m[1], (int) $m[4], $month, (int) $m[2]);
            }
        }

        // « du 30 avril au 3 mai 2026 » — two months, the year stated once.
        if (preg_match(
            '/\bdu\s+' . self::DAY . '\s+([a-zéû]+)\s+au\s+' . self::DAY . '\s+([a-zéû]+)\s+(\d{4})\b/u',
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

        // « du 12 au 19 juillet » with no year at all, the way a farmer
        // writes back about the summer. The year is the message's own:
        // the next occurrence of that range from the day it was sent, so
        // a January message about July means this July and a December
        // one means the next. Only when a reference date is given —
        // without one, a range with no year is still not read.
        if ($reference !== null) {
            $yearless = $this->readYearlessRange($text, $reference);
            if ($yearless !== null) {
                return $yearless;
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
     * A price in cents, when exactly one amount in the text can be the
     * stay's own.
     *
     * **The rule used to be « exactly ONE amount in the whole text », and
     * it was written for a message body.** It is right there: a two-line
     * e-mail naming a deposit and a total is precisely where guessing
     * wrong is most expensive. But the reading now sees the CONTRACT, and
     * a contract always states at least two figures — a total and a
     * deposit — so the rule refused every one of them, systematically,
     * including the documents that state their price most plainly.
     *
     * So amounts are ELIMINATED rather than chosen. An amount whose own
     * words say it is not the stay's price — an acompte, une caution, des
     * arrhes, la TVA — is dropped; whatever is left has to be ALONE, or
     * the answer is still null. Nothing here ranks two candidates against
     * each other: « the bigger one is probably the total » is exactly the
     * guess this class exists not to make.
     */
    public function readPriceCents(string $text): ?int
    {
        $text = $this->normalise($text);
        $candidates = [];

        preg_match_all(
            '/(\d{1,3}(?:[  \.]\d{3})*|\d+)(?:[.,](\d{1,2}))?\s*(?:€|eur\b|euros?\b)/u',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        // Each amount is judged on ITS OWN label, so the neighbours' words
        // cannot disqualify it: « Total 2450 €, acompte 500 € » names one
        // price and one deposit, and a window wide enough to see both
        // would throw away the very figure it exists to find.
        $previousEnd = 0;
        foreach ($matches as $index => $match) {
            $offset = (int) $match[0][1];
            $end = $offset + strlen($match[0][0]);
            $nextStart = isset($matches[$index + 1]) ? (int) $matches[$index + 1][0][1] : strlen($text);

            if (self::isNotTheStaysPrice($text, $offset, $end, $previousEnd, $nextStart)) {
                $previousEnd = $end;
                continue;
            }
            $previousEnd = $end;

            $whole = (string) preg_replace('/[  \.]/u', '', $match[1][0]);
            if (!ctype_digit($whole)) {
                continue;
            }

            $candidates[] = (int) $whole * 100 + (int) str_pad($match[2][0] ?? '', 2, '0');
        }

        // Still exactly one, and still null otherwise. Eliminating is not
        // choosing: two figures that both survive are two figures a human
        // has to look at, exactly as before.
        return count(array_unique($candidates)) === 1 ? $candidates[0] : null;
    }

    /**
     * The words that say an amount is something other than what the stay
     * costs.
     *
     * A deposit, a security deposit, VAT and a discount are all figures a
     * contract states next to its price and none of them IS the price.
     * « solde » is here too: a balance is what remains after a deposit, so
     * a document naming one is a document whose total is elsewhere.
     */
    private const NOT_A_PRICE = [
        'acompte', 'arrhes', 'caution', 'garantie', 'solde', 'tva', 'remise',
        'reduction', 'réduction', 'frais de dossier', 'penalite', 'pénalité',
    ];

    /**
     * How far BEFORE an amount its label is looked for, and how far after.
     *
     * French writes the label before the figure — « acompte : 490 € » — so
     * that is the wide side, bounded by the previous amount so one figure's
     * words are never read as another's.
     *
     * **The trailing side is deliberately tiny**, and that is not timidity:
     * `normalise()` has already collapsed every line break into a space, so
     * there is no longer anything to say where one line ended and the next
     * began. A window of twenty-five reached from « Forfait : 1.468,80 EUR »
     * into « Réception de l'acompte » on the line below and threw the
     * forfait away — the exact figure it exists to find. Fourteen covers
     * every trailing label a contract actually uses (« €/Forfait », « € TTC »,
     * « € de caution ») and reaches no further than the end of its own
     * clause. The cut at punctuation is the other half of the same rule.
     */
    private const LABEL_BEFORE = 40;
    private const LABEL_AFTER = 14;

    /**
     * Offsets are in BYTES (PREG_OFFSET_CAPTURE) while the text is UTF-8,
     * so `substr` is the right tool: cutting with `mb_substr` on a byte
     * offset would slice a multi-byte character in half. The window is only
     * ever searched for a word, never measured.
     */
    private static function isNotTheStaysPrice(
        string $text,
        int $offset,
        int $end,
        int $previousEnd,
        int $nextStart
    ): bool {
        $from = max($previousEnd, $offset - self::LABEL_BEFORE);
        $before = substr($text, $from, max(0, $offset - $from));

        $after = substr($text, $end, min(self::LABEL_AFTER, max(0, $nextStart - $end)));
        // Past a comma or a full stop the sentence has moved on, and what
        // it moved on to belongs to the next figure.
        $after = (string) preg_split('/[.,;:\n]/u', $after, 2)[0];

        foreach (self::NOT_A_PRICE as $word) {
            if (str_contains($before . ' ' . $after, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many people the stay is for, when the text says so in as many
     * words.
     *
     * A number needs a LABEL here, and a narrow one: a contract is full of
     * integers — a postcode, a house number, a booking reference, a year —
     * and the only thing separating « 250 participants » from « 250 » is
     * the word next to it. Exactly one labelled count, or nothing.
     *
     * Bounded at both ends. A unit of one is a typo and a unit of six
     * thousand is a phone number that happened to sit after the word
     * « personnes ».
     */
    public const MIN_PARTICIPANTS = 2;
    public const MAX_PARTICIPANTS = 2000;

    public function readParticipantCount(string $text): ?int
    {
        $text = $this->normalise($text);

        preg_match_all(
            '/(?:nombre\s+(?:pr[ée]vu|de\s+participants|de\s+personnes)|participants?|personnes)'
            . '\s*(?:pr[ée]vus?|attendus?)?\s*[:=]?\s*(\d{1,4})'
            . '|(\d{1,4})\s*(?:participants?|personnes)\b/u',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        $counts = [];
        foreach ($matches as $match) {
            $value = (int) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '0'));
            if ($value >= self::MIN_PARTICIPANTS && $value <= self::MAX_PARTICIPANTS) {
                $counts[] = $value;
            }
        }

        return count(array_unique($counts)) === 1 ? $counts[0] : null;
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

    /**
     * « du 12 au 19 juillet » or « du 30 avril au 3 mai », no year: the
     * next occurrence of that range from the day the message was sent.
     *
     * @return array{start: string, end: string}|null
     */
    private function readYearlessRange(string $text, \DateTimeImmutable $reference): ?array
    {
        $twoMonths = preg_match(
            '/\bdu\s+' . self::DAY . '\s+([a-zéû]+)\s+au\s+' . self::DAY . '\s+([a-zéû]+)\b(?!\s+\d{4})/u',
            $text,
            $m
        ) === 1;
        if ($twoMonths) {
            $startDay = (int) $m[1];
            $startMonth = self::MONTHS[$m[2]] ?? null;
            $endDay = (int) $m[3];
            $endMonth = self::MONTHS[$m[4]] ?? null;
        } elseif (preg_match(
            '/\bdu\s+' . self::DAY . '\s+au\s+' . self::DAY . '\s+([a-zéû]+)\b(?!\s+\d{4})/u',
            $text,
            $m
        ) === 1) {
            $startDay = (int) $m[1];
            $endDay = (int) $m[2];
            $startMonth = $endMonth = self::MONTHS[$m[3]] ?? null;
        } else {
            return null;
        }

        if ($startMonth === null || $endMonth === null) {
            return null;
        }

        $year = (int) $reference->format('Y');
        $startYear = $startMonth > $endMonth ? $year - 1 : $year;
        $range = $this->range($startYear, $startMonth, $startDay, $year, $endMonth, $endDay);
        if ($range !== null && $range['end'] < $reference->format('Y-m-d')) {
            // Already past when the message was sent: it means next year's.
            $range = $this->range($startYear + 1, $startMonth, $startDay, $year + 1, $endMonth, $endDay);
        }

        return $range;
    }

    /**
     * A day of the month as a message writes it: « 12 », « 1er », and
     * optionally the weekday before it, which is never captured.
     */
    private const DAY = '(?:(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(\d{1,2})(?:er)?';

    private function normalise(string $text): string
    {
        // Quoted lines — the earlier message a reply carries under « > »
        // — are not this message's statement. Reading them made the
        // farmer's confirmation say whatever the unit's own request had
        // said, first.
        $text = (string) preg_replace('/^[ \t]*>.*$/mu', ' ', $text);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }
}
