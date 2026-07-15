<?php

declare(strict_types=1);

namespace Core\Import;

class DeskCsvParser
{
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
                sectionCode: $this->nullIfEmpty($row['SECTION'] ?? ''),
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
            throw new ImportException(
                'En-têtes CSV manquants : ' . implode(', ', $missing)
            );
        }
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
