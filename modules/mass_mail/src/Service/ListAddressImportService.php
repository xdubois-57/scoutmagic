<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Journal\JournalService;
use Modules\MassMail\Repository\ListAddress;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The Excel round trip of a list's own addresses: out through
 * {@see export()}, back in through {@see analyse()} and {@see apply()}.
 *
 * **Replacing a list wholesale is the one operation of this module with
 * no way back**, which is why it takes two steps. A misspelled header and
 * three hundred addresses vanish; so the upload only ever ANALYSES — it
 * writes nothing, and answers with the counts of what a confirmation
 * would do. Nothing is applied until somebody has read those counts and
 * said yes. It is the house habit: the Desk import has its mappings
 * confirmed, an update restores itself.
 *
 * **The uploaded file is deleted the moment the analysis returns**,
 * success or failure — the caller's `finally` (SECURITY.md §5, the same
 * rule as the Desk CSV and the mail-merge audience). Nothing of it
 * survives on disk, which is also why the confirmation carries the rows
 * back rather than re-reading a file: there is no file left to re-read.
 *
 * **Columns are found by their header, never by their position** — the
 * same rule the Desk import follows. `Désinscrit` is read and then
 * ignored: no import can ever re-subscribe anybody, and a spreadsheet
 * that could undo an unsubscribe would make the unsubscribe link worth
 * nothing.
 */
class ListAddressImportService
{
    public const COLUMN_NAME = 'Nom';
    public const COLUMN_EMAIL = 'Adresse';
    public const COLUMN_UNSUBSCRIBED = 'Désinscrit';

    /**
     * Accepted spellings of each column, normalised. `Email` and
     * `Adresse email` are here because they are what somebody types, and
     * `Adresse` alone is what the export writes.
     */
    private const NAME_ALIASES = ['nom', 'nomcomplet', 'contact'];
    private const EMAIL_ALIASES = ['adresse', 'adresseemail', 'email', 'courriel', 'mail'];
    private const UNSUBSCRIBED_ALIASES = ['desinscrit', 'desinscrite', 'desabonne'];

    /**
     * $suppressedAddressRepository is what keeps a file from re-opening a
     * door somebody asked to be shut — see apply(). Nullable so a test
     * that only reads a file does not have to build it.
     */
    public function __construct(
        private ListAddressRepository $addressRepository,
        private ListAddressService $addressService,
        private JournalService $journal,
        private ?SuppressedAddressRepository $suppressedAddressRepository = null
    ) {
    }

    /**
     * The file a chief downloads, edits and sends back. Three columns:
     * the two that are read on the way in, and `Désinscrit` in third
     * place, which is read-only — it is there so the file says which
     * lines are out rather than looking as though they had been lost.
     *
     * Every cell is written as explicit text (SECURITY.md §23): a name is
     * whatever somebody typed, and a leading `=` in a general-typed cell
     * becomes a live formula when the file is opened.
     */
    public function export(int $listId): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Adresses');

        $sheet->setCellValue('A1', self::COLUMN_NAME);
        $sheet->setCellValue('B1', self::COLUMN_EMAIL);
        $sheet->setCellValue('C1', self::COLUMN_UNSUBSCRIBED);

        $row = 2;
        foreach ($this->addressRepository->findForList($listId) as $address) {
            $sheet->setCellValueExplicit('A' . $row, $address->name ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, $address->email, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit(
                'C' . $row,
                $address->isUnsubscribed() ? 'Oui' : 'Non',
                DataType::TYPE_STRING
            );
            $row++;
        }

        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Reads the file and says what a confirmation WOULD do. Writes
     * nothing.
     *
     * @throws ListAddressImportException on anything structural — the whole file is refused
     */
    public function analyse(int $listId, string $filePath): ListAddressImportPreview
    {
        $rows = $this->readFirstSheet($filePath);
        if ($rows === []) {
            throw new ListAddressImportException([
                'Le fichier est vide : aucune ligne trouvée sur la première feuille.',
            ]);
        }

        [$nameIndex, $emailIndex] = $this->readHeaders(array_values($rows[0]));

        $addresses = [];
        $seen = [];
        $errors = [];
        $duplicates = 0;

        foreach (array_slice($rows, 1) as $offset => $row) {
            $line = $offset + 2;
            $cells = array_values($row);
            $email = trim((string) ($cells[$emailIndex] ?? ''));
            $name = $nameIndex !== null ? trim((string) ($cells[$nameIndex] ?? '')) : '';

            if ($email === '' && $name === '') {
                continue; // A blank line between two blocks is not an error.
            }
            if ($email === '') {
                $errors[] = "Ligne {$line} — aucune adresse dans la colonne « " . self::COLUMN_EMAIL . ' ».';
                continue;
            }

            $normalised = mb_strtolower($email);
            if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "Ligne {$line} — « {$email} » n'est pas une adresse email valide.";
                continue;
            }
            if (isset($seen[$normalised])) {
                $duplicates++;
                continue;
            }

            $seen[$normalised] = true;
            $addresses[] = ['name' => $name !== '' ? $name : null, 'email' => $normalised];
        }

        // The cap is asked here, on the analysis, so the refusal arrives
        // before anybody is invited to confirm a replacement that could
        // not be applied.
        $this->addressService->assertRoomForReplacement($listId, count($addresses));

        return new ListAddressImportPreview(
            $addresses,
            $this->summarise($listId, $addresses),
            $errors,
            $duplicates
        );
    }

