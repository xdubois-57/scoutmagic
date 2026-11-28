<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Service\TextNormalizerService;
use Modules\Finance\Repository\MemberLookupRepository;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * Turns the treasurer's spreadsheet into the rows a campaign is made of.
 *
 * **Two columns are required and nothing else is**: the member
 * identifier the site's own export produces, and an amount. Every other
 * column is free, kept, and becomes a merge variable of the reminder.
 *
 * **There is no fall-back matching on a name**, and that is the whole
 * design rather than an omission. A name is not an identity: two
 * siblings share a surname, two cousins a first name, and a
 * "did you mean" that guesses wrong bills the wrong child — silently,
 * because nothing downstream can tell. So the identifier column decides,
 * and a line whose identifier is missing, empty or unknown **refuses the
 * whole file**. The consequence is accepted and has to be said out loud
 * on the screen and in the help: you never build the list by hand in
 * Excel, you always start from an export of the site.
 *
 * The refusal names every offending line at once, with what the file
 * actually says on it — a refusal that sends somebody to a help page
 * without explaining anything on the spot is a refusal done badly.
 */
class CampaignImportService
{
    /**
     * Far above any unit's roster, low enough that a spreadsheet somebody
     * pasted a hundred thousand rows into cannot fill the database with
     * encrypted blobs before anybody notices.
     */
    public const MAX_ROWS = 5000;

    /**
     * The most one line may invoice: 5 000,00 €.
     *
     * No amount limit existed in the module to reuse; the nearest one,
     * the fees module's 500 € cotisation bound, is per-member and too low
     * for a campaign, which also bills camps. A scout camp, even abroad,
     * costs a family a few hundred euros, a long trip at most a couple of
     * thousand; 5 000 € leaves room above that and still sits far below
     * what a misread decimal separator produces — « 31,875 » read as
     * thousands is 31 875 € (issue #1472), and a real amount of 5 € to
     * 50 € read that way lands between 5 000 € and 50 000 €. The ceiling
     * is what stops such a line instead of billing it.
     */
    public const MAX_AMOUNT_CENTS = 500000;

    /**
     * Above this many euros a value is not an amount anybody meant, and
     * multiplying it by a hundred would leave the integer range.
     */
    private const ABSURD_AMOUNT_EUROS = 1_000_000_000_000;

    /**
     * "ID interne" is the header Core\Member\Export\MemberExportColumns
     * writes for `members.id`, and it is the one this expects. "Identifiant
     * Desk" is accepted as a second exact key, for the treasurer who
     * trimmed the export down to the columns they thought they needed —
     * it is a lookup, not a guess, so accepting it costs nothing and
     * saves a refusal nobody would understand.
     */
    private const MEMBER_ID_ALIASES = ['id interne', 'idinterne', 'member id', 'memberid', 'id membre',
        'identifiant interne'];
    private const DESK_ID_ALIASES = ['identifiant desk', 'identifiantdesk', 'tiers', 'desk id', 'deskid'];
    private const AMOUNT_ALIASES = [
        'montant',
        'montant du',
        'montant a payer',
        'montant eur',
        'amount',
        'prix',
        'tarif'
    ];

    public function __construct(private MemberLookupRepository $members)
    {
    }

