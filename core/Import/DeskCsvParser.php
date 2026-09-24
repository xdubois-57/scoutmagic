<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Journal\JournalService;

/**
 * Desk CSV export parser.
 *
 * **Changing anything here changes the reference dataset too.**
 * `tests/fixtures/reference-dataset/` holds three committed exports built to
 * this exact format — 35 headers in this order, `;`-delimited, `true`/`false`
 * booleans, dates as `JJ/MM/AAAA`, one row per (function × address) — and a
 * CLI builder that replays them into a test instance. Its README is the
 * manual, and AGENTS.md § Reference dataset says what has to be checked in the
 * same change. `Tests\Integration\ReferenceDatasetFormatTest` will fail on the
 * pull request that breaks it, rather than on the day somebody tries to build
 * an instance.
 *
 * Two details in EXPECTED_HEADERS are not typos and must not be "fixed":
 * `Sizaine/Patrouillle` really is spelled with three Ls in a real export, and
 * `SECTION` (all-caps) is a separate field this parser deliberately never
 * reads — the section's identity comes from `Section`.
 */
class DeskCsvParser
{
    /**
     * How many column names one journal entry may quote, and how long
     * each may be. A refused header line is a diagnostic, not a place to
     * mirror an arbitrary file: the names come from a document this site
     * did not write, and a malformed one can carry hundreds.
     */
    private const MAX_JOURNALLED_HEADERS = 20;
    private const MAX_JOURNALLED_HEADER_LENGTH = 100;

    /**
     * How many expected headers a line must carry before its OTHER cells
     * may be written down — SECURITY.md §13, « Journal stores only
     * metadata — never raw CSV content », and its kept-file rule 5, « No
     * line of CSV in a journal entry, an error message or a trace,
     * including when the parse fails ».
     *
     * `parse()` treats line 0 as the header line unconditionally, because
     * it has nothing else to go on. So when a file arrives with its header
     * row stripped, or the delimiter is misdetected, line 0 is a MEMBER:
     * `Dupont`, `Marie`, a birth date, a phone number, an address. Writing
     * those cells into `event_log.context` would put personal data in a
     * journal that `Core\Support\Collector\EventJournalCollector` copies
     * verbatim into a support package — an archive that leaves the
     * installation.
     *
     * Two thirds is the line between the two cases, and it is not a close
     * call: the case this journal entry exists for — the federation
     * renames `Email Tiers` to `Courriel` — still carries 34 of the 35
     * expected names, while a row of member data carries none of them. A
     * column name from a line PROVEN to be the schema row is metadata
     * about the file, which is what §13 allows; anything from a line that
     * is not is content, which it forbids.
     */
    private const HEADER_LINE_MIN_EXPECTED_RATIO = 2 / 3;

    public function __construct(
        /**
         * Where a refused header line is written down (issue #356). Null
         * parses exactly as before — every existing call site, tests
         * included, keeps working.
         */
        private ?JournalService $journal = null
    ) {
    }

    /** @var string[] */
    private const EXPECTED_HEADERS = [
        'Nom', 'Prenom', 'Genre', 'Date de naissance', 'Tél', 'GSM',
        'Email Tiers', 'Rue', 'No', 'Bte', 'cplt adr', 'Code Postal',
        'Ville', 'Pays', "Type d'adresse", 'Courrier fédération',
        "Courrier d'unité", 'Groupe unités', "Fonction au sein de l'unité",
        'FONCTION', 'Tiers', 'Branche', 'Section', 'SECTION',
        'Date début', 'Date fin', 'fin de mandat', 'Fonction principale',
        'Tarif', 'Totem', 'Quali', 'Sizaine/Patrouillle', 'Niveau formation',
        'Handicap', 'Assurance complémentaire',
    ];