    /**
     * Applies what {@see analyse()} previewed: this list's addresses
     * become exactly these, except the unsubscribed ones, which are
     * neither removed nor re-subscribed whatever the file says.
     *
     * @param array<int, array{name: ?string, email: string}> $addresses
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     * @throws MailingListException when the cap would be exceeded — nothing is written in that case
     */
    public function apply(int $listId, array $addresses, ?int $actorId): array
    {
        $addresses = $this->sanitise($addresses);
        $this->addressService->assertRoomForReplacement($listId, count($addresses));

        $summary = $this->addressRepository->replaceForList($listId, $addresses);

        // A file never re-subscribes anybody (D3), and `replaceForList()`
        // already refuses to touch an unsubscribed row of THIS list. What
        // it cannot see is an address unsubscribed somewhere else and now
        // arriving as a new line: it would be created active. The
        // suppression table is the one place that knows, every unsubscribe
        // writing to it whoever asked, so the rows it names are flagged
        // right after the replacement — in one query for the whole file,
        // then one statement per address actually concerned, which is
        // almost always none.
        $imported = array_map(static fn(array $a): string => $a['email'], $addresses);
        foreach ($this->suppressedAddressRepository?->filterSuppressed($imported) ?? [] as $suppressed) {
            $this->addressRepository->unsubscribeEverywhere($suppressed);
        }

        $this->journal->log(
            'mass_mail',
            'list_addresses_replaced',
            'info',
            'Adresses d\'une liste de diffusion remplacées depuis un fichier Excel',
            // Counts and identifiers only — never an address (SECURITY.md §11).
            ['list_id' => $listId] + $summary,
            $actorId
        );

        return $summary;
    }

    /**
     * What the confirmation carried back is treated as input, not as
     * something this service already vouched for: the analysis ran in an
     * earlier request and the file it read is gone, so the rows are
     * re-validated and re-deduplicated here.
     *
     * @param array<int, array{name?: ?string, email?: string}> $addresses
     * @return array<int, array{name: ?string, email: string}>
     * @throws MailingListException
     */
    private function sanitise(array $addresses): array
    {
        $clean = [];
        $seen = [];

        foreach ($addresses as $address) {
            $email = mb_strtolower(trim((string) ($address['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new MailingListException('Le fichier contient une adresse invalide — reprenez l\'import.');
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $name = trim((string) ($address['name'] ?? ''));
            $clean[] = ['name' => $name !== '' ? mb_substr($name, 0, 150) : null, 'email' => $email];
        }

        return $clean;
    }

    /**
     * @param array<int, array{name: ?string, email: string}> $addresses
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     */
    private function summarise(int $listId, array $addresses): array
    {
        $incoming = [];
        foreach ($addresses as $address) {
            $incoming[$address['email']] = true;
        }

        $added = count($incoming);
        $unchanged = 0;
        $removed = 0;
        $keptUnsubscribed = 0;

        foreach ($this->addressRepository->findForList($listId) as $current) {
            $key = mb_strtolower($current->email);
            if (isset($incoming[$key])) {
                $unchanged++;
                $added--;
                continue;
            }
            if ($current->isUnsubscribed()) {
                $keptUnsubscribed++;
                continue;
            }
            $removed++;
        }

        return [
            'added' => $added,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'kept_unsubscribed' => $keptUnsubscribed,
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     * @throws ListAddressImportException
     */
    private function readFirstSheet(string $filePath): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable) {
            throw new ListAddressImportException([
                'Le fichier n\'a pas pu être lu comme un fichier Excel (.xlsx).',
            ]);
        }

        try {
            /** @var array<int, array<int, mixed>> $rows */
            $rows = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $rows;
    }

    /**
     * Columns are found by their header. A file whose address column is
     * misspelled is refused whole, naming what was expected — that
     * refusal is the whole reason the header rule exists, since by
     * position the same file would silently replace every address with a
     * column of names.
     *
     * @param array<int, mixed> $headerRow
     * @return array{0: ?int, 1: int} [name column index or null, address column index]
     * @throws ListAddressImportException
     */
    private function readHeaders(array $headerRow): array
    {
        $nameIndex = null;
        $emailIndex = null;
        $unsubscribedIndex = null;

        foreach ($headerRow as $index => $cell) {
            $normalised = self::normalizeHeader((string) ($cell ?? ''));
            if ($normalised === '') {
                continue;
            }
            if ($emailIndex === null && in_array($normalised, self::EMAIL_ALIASES, true)) {
                $emailIndex = $index;
            } elseif ($nameIndex === null && in_array($normalised, self::NAME_ALIASES, true)) {
                $nameIndex = $index;
            } elseif ($unsubscribedIndex === null && in_array($normalised, self::UNSUBSCRIBED_ALIASES, true)) {
                // Read, and deliberately never used: no import can
                // re-subscribe anybody, and none can unsubscribe anybody
                // either — that is the recipient's own decision.
                $unsubscribedIndex = $index;
            }
        }

        if ($emailIndex === null) {
            throw new ListAddressImportException([
                'Le fichier doit contenir une colonne « ' . self::COLUMN_EMAIL . ' » — c\'est celle qui porte les '
                . 'adresses email. Les colonnes sont reconnues par leur en-tête, jamais par leur position.',
            ]);
        }

        return [$nameIndex, $emailIndex];
    }

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
