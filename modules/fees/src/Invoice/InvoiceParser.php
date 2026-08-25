<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

use Core\Member\UnitStaffSectionService;

/**
 * Reads a federation invoice out of the text layer of its PDF.
 *
 * Robustness here does not come from cleverer patterns; it comes from
 * never leaning on position, and from refusing loudly. Six rules, each of
 * them a decision rather than an implementation detail:
 *
 * 1. **Recognise shapes.** A tariff line ends with three numbers. A
 *    nominative line contains a `jj/mm/aaaa` date — the most stable mark
 *    in the whole document: what precedes it is the name, what follows it
 *    is the function.
 * 2. **No hardcoded list of references.** An unknown reference never
 *    blocks a reading; its nature is read off its own shape
 *    ({@see InvoiceLine::nature()}).
 * 3. **Arithmetic is the integrity check, in three layers**: unit price ×
 *    quantity = amount, per line; names listed = quantity, on every line
 *    that has a list; Σ amounts = TOTAL A PAYER. One failure and the
 *    reading is refused, naming the offending line.
 * 4. **A page-break repetition is deduplicated by tuple identity**, not by
 *    detecting a page boundary ({@see InvoiceLine::tuple()}).
 * 5. **Tolerate the unknown, count it.** Anything matching neither shape
 *    is ignored, and the number of ignored rows is returned.
 * 6. **Belgian numbers** ({@see BelgianNumber}).
 *
 * The parser takes TEXT, not a PDF: extraction is
 * `Core\File\PdfTextExtractor`'s job and belongs to
 * {@see InvoiceReader}, which keeps every rule above testable without a
 * binary file.
 */
class InvoiceParser
{
    /** `jj/mm/aaaa`, the marker a nominative line is recognised by. */
    private const DATE = '/\b(\d{2})\/(\d{2})\/(\d{4})\b/u';

    /** A section code as this federation writes one: letters, then digits. */
    private const SECTION_CODE = '/\b[A-Z]{1,6}\d{2,}[A-Z0-9]*\b/u';

    /**
     * The label the federation prints instead of a code for the unit's own
     * staff. It maps onto this site's own convention for that section
     * (`Core\Member\UnitStaffSectionService::DESK_CODE`), which is the one
     * place the two vocabularies have to be tied together.
     */
    private const UNIT_STAFF_LABELS = ['staff d\'unite', 'staff d\'unité', 'staff d unite'];

    public function parse(string $text): InvoiceReadResult
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        /** @var InvoiceLine[] $tariffLines */
        $tariffLines = [];
        /** @var InvoicePerson[][] $peopleByTuple */
        $peopleByTuple = [];
        $currentTuple = null;
        $ignored = 0;

        $documentNumber = null;
        $issueDate = null;
        $totalCents = null;
        $iban = null;
        $communication = null;
        $templateNumber = null;

        foreach ($lines as $rawLine) {
            $line = trim(preg_replace('/\s+/u', ' ', BelgianNumber::collapseGroupSeparators($rawLine)) ?? '');
            if ($line === '') {
                continue;
            }

            // The footer is tried FIRST, because "Date : 08/01/2026" carries
            // the same date marker a nominative line does and would
            // otherwise be read as somebody called "Date".
            $footer = $this->readFooter($line);
            if ($footer !== null) {
                [$key, $value] = $footer;
                match ($key) {
                    'total' => $totalCents = (int) $value,
                    'iban' => $iban = (string) $value,
                    'communication' => $communication = (string) $value,
                    'template' => $templateNumber = (string) $value,
                    'document' => $documentNumber ??= (string) $value,
                    'date' => $issueDate ??= (string) $value,
                };
                continue;
            }

            // Then the date marker, before the numbers: a nominative line
            // carries digits too, and a "three trailing numbers" test would
            // read "12/04/2016" as an amount on a document whose separators
            // this parser had misjudged.
            $person = $this->readPerson($line);
            if ($person !== null) {
                if ($currentTuple === null) {
                    $ignored++;
                    continue;
                }
                $peopleByTuple[$currentTuple][] = $person;
                continue;
            }

            $tariff = $this->readTariffLine($line);
            if ($tariff === null) {
                $ignored++;
                continue;
            }

            $tuple = $tariff->tuple();
            if (!isset($tariffLines[$tuple])) {
                $tariffLines[$tuple] = $tariff;
                $peopleByTuple[$tuple] = [];
            }
            $currentTuple = $tuple;
        }

