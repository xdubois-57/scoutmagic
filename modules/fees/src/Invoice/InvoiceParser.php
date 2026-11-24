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
 * 4. **A page-break repetition needs evidence**: a page marker, the same
 *    tariff tuple and a continued or repeated nominative list. Identical
 *    independent charges remain separate.
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
    private const TARIFF_REFERENCE = '[A-Z]{3}_[A-Za-z0-9_]+';
    /** The next footer token can be attached directly after the page total. */
    private const PAGE_MARKER = '/\bPage\s*(\d+)\s*(?:sur\s*|\/\s*)(\d+)/ui';

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
        $stats = new InvoiceReadStats();
        $stats->extractedLines = count($lines);
        $stats->pageCount = self::pageCount($text);
        $stats->tabsSeen = str_contains($text, "\t");
        // Smalot joins trimmed pages with two newlines. Use these boundaries
        // only when a printed page total corroborates the exact block count;
        // an arbitrary blank paragraph alone proves no page break.
        $pageSeparators = $stats->pageCount !== null
            && count(explode("\n\n", $text)) === $stats->pageCount;

        /** @var InvoiceLine[] $tariffLines */
        $tariffLines = [];
        /** @var InvoicePerson[][] $peopleByLine */
        $peopleByLine = [];
        /** @var bool[] $afterPageBreak */
        $afterPageBreak = [];
        /** @var InvoiceProblem[] $readingProblems */
        $readingProblems = [];
        $currentLine = null;
        $pageBreak = false;
        $ignored = 0;

        $documentNumber = null;
        $issueDate = null;
        $totalCents = null;
        $iban = null;
        $communication = null;
        $templateNumber = null;

        foreach ($lines as $index => $rawLine) {
            if (preg_match('/\d[' . BelgianNumber::NBSP_CLASS . '.]\d{3}\b/u', $rawLine) === 1) {
                $stats->groupSeparatedNumbers++;
            }
            $collapsed = BelgianNumber::collapseGroupSeparators($rawLine);
            // Two views of the same row: the cells as the PDF separated them
            // (a tab between cells, other whitespace single), and the plain
            // line every other rule reads.
            $cells = trim(preg_replace('/[^\S\t]+/u', ' ', $collapsed) ?? '');
            $line = trim(preg_replace('/\s+/u', ' ', $collapsed) ?? '');
            if ($line === '') {
                $pageBreak = $pageBreak || $pageSeparators;
                continue;
            }
            $stats->nonEmptyLines++;

            // A VAT footer has a percentage followed by base, tax and
            // total. Its decimal amounts must not look like an unread fee.
            $number = BelgianNumber::PATTERN;
            $vatSummary = '/^\d+(?:,\d+)?\s*%\s+' . $number . '\s+' . $number . '\s+' . $number . '$/u';
            if (preg_match($vatSummary, $line) === 1) {
                $ignored++;
                continue;
            }

            if (preg_match(self::PAGE_MARKER, $line) === 1) {
                $pageBreak = true;
                $ignored++;
                continue;
            }

            if (self::isTariffHeader($line)) {
                $pageBreak = $currentLine !== null;
                $ignored++;
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

            // The header row of the federation's own export: the number,
            // then the issue date, glued to the due date (#1327).
            $header = $this->readDocumentHeader($line);
            if ($header !== null) {
                $documentNumber ??= $header[0];
                $issueDate ??= $header[1];
                continue;
            }

            // Then the date marker, before the numbers: a nominative line
            // carries digits too, and a "three trailing numbers" test would
            // read "12/04/2016" as an amount on a document whose separators
            // this parser had misjudged.
            $person = $this->readPerson($line, $cells);
            if ($person !== null) {
                $stats->personRows++;
                if ($currentLine === null) {
                    $stats->orphanPersonRows++;
                    $ignored++;
                    continue;
                }
                $peopleByLine[$currentLine][] = $person;
                // A list can continue without its tariff being repeated.
                $pageBreak = false;
                continue;
            }

            $problemsBefore = count($readingProblems);
            $tariff = $this->readTariffLine($line, $cells, $stats, $readingProblems);
            if ($tariff === null) {
                // A row read and then rejected for its content (a fractional
                // quantity) already said why; it is neither « not understood »
                // nor ignored.
                if (count($readingProblems) === $problemsBefore) {
                    if (self::looksFinancial($line)) {
                        $stats->unrecognisedFinancialRows[] = $index + 1;
                    }
                    $ignored++;
                }
                continue;
            }

            $stats->tariffRows++;
            $currentLine = count($tariffLines);
            $tariffLines[] = $tariff;
            $peopleByLine[$currentLine] = [];
            $afterPageBreak[$currentLine] = $pageBreak;
            $pageBreak = false;
        }

        $stats->ignoredRows = $ignored;
        $stats->totalFound = $totalCents !== null;
        $stats->documentNumberFound = $documentNumber !== null;

        $merged = [];
        foreach ($tariffLines as $index => $tariff) {
            $line = $tariff->withPeople($this->dedupePeople($peopleByLine[$index]));
            $previousIndex = count($merged) - 1;
            $continuation = $this->joinContinuation(
                $merged[$previousIndex] ?? null,
                $line,
                $afterPageBreak[$index],
                $readingProblems
            );
            if ($continuation !== null) {
                $merged[$previousIndex] = $continuation;
                $stats->repeatedTariffRows++;
                continue;
            }
            $merged[] = $line;
        }
        $stats->distinctTariffLines = count($merged);

        // A row that looks like money and was not read is a document only
        // partly understood: nothing is accepted on a partial reading,
        // whatever the arithmetic of the rest says (#1327).
        if ($stats->unrecognisedFinancialRows !== []) {
            $count = count($stats->unrecognisedFinancialRows);
            $readingProblems[] = new InvoiceProblem(
                InvoiceProblem::UNRECOGNISED_LINE,
                $count . ' ligne(s) qui ressemblent à une ligne de tarif n\'ont pas pu être lues (ligne(s) '
                    . implode(', ', array_slice($stats->unrecognisedFinancialRows, 0, 10))
                    . ' du texte extrait) : le format de ce document n\'est pas encore reconnu.'
            );
        }

        // A reading already refused for what it could not take in is
        // refused for that alone: checking the arithmetic of the part that
        // was read would add a « no line » or a total that cannot close —
        // true of the partial reading, false of the document.
        $problems = $readingProblems !== []
            ? self::distinct($readingProblems)
            : $this->checkIntegrity($merged, $totalCents);
        if ($problems !== []) {
            return InvoiceReadResult::refused($problems, $ignored, $stats);
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
        ), $stats);
    }

    private static function isTariffHeader(string $line): bool
    {
        $lower = mb_strtolower($line);

        return preg_match('/\d/u', $line) !== 1
            && str_contains($lower, 'description')
            && str_contains($lower, 'montant')
            && (str_contains($lower, 'référence') || str_contains($lower, 'reference'));
    }

    /** @param InvoiceProblem[] $problems */
    private function joinContinuation(
        ?InvoiceLine $previous,
        InvoiceLine $line,
        bool $afterPageBreak,
        array &$problems
    ): ?InvoiceLine {
        if (!$afterPageBreak || $previous === null || $previous->tuple() !== $line->tuple()) {
            return null;
        }
        if ($previous->people === [] && $line->people === []) {
            $problems[] = new InvoiceProblem(
                InvoiceProblem::AMBIGUOUS_REPETITION,
                'Deux lignes identiques de part et d\'autre d\'un saut de page ne permettent pas de distinguer '
                    . 'une continuation de deux cotisations : aucune liste nominative ne le confirme.',
                $line->reference,
                $line->sectionCode
            );

            return null;
        }

        $people = $this->dedupePeople([...$previous->people, ...$line->people]);
        $continued = count($previous->people) < abs($line->quantity)
            && count($people) <= abs($line->quantity);
        $repeated = count($people) === count($previous->people);

        return $continued || $repeated ? $previous->withPeople($people) : null;
    }

    /**
     * A row repeated at the top of a page is rejected twice for the same
     * reason; it is said once, as the row is read once.
     *
     * @param InvoiceProblem[] $problems
     * @return InvoiceProblem[]
     */
    private static function distinct(array $problems): array
    {
        $seen = [];
        $distinct = [];
        foreach ($problems as $problem) {
            $key = $problem->kind . "\0" . $problem->message;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $distinct[] = $problem;
            }
        }

        return $distinct;
    }

    /** « Page 3 sur 10 » or « Page 3/10 », when printed; null otherwise. */
    private static function pageCount(string $text): ?int
    {
        if (preg_match_all(self::PAGE_MARKER, $text, $matches) < 1) {
            return null;
        }

        return max(array_map('intval', $matches[2]));
    }

    /**
     * A row that carries money and was not understood: a tariff reference
     * (`COT_…`, glued to the text before it or not), or two amounts at its
     * end. Read off its shape only — nothing of it is kept.
     */
    private static function looksFinancial(string $line): bool
    {
        if (preg_match('/' . self::TARIFF_REFERENCE . '/u', $line) === 1) {
            return true;
        }

        return preg_match('/-?\d+,\d{2}\D*-?\d+,\d{2}\s*$/u', $line) === 1;
    }

    /**
     * `NUMERO⇥jj/mm/aaaajj/mm/aaaa…`: the invoice number, then the issue
     * date and the due date glued together, as the federation's export
     * writes the row under « N° Document … Date facture Echéance ».
     *
     * The text layer gives the two dates in an order that is not the
     * column order — the due date came first on the real document — so the
     * issue date is the EARLIER of the two: an invoice is never due before
     * it is issued.
     *
     * @return array{string, string}|null
     */
    private function readDocumentHeader(string $line): ?array
    {
        $date = '(\d{2})\/(\d{2})\/(\d{4})';
        if (preg_match('/^([A-Z]{2,8}-\d{3,})\s+' . $date . '(?:\s*' . $date . ')?/u', $line, $match) !== 1) {
            return null;
        }

        $dates = [sprintf('%s-%s-%s', $match[4], $match[3], $match[2])];
        if (($match[7] ?? '') !== '') {
            $dates[] = sprintf('%s-%s-%s', $match[7], $match[6], $match[5]);
        }

        return [$match[1], min($dates)];
    }

    /**
     * A nominative line: everything before the date is the name, everything
     * after it is the function. The name splits on its LAST word being the
     * first name, because the federation prints `Nom Prénom` — a compound
     * surname therefore stays whole, and a compound first name does not,
     * which is the trade-off this shape forces either way.
     */
    private function readPerson(string $line, string $cells = ''): ?InvoicePerson
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

        // Where the export separates the name cells with a tab, the cells
        // say which words are the surname — a compound first name then
        // stays whole too (#1327).
        $nameCells = explode("\t", $cells);
        if (count($nameCells) >= 3 && preg_match(self::DATE, (string) $nameCells[2]) === 1) {
            $lastName = trim((string) $nameCells[0]);
            $firstName = trim((string) $nameCells[1]);
        }
        $birthDate = sprintf('%s-%s-%s', $match[3][0], $match[2][0], $match[1][0]);

        return new InvoicePerson($lastName, $firstName, $birthDate, $after === '' ? null : $after);
    }

    /**
     * A tariff line, in either of the two layouts seen so far.
     *
     * - **Spaced**: `REF descriptor PU QT AMOUNT`, one cell per word.
     * - **Glued** (the federation's own export, #1327): the cells of the
     *   left half run together — section code, descriptor, reference —
     *   then a tab, then `QT AMOUNTPU`, the amount and the unit price
     *   written with no space between them. Both always carry two decimals,
     *   which is what makes the split deterministic; unit price × quantity
     *   = amount then checks it.
     *
     * A quantity may be written `9,00` or `-1,00` (a deposit deducted). It
     * is a whole number or the line is refused — never truncated.
     *
     * @param InvoiceProblem[] $problems a fractional quantity is added here
     */
    private function readTariffLine(
        string $line,
        string $cells,
        InvoiceReadStats $stats,
        array &$problems
    ): ?InvoiceLine {
        // An amount, its thousands separated by an ordinary space or not —
        // the non-breaking ones are already gone. Unambiguous on this side of
        // the tab: every number there carries its two decimals.
        $money = '-?(?:\d{1,3}(?: \d{3})+|\d+),\d{2}';
        $quantity = '-?\d+(?:,\d{1,2})?';

        if (str_contains($cells, "\t")) {
            $glued = '/^(?<left>[^\t]+)\t\s*(?<qt>' . $quantity . ')\s+'
                . '(?<amount>' . $money . ')\s*(?<pu>' . $money . ')$/u';
            if (preg_match($glued, $cells, $match) === 1) {
                $left = trim($match['left']);
                $referencePattern = '/^(?<rest>.*?)(?<reference>' . self::TARIFF_REFERENCE . ')$/u';
                if (preg_match($referencePattern, $left, $parts) !== 1) {
                    return null;
                }
                $descriptor = self::separateSectionCode(trim($parts['rest']));
                $stats->gluedTariffRows++;

                return $this->buildTariffLine(
                    $parts['reference'],
                    $descriptor,
                    str_replace(' ', '', $match['pu']),
                    $match['qt'],
                    str_replace(' ', '', $match['amount']),
                    $stats,
                    $problems
                );
            }
        }

        $pattern = '/^(?<reference>' . self::TARIFF_REFERENCE . ')\s+(?<descriptor>.*?)\s+(?<pu>'
            . BelgianNumber::PATTERN . ')'
            . '\s+(?<qt>' . $quantity . ')\s+(?<amount>' . BelgianNumber::PATTERN . ')$/u';
        if (preg_match($pattern, $line, $match) !== 1) {
            return null;
        }
        $stats->spacedTariffRows++;

        return $this->buildTariffLine(
            $match['reference'],
            trim($match['descriptor']),
            $match['pu'],
            $match['qt'],
            $match['amount'],
            $stats,
            $problems
        );
    }

    /**
     * @param InvoiceProblem[] $problems
     */
    private function buildTariffLine(
        string $reference,
        string $descriptor,
        string $rawUnitPrice,
        string $rawQuantity,
        string $rawAmount,
        InvoiceReadStats $stats,
        array &$problems
    ): ?InvoiceLine {
        $unitPrice = BelgianNumber::toCents($rawUnitPrice);
        $amount = BelgianNumber::toCents($rawAmount);
        $quantityCents = BelgianNumber::toCents($rawQuantity);
        if ($unitPrice === null || $amount === null || $quantityCents === null) {
            return null;
        }
        if (str_contains($rawQuantity, ',')) {
            $stats->decimalQuantities++;
        }
        if ($quantityCents < 0) {
            $stats->negativeQuantities++;
        }

        $sectionCode = $this->readSectionCode($descriptor);
        if ($quantityCents % 100 !== 0) {
            $problems[] = new InvoiceProblem(
                InvoiceProblem::FRACTIONAL_QUANTITY,
                'Ligne ' . $reference . ($sectionCode === null ? '' : ' / ' . $sectionCode)
                    . ' : la quantité ' . $rawQuantity . " n'est pas un nombre entier de personnes.",
                $reference,
                $sectionCode
            );

            return null;
        }

        return new InvoiceLine(
            $reference,
            $descriptor,
            $sectionCode,
            $unitPrice,
            intdiv($quantityCents, 100),
            $amount
        );
    }

    /**
     * `SV025B1COTISATION NORMALE` → `SV025B1 COTISATION NORMALE`: the
     * glued export runs the section code into the descriptor, and the code
     * is read as a word of its own afterwards.
     */
    private static function separateSectionCode(string $descriptor): string
    {
        return (string) preg_replace('/^([A-Z]{1,6}\d{2,}(?:[A-Z]\d+)?)(?=\p{Lu})/u', '$1 ', $descriptor);
    }

    /**
     * The section a line is about, matched on the code the site imports
     * (`sections.desk_code`) rather than on a displayed name — a rename in
     * Correspondances Desk touches `sections.name` and must break nothing here.
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
        // The federation's export prints the amount BEFORE its label,
        // with the euro sign glued to it: `1 848,50 €TOTAL A PAYER:` (#1327).
        if (preg_match('/^(' . BelgianNumber::PATTERN . ')\s*€?\s*TOTAL\s+A\s+PAYER\b/ui', $line, $match) === 1) {
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