    /**
     * Reads and validates the file. Returns what a campaign would be made
     * of; writes nothing.
     *
     * @return CampaignImportResult
     * @throws CampaignImportException when the file is refused — and it is
     *         refused whole, never in part
     */
    public function read(string $filePath): CampaignImportResult
    {
        [$rows, $rawRows] = $this->readFirstSheet($filePath);
        if ($rows === []) {
            throw CampaignImportException::file('Le fichier est vide : la première feuille ne contient aucune ligne.');
        }

        [$columns, $memberIdColumn, $deskIdColumn, $amountColumn] = $this->readHeaders(array_values($rows)[0]);
        $amountIndex = (int) array_search($amountColumn, $columns, true);
        $dataRows = $this->collectDataRows($rows, $rawRows, $columns, $amountIndex);

        if ($dataRows === []) {
            throw CampaignImportException::file("Le fichier ne contient aucune ligne sous la ligne d'en-têtes.");
        }
        if (count($dataRows) > self::MAX_ROWS) {
            throw CampaignImportException::file(sprintf(
                'Le fichier contient %d lignes — le maximum est de %d par campagne.',
                count($dataRows),
                self::MAX_ROWS
            ));
        }

        $resolvedIds = $memberIdColumn !== null
            ? $this->members->resolveIds(array_map(
                static fn(array $row): int => (int) ($row['data'][$memberIdColumn] ?? '0'),
                $dataRows
            ))
            : [];
        $resolvedDeskIds = $deskIdColumn !== null
            ? $this->members->resolveDeskIds(array_map(
                static fn(array $row): string => trim($row['data'][$deskIdColumn] ?? ''),
                $dataRows
            ))
            : [];

        $problems = [];
        $accepted = [];
        $seenMemberIds = [];

        foreach ($dataRows as $row) {
            $memberId = $this->resolveMember(
                $row['data'],
                $memberIdColumn,
                $deskIdColumn,
                $resolvedIds,
                $resolvedDeskIds,
                $problem
            );
            if ($memberId === null) {
                $problems[] = [
                    'line' => $row['line'],
                    'content' => $this->summarize($row['data']),
                    'problem' => (string) $problem
                ];
                continue;
            }

            if (isset($seenMemberIds[$memberId])) {
                $problems[] = [
                    'line' => $row['line'],
                    'content' => $this->summarize($row['data']),
                    'problem' => 'Ce membre apparaît déjà à la ligne ' . $seenMemberIds[$memberId] . '.',
                ];
                continue;
            }

            $amountCents = self::cellAmountCents($row['amount'], $row['data'][$amountColumn] ?? '');
            if ($amountCents === null) {
                $problems[] = [
                    'line' => $row['line'],
                    'content' => $this->summarize($row['data']),
                    'problem' => 'Montant illisible ou absent.',
                ];
                continue;
            }
            if ($amountCents <= 0) {
                $problems[] = [
                    'line' => $row['line'],
                    'content' => $this->summarize($row['data']),
                    'problem' => 'Le montant doit être supérieur à zéro.',
                ];
                continue;
            }
            if ($amountCents > self::MAX_AMOUNT_CENTS) {
                $problems[] = [
                    'line' => $row['line'],
                    'content' => $this->summarize($row['data']),
                    'problem' => sprintf(
                        'Montant de %s € : au-delà du plafond de %s € par ligne. Vérifiez le séparateur '
                            . 'décimal — dans une cellule texte, « 1.500 » se lit mille cinq cents euros.',
                        self::formatEuros($amountCents),
                        self::formatEuros(self::MAX_AMOUNT_CENTS)
                    ),
                ];
                continue;
            }

            $seenMemberIds[$memberId] = $row['line'];

            // The identifier and amount columns are what the campaign IS;
            // everything else is what the reminder can talk about.
            $mergeData = $row['data'];
            unset($mergeData[$amountColumn]);

            $accepted[] = [
                'line' => $row['line'],
                'member_id' => $memberId,
                'amount_cents' => $amountCents,
                'merge_data' => $mergeData,
            ];
        }

        if ($problems !== []) {
            throw new CampaignImportException($problems, rowsRead: count($dataRows));
        }

        $mergeColumns = array_values(array_filter($columns, static fn(string $name): bool => $name !== $amountColumn));

        return new CampaignImportResult($accepted, $mergeColumns);
    }

    /**
     * @param array<string, string> $data
     * @param array<int, int> $resolvedIds
     * @param array<string, int> $resolvedDeskIds
     */
    private function resolveMember(
        array $data,
        ?string $memberIdColumn,
        ?string $deskIdColumn,
        array $resolvedIds,
        array $resolvedDeskIds,
        ?string &$problem
    ): ?int {
        if ($memberIdColumn !== null) {
            $raw = trim($data[$memberIdColumn] ?? '');
            if ($raw === '') {
                $problem = 'Identifiant vide.';
                return null;
            }
            if (!ctype_digit($raw)) {
                $problem = 'Identifiant illisible : « ' . $raw . ' ».';
                return null;
            }
            $resolved = $resolvedIds[(int) $raw] ?? null;
            if ($resolved === null) {
                $problem = 'Identifiant inconnu du site : « ' . $raw . ' ».';
                return null;
            }

            return $resolved;
        }

        $raw = trim($data[$deskIdColumn ?? ''] ?? '');
        if ($raw === '') {
            $problem = 'Identifiant vide.';
            return null;
        }
        $resolved = $resolvedDeskIds[$raw] ?? null;
        if ($resolved === null) {
            $problem = 'Identifiant inconnu du site : « ' . $raw . ' ».';
            return null;
        }

        return $resolved;
    }