        $merged = [];
        foreach ($tariffLines as $tuple => $tariff) {
            $merged[] = $tariff->withPeople($this->dedupePeople($peopleByTuple[$tuple] ?? []));
        }

        $problems = $this->checkIntegrity($merged, $totalCents);
        if ($problems !== []) {
            return InvoiceReadResult::refused($problems, $ignored);
        }

        return InvoiceReadResult::accepted(new ParsedInvoice(
            $documentNumber,
            $issueDate,
            $merged,
            (int) $totalCents,
            $iban,
            $communication,
            $templateNumber,
            $ignored
        ));
    }

    /**
     * A nominative line: everything before the date is the name, everything
     * after it is the function. The name splits on its LAST word being the
     * first name, because the federation prints `Nom Prénom` — a compound
     * surname therefore stays whole, and a compound first name does not,
     * which is the trade-off this shape forces either way.
     */
    private function readPerson(string $line): ?InvoicePerson
    {
        if (preg_match(self::DATE, $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $before = trim(substr($line, 0, (int) $match[0][1]));
        $after = trim(substr($line, (int) $match[0][1] + strlen((string) $match[0][0])));
        if ($before === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $before) ?: [];
        if (count($words) < 2) {
            return null;
        }
        // Every word before the date has to look like part of a name. A
        // header row such as "Date : 08/01/2026" carries the same marker,
        // and reading it as somebody called "Date" would put a phantom in
        // the count.
        foreach ($words as $word) {
            if (preg_match('/^\p{L}[\p{L}\-\x{2019}\'.]*$/u', $word) !== 1) {
                return null;
            }
        }

        $firstName = (string) array_pop($words);
        $lastName = implode(' ', $words);
        $birthDate = sprintf('%s-%s-%s', $match[3][0], $match[2][0], $match[1][0]);

        return new InvoicePerson($lastName, $firstName, $birthDate, $after === '' ? null : $after);
    }

    /** A tariff line: a reference, a descriptor, then unit price, quantity, amount. */
    private function readTariffLine(string $line): ?InvoiceLine
    {
        $pattern = '/^(?<reference>\S+)\s+(?<descriptor>.*?)\s+(?<pu>' . BelgianNumber::PATTERN . ')'
            . '\s+(?<qt>\d+)\s+(?<amount>' . BelgianNumber::PATTERN . ')$/u';
        if (preg_match($pattern, $line, $match) !== 1) {
            return null;
        }

        $unitPrice = BelgianNumber::toCents($match['pu']);
        $amount = BelgianNumber::toCents($match['amount']);
        if ($unitPrice === null || $amount === null) {
            return null;
        }

        return new InvoiceLine(
            $match['reference'],
            trim($match['descriptor']),
            $this->readSectionCode(trim($match['descriptor'])),
            $unitPrice,
            (int) $match['qt'],
            $amount
        );
    }

    /**
     * The section a line is about, matched on the code the site imports
     * (`sections.desk_code`) rather than on a displayed name — a rename in
     * Config Desk touches `sections.name` and must break nothing here.
     *
     * A descriptor with no code at all is not a defect: `COT_iAM_LOCAL` and
     * the deposit deduction genuinely have no section.
     */
    private function readSectionCode(string $descriptor): ?string
    {
        // A typographic apostrophe and a straight one are the same word;
        // which of the two a given export prints is not a decision.
        $folded = str_replace("\u{2019}", "'", mb_strtolower($descriptor));
        foreach (self::UNIT_STAFF_LABELS as $label) {
            if (str_contains($folded, $label)) {
                return UnitStaffSectionService::DESK_CODE;
            }
        }

        return preg_match(self::SECTION_CODE, $descriptor, $match) === 1 ? $match[0] : null;
    }

    /**
     * @return array{'total'|'iban'|'communication'|'template'|'document'|'date', string|int}|null
     *         the footer field this line carries, and its value
     */
    private function readFooter(string $line): ?array
    {
        // [^0-9-] and not \D: a greedy \D would swallow the minus sign and
        // turn a credit note into a debit.
        if (preg_match('/TOTAL\s+A\s+PAYER[^0-9-]*(' . BelgianNumber::PATTERN . ')/ui', $line, $match) === 1) {
            $cents = BelgianNumber::toCents($match[1]);

            return $cents === null ? null : ['total', $cents];
        }
        if (preg_match('/\b([A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7})\b/u', $line, $match) === 1
            && str_contains(mb_strtoupper($line), 'IBAN')) {
            return ['iban', (string) preg_replace('/\s+/u', '', $match[1])];
        }
        if (preg_match('/\+\+\+\d{3}\/\d{4}\/\d{5}\+\+\+/u', $line, $match) === 1) {
            return ['communication', $match[0]];
        }
        if (preg_match('/\b(Report\d+\s+v\.\d+)/u', $line, $match) === 1) {
            return ['template', $match[1]];
        }
        if (preg_match('/^Facture\s+n[°o]\s*:?\s*(\S+)/ui', $line, $match) === 1) {
            return ['document', $match[1]];
        }
        if (preg_match('/^Date\b[^0-9]*(\d{2})\/(\d{2})\/(\d{4})/u', $line, $match) === 1) {
            return ['date', sprintf('%s-%s-%s', $match[3], $match[2], $match[1])];
        }

        return null;
    }

    /**
     * @param InvoicePerson[] $people
     * @return InvoicePerson[]
     */
    private function dedupePeople(array $people): array
    {
        $seen = [];
        $unique = [];
        foreach ($people as $person) {
            $key = $person->matchKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $person;
        }

        return $unique;
    }

    /**
     * @param InvoiceLine[] $lines
     * @return InvoiceProblem[]
     */
    private function checkIntegrity(array $lines, ?int $totalCents): array
    {
        if ($lines === []) {
            return [new InvoiceProblem(
                InvoiceProblem::NO_LINE_FOUND,
                "Aucune ligne de tarif n'a été reconnue dans ce document."
            )];
        }

        $problems = [];
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line->amountCents;
            $where = $line->reference . ($line->sectionCode === null ? '' : ' / ' . $line->sectionCode);

            $expected = $line->unitPriceCents * $line->quantity;
            if ($expected !== $line->amountCents) {
                $problems[] = new InvoiceProblem(
                    InvoiceProblem::LINE_ARITHMETIC,
                    'Ligne ' . $where . ' : ' . BelgianNumber::format($line->unitPriceCents) . ' × '
                    . $line->quantity . ' donne ' . BelgianNumber::format($expected)
                    . ', la facture indique ' . BelgianNumber::format($line->amountCents) . '.',
                    $line->reference,
                    $line->sectionCode,
                    $expected,
                    $line->amountCents
                );
            }

            if ($line->people !== [] && count($line->people) !== $line->quantity) {
                $problems[] = new InvoiceProblem(
                    InvoiceProblem::NAME_COUNT,
                    'Ligne ' . $where . ' : ' . count($line->people) . ' nom(s) listé(s) pour une quantité de '
                    . $line->quantity . '.',
                    $line->reference,
                    $line->sectionCode
                );
            }
        }

        if ($totalCents === null) {
            $problems[] = new InvoiceProblem(
                InvoiceProblem::TOTAL_MISSING,
                "Le total à payer n'a pas été trouvé dans ce document."
            );

            return $problems;
        }

        if ($sum !== $totalCents) {
            $problems[] = new InvoiceProblem(
                InvoiceProblem::TOTAL_MISMATCH,
                'La somme des lignes vaut ' . BelgianNumber::format($sum)
                . ', le total à payer indique ' . BelgianNumber::format($totalCents) . '.',
                null,
                null,
                $sum,
                $totalCents
            );
        }

        return $problems;
    }
}
