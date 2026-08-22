<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Journal\JournalService;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * Turns an uploaded Excel file into a stored mail-merge audience.
 * First sheet only, first row = headers, one email per data row.
 *
 * Delivery resolution per row (module spec):
 * - a non-empty "Tiers" value → the member with that members.desk_id
 *   (the email later goes to every address known for them); an unknown
 *   Tiers refuses the file;
 * - otherwise a non-empty "Email" value → that address (several allowed,
 *   separated by ";" or ","); an invalid address refuses the file;
 * - neither → the file is refused.
 * Validation is all-or-nothing: any error refuses the WHOLE file, and
 * the AudienceImportException lists every offending line at once.
 *
 * Header recognition is alias-based (case/accent-insensitive), so the
 * site's own exports re-import as-is: "Tiers" ≡ "Identifiant Desk",
 * "Email" ≡ "Email(s)" ≡ "Contact" ≡ "Adresse email" ≡ "Courriel". A
 * multi-address cell uses the same "; " separator the member export
 * writes. Every other column is simply a merge variable.
 *
 * The caller deletes the uploaded file right after this returns (same
 * rule as the Desk CSV import) — the encrypted audience rows are all
 * that remains.
 */
class AudienceImportService
{
    /**
     * Hard cap on data rows — far above any real unit's audience, low
     * enough that an accidental 200k-row sheet can't fill the database
     * with encrypted blobs.
     */
    public const MAX_ROWS = 5000;

    private const TIERS_ALIASES = ['tiers', 'identifiantdesk'];
    private const EMAIL_ALIASES = ['email', 'emails', 'contact', 'adresseemail', 'courriel'];

    public function __construct(
        private AudienceRepository $audienceRepository,
        private MemberResolutionRepository $memberResolutionRepository,
        private JournalService $journalService
    ) {
    }

    /**
     * @throws AudienceImportException when the file is refused — nothing is stored in that case
     */
    public function import(string $filePath, string $originalFilename, ?int $createdBy): AudienceImportResult
    {
        [$sheetName, $rows] = $this->readFirstSheet($filePath);

        if ($rows === []) {
            throw new AudienceImportException(['Le fichier est vide : aucune ligne trouvée sur la première feuille.']);
        }

        [$columns, $tiersColumnIndex, $emailColumnIndex] = $this->readHeaders($rows[0]);
        // collectDataRows() keys each row's data by column NAME — resolve
        // the two special columns to their names once here.
        $tiersColumn = $tiersColumnIndex !== null ? $columns[$tiersColumnIndex] : null;
        $emailColumn = $emailColumnIndex !== null ? $columns[$emailColumnIndex] : null;

        $dataRows = $this->collectDataRows($rows, $columns);
        if ($dataRows === []) {
            throw new AudienceImportException(['Le fichier ne contient aucune ligne de données sous la ligne d\'en-têtes.']);
        }
        if (count($dataRows) > self::MAX_ROWS) {
            throw new AudienceImportException([sprintf(
                'Le fichier contient %d lignes de données — le maximum est de %d lignes par publipostage.',
                count($dataRows),
                self::MAX_ROWS
            )]);
        }

        $memberIdsByDeskId = $tiersColumn !== null
            ? $this->memberResolutionRepository->findMemberIdsByDeskIds(
                array_values(array_filter(array_map(fn(array $r) => trim($r['data'][$tiersColumn] ?? ''), $dataRows), fn(string $v) => $v !== ''))
            )
            : [];

        $errors = [];
        $resolved = [];
        foreach ($dataRows as $row) {
            $lineNo = $row['line'];
            $tiersValue = $tiersColumn !== null ? trim($row['data'][$tiersColumn] ?? '') : '';

            if ($tiersValue !== '') {
                $memberId = $memberIdsByDeskId[$tiersValue] ?? null;
                if ($memberId === null) {
                    $errors[] = "Ligne {$lineNo} — aucun membre ne correspond au Tiers « {$tiersValue} ».";
                    continue;
                }
                $resolved[] = ['line' => $lineNo, 'member_id' => $memberId, 'email' => null, 'data' => $row['data']];
                continue;
            }

            $emailValue = $emailColumn !== null ? trim($row['data'][$emailColumn] ?? '') : '';
            if ($emailValue === '') {
                $errors[] = $tiersColumn !== null && $emailColumn !== null
                    ? "Ligne {$lineNo} — ni « Tiers » ni « Email » renseignés pour cette ligne."
                    : ($tiersColumn !== null
                        ? "Ligne {$lineNo} — « Tiers » vide, et le fichier n'a pas de colonne « Email »."
                        : "Ligne {$lineNo} — « Email » vide pour cette ligne.");
                continue;
            }

            $addresses = $this->splitAddresses($emailValue);
            $invalid = array_values(array_filter($addresses, fn(string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) === false));
            if ($invalid !== []) {
                $errors[] = "Ligne {$lineNo} — la valeur « {$invalid[0]} » de la colonne Email n'est pas une adresse valide.";
                continue;
            }

            $resolved[] = ['line' => $lineNo, 'member_id' => null, 'email' => implode('; ', $addresses), 'data' => $row['data']];
        }

        if ($errors !== []) {
            throw new AudienceImportException($errors);
        }

        $audienceId = $this->audienceRepository->createAudience($originalFilename, $sheetName, $columns, count($resolved), $createdBy);
        foreach ($resolved as $row) {
            $this->audienceRepository->createRow($audienceId, $row['line'], $row['member_id'], $row['email'], $row['data']);
        }

        $this->journalService->log(
            'mass_mail', 'audience_imported', 'info', 'Audience de publipostage importée depuis un fichier Excel',
            ['audience_id' => $audienceId, 'row_count' => count($resolved), 'column_count' => count($columns)], $createdBy
        );

        $audience = $this->audienceRepository->findById($audienceId);
        \assert($audience !== null);

        return new AudienceImportResult($audience, $this->buildWarnings($resolved));
    }