    /**
     * What the refused line actually says, for the screen — the first few
     * non-empty cells, so the treasurer recognises the row without going
     * back to the file.
     *
     * @param array<string, string> $data
     */
    private function summarize(array $data): string
    {
        $values = array_values(array_filter($data, static fn(string $v): bool => trim($v) !== ''));
        if ($values === []) {
            return '—';
        }

        $shown = array_slice($values, 0, 3);
        $summary = implode(', ', $shown);

        return mb_strlen($summary) > 80 ? mb_substr($summary, 0, 79) . '…' : $summary;
    }

    /**
     * The amount of one cell, in cents.
     *
     * **A cell the spreadsheet holds as a number is read as that number**,
     * rounded half up to the cent: 31,875 is 31,88 €, whatever the cell's
     * display format makes of it. Only a cell holding text goes through
     * {@see parseAmountCents()} and its thousands-separator rule — reading
     * a number's display through that rule is how 31,875 became
     * 31 875 € (issue #1472).
     *
     * @param mixed $raw the cell's unformatted value, as the workbook stores it
     * @param string $formatted the same cell as displayed
     */
    public static function cellAmountCents(mixed $raw, string $formatted): ?int
    {
        if (is_int($raw) || is_float($raw)) {
            return self::numberToCents($raw);
        }
        if (is_bool($raw)) {
            return null;
        }

        return self::parseAmountCents($formatted);
    }

    /**
     * Rounds half away from zero to the cent on the decimal digits, not on
     * the binary float: 1.005 * 100 is 100.49999… in a float, and
     * `round()` on it would lose the cent the treasurer typed. Fifteen
     * significant digits is what a spreadsheet itself displays, so a
     * formula result such as 31.874999999999996 reads as the 31,875 on
     * the screen.
     */
    private static function numberToCents(int|float $value): ?int
    {
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        if (abs($value) >= self::ABSURD_AMOUNT_EUROS) {
            return null;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (abs($value) < 0.001) {
            return 0; // below what %G prints without an exponent, and far below a cent
        }

        $decimal = sprintf('%.15G', abs($value));
        $dot = strpos($decimal, '.');
        $integerPart = $dot === false ? $decimal : substr($decimal, 0, $dot);
        $fraction = $dot === false ? '' : substr($decimal, $dot + 1);
        $fraction = str_pad($fraction, 3, '0');

        $cents = (int) $integerPart * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $value < 0 ? -$cents : $cents;
    }

    private static function formatEuros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ');
    }

    /**
     * Belgian spreadsheets write "38,25", "38.25", "€ 38,25" and
     * "1 234,50" for the same amount, and a thin or non-breaking space is
     * what Excel actually puts in a thousands separator. Everything that
     * is not a digit or a separator goes; the LAST separator is the
     * decimal one, whichever character it is.
     *
     * Returns null when nothing sane can be read, which the caller turns
     * into a named refusal rather than a silent zero.
     */
    public static function parseAmountCents(string $raw): ?int
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
        if ($value === '' || $value === '-') {
            return null;
        }

        $lastSeparator = max(strrpos($value, ',') ?: -1, strrpos($value, '.') ?: -1);
        if (strrpos($value, ',') === false && strrpos($value, '.') === false) {
            $lastSeparator = -1;
        }

        if (strlen(ltrim(str_replace([',', '.', '-'], '', $value), '0')) > 15) {
            return null; // more digits than any amount, and than an integer holds once in cents
        }

        if ($lastSeparator < 0) {
            return (int) $value * 100;
        }

        $integerPart = str_replace([',', '.'], '', substr($value, 0, $lastSeparator));
        $decimalPart = substr($value, $lastSeparator + 1);

        // Three digits after the last separator is a thousands separator,
        // not a decimal one: "1.500" is fifteen hundred euros, not one
        // euro fifty. This is only ever applied to TEXT: a cell the
        // spreadsheet holds as a number never reaches here (see
        // cellAmountCents()), and a text "31,875" meant as 31,88 € is
        // caught by MAX_AMOUNT_CENTS instead of billed.
        if (strlen($decimalPart) === 3 && ctype_digit($decimalPart)) {
            return (int) ($integerPart . $decimalPart) * 100;
        }
        if (!ctype_digit($decimalPart) && $decimalPart !== '') {
            return null;
        }