    /**
     * Parse the CSV file and return structured data grouped by Tiers (desk_id).
     *
     * @throws ImportException If headers don't match or file is unreadable
     */
    public function parse(string $filePath): ParsedImport
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new ImportException('Le fichier CSV est introuvable ou illisible.');
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            throw new ImportException('Le fichier CSV est vide.');
        }

        // Strip UTF-8 BOM if present
        $content = $this->stripBom($content);

        // Detect and convert encoding
        $content = $this->ensureUtf8($content);

        $lines = $this->splitLines($content);

        if (count($lines) === 0) {
            throw new ImportException('Le fichier CSV est vide.');
        }

        // Detect delimiter from header line
        $delimiter = $this->detectDelimiter($lines[0]);

        // Parse header line
        $headers = str_getcsv($lines[0], $delimiter, '"', '');
        $headers = array_map('trim', $headers);
        $this->validateHeaders($headers);

        // Build header index map
        $headerMap = array_flip($headers);

        // Parse data lines
        $grouped = [];
        $lineCount = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $fields = str_getcsv($line, $delimiter, '"', '');
            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = isset($fields[$idx]) ? trim($fields[$idx]) : '';
            }

            $deskId = $row['Tiers'] ?? '';
            if ($deskId === '') {
                continue;
            }

            $grouped[$deskId][] = $row;
            $lineCount++;
        }

        // Build ParsedMember list
        $members = [];
        foreach ($grouped as $deskId => $rows) {
            $members[] = $this->buildMember((string) $deskId, $rows);
        }

        return new ParsedImport($members, $lineCount);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function buildMember(string $deskId, array $rows): ParsedMember
    {
        $first = $rows[0];

        // Addresses: deduplicate by type
        $addressMap = [];
        foreach ($rows as $row) {
            $type = $row["Type d'adresse"] ?? '';
            if ($type === '' || isset($addressMap[$type])) {
                continue;
            }
            $addressMap[$type] = new ParsedAddress(
                type: $type,
                street: $this->nullIfEmpty($row['Rue'] ?? ''),
                number: $this->nullIfEmpty($row['No'] ?? ''),
                box: $this->nullIfEmpty($row['Bte'] ?? ''),
                complement: $this->nullIfEmpty($row['cplt adr'] ?? ''),
                postalCode: $this->nullIfEmpty($row['Code Postal'] ?? ''),
                city: $this->nullIfEmpty($row['Ville'] ?? ''),
                country: $this->nullIfEmpty($row['Pays'] ?? '')
            );
        }

        // Functions: one per line
        $functions = [];
        foreach ($rows as $row) {
            $functionCode = $row['FONCTION'] ?? '';
            if ($functionCode === '') {
                continue;
            }
            $functions[] = new ParsedFunction(
                functionCode: $functionCode,
                branchCode: $this->nullIfEmpty($row['Branche'] ?? ''),
                // The section identity always comes from the "Section" column —
                // "SECTION" (all-caps) is a separate Desk export field that can
                // hold incorrect/stale data and must never be used to identify
                // a section.
                sectionCode: $this->nullIfEmpty($row['Section'] ?? ''),
                sectionName: $this->nullIfEmpty($row['Section'] ?? ''),
                startDate: $this->nullIfEmpty($row['Date début'] ?? ''),
                endDate: $this->nullIfEmpty($row['Date fin'] ?? ''),
                mandateEnd: $this->nullIfEmpty($row['fin de mandat'] ?? ''),
                isMainFunction: $this->parseBool($row['Fonction principale'] ?? '')
            );
        }

        return new ParsedMember(
            deskId: $deskId,
            lastName: $first['Nom'] ?? '',
            firstName: $first['Prenom'] ?? '',
            gender: $this->nullIfEmpty($first['Genre'] ?? ''),
            birthDate: $this->nullIfEmpty($first['Date de naissance'] ?? ''),
            phone: $this->nullIfEmpty($first['Tél'] ?? ''),
            mobile: $this->nullIfEmpty($first['GSM'] ?? ''),
            email: $this->nullIfEmpty($first['Email Tiers'] ?? ''),
            totem: $this->nullIfEmpty($first['Totem'] ?? ''),
            quali: $this->nullIfEmpty($first['Quali'] ?? ''),
            patrol: $this->nullIfEmpty($first['Sizaine/Patrouillle'] ?? ''),
            formationLevel: $this->nullIfEmpty($first['Niveau formation'] ?? ''),
            federationMailConsent: $this->parseBool($first['Courrier fédération'] ?? ''),
            unitMailConsent: $this->parseBool($first["Courrier d'unité"] ?? ''),
            feeCode: $this->nullIfEmpty($first['Tarif'] ?? ''),
            unitCode: $this->nullIfEmpty($first["Fonction au sein de l'unité"] ?? ''),
            handicap: $this->nullIfEmpty($first['Handicap'] ?? ''),
            supplementaryInsurance: $this->nullIfEmpty($first['Assurance complémentaire'] ?? ''),
            addresses: array_values($addressMap),
            functions: $functions
        );
    }

    /**
     * @param string[] $headers
     * @throws ImportException
     */
    private function validateHeaders(array $headers): void
    {
        $missing = [];
        foreach (self::EXPECTED_HEADERS as $expected) {
            if (!in_array($expected, $headers, true)) {
                $missing[] = $expected;
            }
        }

        if (count($missing) > 0) {
            // What the file actually carried where an expected column
            // should have been. The exception can only name what is
            // absent, and « Email Tiers manquant » is the symptom of
            // « Courriel est arrivé à sa place » — a rename nobody can
            // act on without seeing the new spelling, and the reason
            // EXPECTED_HEADERS warns that two of its entries are not
            // typos (issue #356).
            $this->journalRefusedHeaders($headers, $missing);

            throw new ImportException(
                'En-têtes CSV manquants : ' . implode(', ', $missing)
            );
        }
    }

    /**
     * @param string[] $headers the header line exactly as read
     * @param string[] $missing the expected names it does not carry
     */
    private function journalRefusedHeaders(array $headers, array $missing): void
    {
        if ($this->journal === null) {
            return;
        }

        $unexpected = array_values(array_diff($headers, self::EXPECTED_HEADERS));
        $present = count(self::EXPECTED_HEADERS) - count($missing);
        $isAHeaderLine = $present >= (int) ceil(count(self::EXPECTED_HEADERS) * self::HEADER_LINE_MIN_EXPECTED_RATIO);

        $context = [
            // Counts, always: they say what happened and can describe no
            // one. `missing` names are this class's own constants, never
            // anything the file supplied.
            'unexpected_count' => count($unexpected),
            'missing' => self::boundedNames($missing),
            'code_table' => DeskMappingGapKind::CSV_HEADER->codeTable(),
        ];

        if ($isAHeaderLine) {
            $context['unexpected'] = self::boundedNames($unexpected);
        }

        $this->journal->log(
            'core',
            DeskMappingGapKind::CSV_HEADER->journalType(),
            'info',
            $isAHeaderLine
                ? 'Import Desk refusé : ' . count($missing) . ' en-tête(s) attendu(s) absent(s), '
                    . count($unexpected) . ' inattendu(s)'
                : "Import Desk refusé : le fichier ne commence pas par une ligne d'en-têtes",
            $context
        );
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    private static function boundedNames(array $names): array
    {
        return array_map(
            static fn(string $name): string => mb_substr($name, 0, self::MAX_JOURNALLED_HEADER_LENGTH),
            array_slice($names, 0, self::MAX_JOURNALLED_HEADERS)
        );
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        return $content;
    }

    private function ensureUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return $content;
    }

    /**
     * @return string[]
     */
    private function splitLines(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return [];
        }
        // Remove trailing empty lines
        while (count($lines) > 0 && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function parseBool(string $value): bool
    {
        return strtolower(trim($value)) === 'true';
    }

    /**
     * Detect delimiter by counting occurrences of comma vs semicolon in the header line.
     */
    private function detectDelimiter(string $headerLine): string
    {
        $semicolons = substr_count($headerLine, ';');
        $commas = substr_count($headerLine, ',');

        return $semicolons > $commas ? ';' : ',';
    }
}