    /**
     * @return array{0: string, 1: array<int, array<int, mixed>>} [sheet name, raw rows]
     * @throws AudienceImportException when the file can't be read as .xlsx
     */
    private function readFirstSheet(string $filePath): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(false);

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable) {
            throw new AudienceImportException(['Le fichier n\'a pas pu être lu comme un fichier Excel (.xlsx).']);
        }

        try {
            // First sheet only — module spec. Formatted values (not raw
            // floats), so a "Tiers" cell displayed as 456789 arrives as
            // the string "456789", and a date cell as its displayed text.
            $sheet = $spreadsheet->getSheet(0);
            $sheetName = $sheet->getTitle();
            /** @var array<int, array<int, mixed>> $rows */
            $rows = $sheet->toArray(null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [$sheetName, $rows];
    }

    /**
     * @param array<int, mixed> $headerRow
     * @return array{0: string[], 1: ?int, 2: ?int} [column names by cell index, tiers column index, email column index]
     * @throws AudienceImportException on duplicate headers or no Tiers/Email column at all
     */
    private function readHeaders(array $headerRow): array
    {
        $errors = [];
        $columns = [];
        $seenNormalized = [];
        $tiersColumn = null;
        $emailColumn = null;

        foreach (array_values($headerRow) as $index => $cell) {
            $name = trim((string) ($cell ?? ''));
            if ($name === '') {
                continue; // A column with no header is ignored entirely.
            }

            $normalized = self::normalizeHeader($name);
            if (isset($seenNormalized[$normalized])) {
                $errors[] = "Deux colonnes portent le même en-tête « {$name} » — renommez l'une des deux.";
                continue;
            }
            $seenNormalized[$normalized] = true;
            $columns[$index] = $name;

            if ($tiersColumn === null && in_array($normalized, self::TIERS_ALIASES, true)) {
                $tiersColumn = $index;
            } elseif ($emailColumn === null && in_array($normalized, self::EMAIL_ALIASES, true)) {
                $emailColumn = $index;
            }
        }

        if ($columns === []) {
            $errors[] = 'La première ligne de la feuille doit contenir les en-têtes de colonnes.';
        } elseif ($tiersColumn === null && $emailColumn === null) {
            $errors[] = 'Le fichier doit contenir une colonne « Tiers » (identifiant Desk du membre) et/ou une colonne « Email ».';
        }

        if ($errors !== []) {
            throw new AudienceImportException($errors);
        }

        return [$columns, $tiersColumn, $emailColumn];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param string[] $columns Column names keyed by cell index.
     * @return array<int, array{line: int, data: array<string, string>}> data keyed by column NAME; entirely-empty rows skipped
     */
    private function collectDataRows(array $rows, array $columns): array
    {
        $dataRows = [];
        foreach (array_values($rows) as $rowIndex => $cells) {
            if ($rowIndex === 0) {
                continue; // headers
            }

            $data = [];
            $hasValue = false;
            foreach ($columns as $cellIndex => $name) {
                $value = trim((string) ($cells[$cellIndex] ?? ''));
                $data[$name] = $value;
                if ($value !== '') {
                    $hasValue = true;
                }
            }
            if (!$hasValue) {
                continue; // an entirely empty line is not an error
            }

            $dataRows[] = ['line' => $rowIndex + 1, 'data' => $data];
        }
        return $dataRows;
    }

    /**
     * @return string[]
     */
    private function splitAddresses(string $value): array
    {
        return array_values(array_filter(array_map('trim', (array) preg_split('/[;,]/', $value)), fn(string $a) => $a !== ''));
    }

    /**
     * Non-blocking: "one row = one email" makes a duplicated Tiers or
     * address legitimate, but it's usually a copy-paste accident worth
     * pointing out before the send doubles someone's mail.
     *
     * @param array<int, array{line: int, member_id: ?int, email: ?string, data: array<string, string>}> $resolved
     * @return string[]
     */
    private function buildWarnings(array $resolved): array
    {
        $warnings = [];

        $memberLines = [];
        $addressLines = [];
        foreach ($resolved as $row) {
            if ($row['member_id'] !== null) {
                $memberLines[$row['member_id']][] = $row['line'];
            } elseif ($row['email'] !== null) {
                foreach ($this->splitAddresses($row['email']) as $address) {
                    $addressLines[mb_strtolower($address)][] = $row['line'];
                }
            }
        }

        $duplicateMemberGroups = array_values(array_filter($memberLines, fn(array $lines) => count($lines) > 1));
        if ($duplicateMemberGroups !== []) {
            $linesList = implode(' ; ', array_map(fn(array $lines) => 'lignes ' . implode(', ', $lines), $duplicateMemberGroups));
            $warnings[] = "Un même Tiers apparaît sur plusieurs lignes ({$linesList}) : chaque ligne enverra son propre email.";
        }

        $duplicateAddressGroups = array_values(array_filter($addressLines, fn(array $lines) => count($lines) > 1));
        if ($duplicateAddressGroups !== []) {
            $linesList = implode(' ; ', array_map(fn(array $lines) => 'lignes ' . implode(', ', $lines), $duplicateAddressGroups));
            $warnings[] = "Une même adresse email apparaît sur plusieurs lignes ({$linesList}) : chaque ligne enverra son propre email.";
        }

        return $warnings;
    }

    /**
     * Lowercased, accents stripped, everything non-alphanumeric removed —
     * so "Identifiant Desk", "identifiant-desk" and "IDENTIFIANT DESK"
     * all reach the same alias, and "E-mail(s)" matches "emails".
     */
    private static function normalizeHeader(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $ascii = strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return (string) preg_replace('/[^a-z0-9]/', '', $ascii);
    }
}