        $sign = str_starts_with($integerPart, '-') ? -1 : 1;
        $integerPart = ltrim($integerPart, '-');
        if ($integerPart !== '' && !ctype_digit($integerPart)) {
            return null;
        }

        $cents = (int) str_pad(substr($decimalPart . '00', 0, 2), 2, '0');

        return $sign * ((int) $integerPart * 100 + $cents);
    }

    /**
     * The first sheet twice: as displayed, and as stored.
     *
     * @return array{0: array<int, array<int, mixed>>, 1: array<int, array<int, mixed>>}
     * @throws CampaignImportException
     */
    private function readFirstSheet(string $filePath): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(false);

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable) {
            throw CampaignImportException::file(
                "Le fichier n'a pas pu être lu comme un fichier Excel (.xlsx). Repartez de l'export des membres du "
                    . "site."
            );
        }

        try {
            // Formatted values, not raw floats: an identifier displayed as
            // 4821 has to arrive as "4821" and not as "4821.0".
            /** @var array<int, array<int, mixed>> $rows */
            $rows = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
            // Stored values, for the amount only: a number displayed as
            // "31,875" or "1.500" has to arrive as that number, not as a
            // text the thousands rule would read (issue #1472).
            /** @var array<int, array<int, mixed>> $rawRows */
            $rawRows = $spreadsheet->getSheet(0)->toArray(null, true, false, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return [$rows, $rawRows];
    }

    /**
     * @param array<int, mixed> $headerRow
     * @return array{0: array<int, string>, 1: ?string, 2: ?string, 3: string}
     * @throws CampaignImportException
     */
    private function readHeaders(array $headerRow): array
    {
        $columns = [];
        $seen = [];
        $memberIdColumn = null;
        $deskIdColumn = null;
        $amountColumn = null;

        foreach (array_values($headerRow) as $index => $cell) {
            $name = trim((string) ($cell ?? ''));
            if ($name === '') {
                continue; // a column with no header is ignored entirely
            }

            $normalized = TextNormalizerService::fold($name);
            if (isset($seen[$normalized])) {
                throw CampaignImportException::file(
                    "Deux colonnes portent le même en-tête « {$name} » — renommez l'une des deux."
                );
            }
            $seen[$normalized] = true;
            $columns[$index] = $name;

            if ($memberIdColumn === null && in_array($normalized, self::MEMBER_ID_ALIASES, true)) {
                $memberIdColumn = $name;
            } elseif ($deskIdColumn === null && in_array($normalized, self::DESK_ID_ALIASES, true)) {
                $deskIdColumn = $name;
            } elseif ($amountColumn === null && in_array($normalized, self::AMOUNT_ALIASES, true)) {
                $amountColumn = $name;
            }
        }

        if ($columns === []) {
            throw CampaignImportException::file("La première ligne du fichier doit porter les en-têtes de colonnes.");
        }

        if ($memberIdColumn === null && $deskIdColumn === null) {
            throw CampaignImportException::file(
                "Le fichier n'a pas de colonne d'identifiant. Repartez de l'export des membres du site et gardez sa "
                . 'colonne « ID interne » : c\'est elle qui rattache chaque montant au bon membre. Ne reconstruisez '
                . 'pas la liste à la main.'
            );
        }

        if ($amountColumn === null) {
            throw CampaignImportException::file(
                'Le fichier n\'a pas de colonne « Montant ». Ajoutez-la à l\'export des membres et complétez-la ligne '
                    . 'par ligne.'
            );
        }

        return [$columns, $memberIdColumn, $deskIdColumn, $amountColumn];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, array<int, mixed>> $rawRows the same cells, unformatted
     * @param array<int, string> $columns column name by cell index
     * @param int $amountIndex cell index of the amount column
     * @return array<int, array{line: int, data: array<string, string>, amount: mixed}>
     */
    private function collectDataRows(array $rows, array $rawRows, array $columns, int $amountIndex): array
    {
        $dataRows = [];
        $rawRows = array_values($rawRows);
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

            $dataRows[] = [
                'line' => $rowIndex + 1,
                'data' => $data,
                'amount' => $rawRows[$rowIndex][$amountIndex] ?? null,
            ];
        }

        return $dataRows;
    }
}
