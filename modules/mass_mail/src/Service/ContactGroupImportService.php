<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The Excel round trip of a contact group — the ONLY way its contacts
 * change: out through {@see export()}, back in through {@see analyse()}
 * and {@see apply()}, and {@see createGroup()} / {@see updateGroup()} for
 * the dialog's « Enregistrer », which writes the name and the file
 * together.
 *
 * **Replacing a group wholesale is the one operation of this module with
 * no way back**, which is why it takes two steps. A misspelled header and
 * three hundred addresses vanish; so the upload only ever ANALYSES — it
 * writes nothing, and answers with the counts of what « Enregistrer »
 * would do. Nothing is applied until somebody has read those counts and
 * saved. It is the house habit: the Desk import has its mappings
 * confirmed, an update restores itself.
 *
 * **The uploaded file is deleted the moment the analysis returns**,
 * success or failure — the caller's `finally` (SECURITY.md §5, the same
 * rule as the Desk CSV and the mail-merge audience). Nothing of it
 * survives on disk, which is also why the save carries the rows back
 * rather than re-reading a file: there is no file left to re-read.
 *
 * **Columns are found by their header, never by their position** — the
 * same rule the Desk import follows. `Désinscrit` is read and then
 * ignored: no import can ever re-subscribe anybody, and a spreadsheet
 * that could undo an unsubscribe would make the unsubscribe link worth
 * nothing.
 *
 * **The cap is per group** (`mass_mail_list_addresses_max`, the setting
 * key kept from when a list carried its addresses directly).
 */
class ContactGroupImportService
{
    public const SETTING_MAX_CONTACTS = 'mass_mail_list_addresses_max';
    private const DEFAULT_MAX_CONTACTS = 2000;

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
        private ListBlockRepository $blockRepository,
        private ContactRepository $contactRepository,
        private ListBlockService $blockService,
        private SettingService $settingService,
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
     * A null $blockId is the empty template « Télécharger un modèle
     * vide » offers before a group exists: the same three headers, no
     * row.
     *
     * Every cell is written as explicit text (SECURITY.md §23): a name is
     * whatever somebody typed, and a leading `=` in a general-typed cell
     * becomes a live formula when the file is opened.
     *
     * @throws MailingListException on an unknown block or a member group
     */
    public function export(?int $blockId): Spreadsheet
    {
        $contacts = $blockId !== null ? $this->blockService->findContacts($blockId) : [];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contacts');

        $sheet->setCellValue('A1', self::COLUMN_NAME);
        $sheet->setCellValue('B1', self::COLUMN_EMAIL);
        $sheet->setCellValue('C1', self::COLUMN_UNSUBSCRIBED);

        $row = 2;
        foreach ($contacts as $contact) {
            $sheet->setCellValueExplicit('A' . $row, $contact->name ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, $contact->email, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit(
                'C' . $row,
                $contact->isUnsubscribed() ? 'Oui' : 'Non',
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
     * Reads the file and says what « Enregistrer » WOULD do. Writes
     * nothing. A null $blockId analyses the file of a group not created
     * yet: every contact is an addition.
     *
     * @throws ContactGroupImportException on anything structural — the whole file is refused
     * @throws MailingListException on an unknown block, a member group, or the cap
     */
    public function analyse(?int $blockId, string $filePath): ContactGroupImportPreview
    {
        if ($blockId !== null) {
            $this->blockService->requireContactGroup($blockId);
        }

        $rows = $this->readFirstSheet($filePath);
        if ($rows === []) {
            throw new ContactGroupImportException([
                'Le fichier est vide : aucune ligne trouvée sur la première feuille.',
            ]);
        }

        [$nameIndex, $emailIndex] = $this->readHeaders(array_values($rows[0]));

        $contacts = [];
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
            $contacts[] = ['name' => $name !== '' ? $name : null, 'email' => $normalised];
        }

        // The cap is asked here, on the analysis, so the refusal arrives
        // before anybody is invited to save a replacement that could not
        // be applied.
        $this->assertRoomForReplacement($blockId, $contacts);

        return new ContactGroupImportPreview(
            $contacts,
            $this->summarise($blockId, $contacts),
            $errors,
            $duplicates
        );
    }

    /**
     * Applies what {@see analyse()} previewed: this group's contacts
     * become exactly these, except the unsubscribed ones, which are
     * neither removed nor re-subscribed whatever the file says. Stamps the
     * group's `file_replaced_at`.
     *
     * The summary counts as {@see summarise()} does: a line new to the
     * group whose address is suppressed elsewhere is created unsubscribed,
     * so it is counted in `kept_unsubscribed`, not in `added`.
     *
     * @param array<int, array{name?: ?string, email?: string}> $contacts
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     * @throws MailingListException on an unknown block, a member group, an invalid
     *         address, or the cap — nothing is written in any of those cases
     */
    public function apply(int $blockId, array $contacts, ?int $actorId): array
    {
        $block = $this->blockService->requireContactGroup($blockId);
        $contacts = $this->sanitise($contacts);
        $this->assertRoomForReplacement($blockId, $contacts);

        $summary = $this->blockRepository->atomically(function () use ($blockId, $contacts): array {
            // A file never re-subscribes anybody (D3), and `replaceForBlock()`
            // already refuses to touch an unsubscribed row of THIS group. What
            // it cannot see is an address unsubscribed somewhere else and now
            // arriving as a new line: it would be created active. The
            // suppression table is the one place that knows, every unsubscribe
            // writing to it whoever asked, so the rows it names are flagged
            // right after the replacement, in the same transaction — asked in
            // one query for the whole file, then one statement per address
            // actually concerned, which is almost always none.
            $suppressed = $this->suppressedIn($contacts);
            $bornUnsubscribed = count($this->notHeldBy($blockId, $suppressed));

            $summary = $this->contactRepository->replaceForBlock($blockId, $contacts);
            $this->blockRepository->markFileReplaced($blockId, new \DateTimeImmutable());

            // Through the block service, which journals it. The flag is the
            // global unsubscribe on purpose, every group of every list: the
            // address asked the unit, not a list, to stop — any other row
            // holding it is normally flagged already, and one that is not is
            // exactly the door this pass exists to shut.
            foreach ($suppressed as $address) {
                $this->blockService->unsubscribeEverywhere($address);
            }

            // What the summary says is what the group can be written to:
            // a line born unsubscribed is not one more address mailed.
            $summary['added'] -= $bornUnsubscribed;
            $summary['kept_unsubscribed'] += $bornUnsubscribed;

            return $summary;
        });

        $this->journal->log(
            'mass_mail',
            'list_contacts_replaced',
            'info',
            'Contacts d\'un groupe de liste de diffusion remplacés depuis un fichier Excel',
            // Counts and identifiers only — never an address (SECURITY.md §11).
            ['list_id' => $block->listId, 'block_id' => $blockId] + $summary,
            $actorId
        );

        return $summary;
    }

    /**
     * « Enregistrer » on a group that does not exist yet: a name and the
     * contacts of the file analysed, written together or not at all. A
     * contact group is never created without its file.
     *
     * @param array<int, array{name?: ?string, email?: string}> $contacts
     * @throws MailingListException on an unknown list, a missing name, an invalid address or the cap
     */
    public function createGroup(int $listId, string $name, array $contacts, ?int $actorId): ListBlock
    {
        $this->blockService->requireList($listId);
        $name = $this->blockService->contactGroupName($name);
        $contacts = $this->sanitise($contacts);
        $this->assertRoomForReplacement(null, $contacts);

        $blockId = $this->blockRepository->atomically(function () use ($listId, $name, $contacts, $actorId): int {
            $blockId = $this->blockRepository->create($listId, ListBlock::KIND_CONTACT_GROUP, $name, $actorId);
            $this->journal->log(
                'mass_mail',
                'list_block_created',
                'info',
                'Groupe de contacts ajouté à une liste de diffusion',
                ['list_id' => $listId, 'block_id' => $blockId, 'kind' => ListBlock::KIND_CONTACT_GROUP],
                $actorId
            );
            $this->apply($blockId, $contacts, $actorId);

            return $blockId;
        });

        $block = $this->blockRepository->findById($blockId);
        \assert($block !== null);

        return $block;
    }

    /**
     * « Enregistrer » on an existing group: the name, and the file when
     * one was chosen ($contacts null otherwise) — both validated before
     * either is written, so a refused file never leaves a renamed group
     * behind.
     *
     * @param array<int, array{name?: ?string, email?: string}>|null $contacts
     * @return array{
     *     block: ListBlock,
     *     summary: ?array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     * }
     * @throws MailingListException on an unknown block, a member group, a missing name, an invalid address or the cap
     */
    public function updateGroup(int $blockId, string $name, ?array $contacts, ?int $actorId): array
    {
        $block = $this->blockService->requireContactGroup($blockId);
        $name = $this->blockService->contactGroupName($name);
        if ($contacts !== null) {
            $contacts = $this->sanitise($contacts);
            $this->assertRoomForReplacement($blockId, $contacts);
        }

        $summary = $this->blockRepository->atomically(function () use ($block, $name, $contacts, $actorId): ?array {
            if ($name !== $block->name) {
                $this->blockRepository->rename($block->id, $name);
                $this->journal->log(
                    'mass_mail',
                    'list_block_updated',
                    'info',
                    'Groupe de contacts d\'une liste de diffusion renommé',
                    ['list_id' => $block->listId, 'block_id' => $block->id, 'kind' => $block->kind],
                    $actorId
                );
            }

            return $contacts !== null ? $this->apply($block->id, $contacts, $actorId) : null;
        });

        $updated = $this->blockRepository->findById($blockId);
        \assert($updated !== null);

        return ['block' => $updated, 'summary' => $summary];
    }

    /**
     * The cap, and the two reasons it exists — both of which the setting's
     * own description states in French, because a number nobody can
     * explain is a number somebody raises.
     */
    public function maxContacts(): int
    {
        $value = (int) $this->settingService->get(
            self::SETTING_MAX_CONTACTS,
            'mass_mail',
            (string) self::DEFAULT_MAX_CONTACTS
        );

        return $value > 0 ? $value : self::DEFAULT_MAX_CONTACTS;
    }

    /**
     * The cap, asked of a wholesale REPLACEMENT: what the file brings is
     * what the group will hold, plus the unsubscribed rows a replacement
     * never removes — **and only those the file does not carry itself**.
     *
     * That last clause is the whole point. `export()` writes the
     * unsubscribed rows into the file, so an untouched export re-imported
     * carries them back, and `replaceForBlock()` counts such a row as
     * unchanged rather than as an addition. Adding every unsubscribed row
     * on top of the file's own count would therefore count them twice and
     * refuse the round trip of a group sitting near the cap — which is
     * exactly the size of group this feature exists for.
     *
     * Asked before anything is written, twice — once on the analysis so
     * the refusal arrives before « Enregistrer » is offered, and once on
     * the save, which arrives in a request of its own and cannot trust
     * what an earlier one checked.
     *
     * @param array<int, array{name: ?string, email: string}> $contacts
     * @throws MailingListException
     */
    private function assertRoomForReplacement(?int $blockId, array $contacts): void
    {
        $max = $this->maxContacts();
        $incoming = count($contacts);
        $surviving = $blockId !== null
            ? $this->contactRepository->countUnsubscribedNotIn(
                $blockId,
                array_map(static fn(array $c): string => $c['email'], $contacts)
            )
            : 0;

        if ($incoming + $surviving > $max) {
            throw new MailingListException(
                'Ce fichier porterait le groupe à ' . ($incoming + $surviving) . ' contacts, au-delà du maximum de '
                . "{$max} (réglage « Contacts par groupe (maximum) »). Rien n'a été modifié."
            );
        }
    }

    /**
     * What the save carried back is treated as input, not as something
     * this service already vouched for: the analysis ran in an earlier
     * request and the file it read is gone, so the rows are re-validated
     * and re-deduplicated here.
     *
     * @param array<int, array{name?: ?string, email?: string}> $contacts
     * @return array<int, array{name: ?string, email: string}>
     * @throws MailingListException
     */
    private function sanitise(array $contacts): array
    {
        $clean = [];
        $seen = [];

        foreach ($contacts as $contact) {
            $email = mb_strtolower(trim((string) ($contact['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new MailingListException('Le fichier contient une adresse invalide — reprenez l\'import.');
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $name = trim((string) ($contact['name'] ?? ''));
            $clean[] = ['name' => $name !== '' ? mb_substr($name, 0, 150) : null, 'email' => $email];
        }

        return $clean;
    }

    /**
     * @param array<int, array{name: ?string, email: string}> $contacts
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     */
    private function summarise(?int $blockId, array $contacts): array
    {
        $incoming = [];
        foreach ($contacts as $contact) {
            $incoming[$contact['email']] = true;
        }

        $added = count($incoming);
        $unchanged = 0;
        $removed = 0;
        $keptUnsubscribed = 0;

        foreach ($blockId !== null ? $this->contactRepository->findForBlock($blockId) : [] as $current) {
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

        // A line the save would create already unsubscribed — its address
        // suppressed elsewhere, see apply() — is not an address added to
        // those the group writes to, and the preview says so as the save
        // will.
        $bornUnsubscribed = count($this->notHeldBy($blockId, $this->suppressedIn($contacts)));

        return [
            'added' => $added - $bornUnsubscribed,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'kept_unsubscribed' => $keptUnsubscribed + $bornUnsubscribed,
        ];
    }

    /**
     * The addresses of $contacts that `mass_mail_suppressed_addresses`
     * names — one query for the whole file.
     *
     * @param array<int, array{name: ?string, email: string}> $contacts
     * @return string[]
     */
    private function suppressedIn(array $contacts): array
    {
        return $this->suppressedAddressRepository?->filterSuppressed(
            array_map(static fn(array $c): string => $c['email'], $contacts)
        ) ?? [];
    }

    /**
     * Those of $addresses this group does not hold yet — the lines the
     * replacement would create. The group is read only when there is
     * something to compare, which is almost never.
     *
     * @param string[] $addresses
     * @return string[]
     */
    private function notHeldBy(?int $blockId, array $addresses): array
    {
        if ($addresses === [] || $blockId === null) {
            return $addresses;
        }

        $held = [];
        foreach ($this->contactRepository->findForBlock($blockId) as $contact) {
            $held[mb_strtolower($contact->email)] = true;
        }

        return array_values(array_filter(
            $addresses,
            static fn(string $address): bool => !isset($held[mb_strtolower($address)])
        ));
    }

    /**
     * @return array<int, array<int, mixed>>
     * @throws ContactGroupImportException
     */
    private function readFirstSheet(string $filePath): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable) {
            throw new ContactGroupImportException([
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
     * @throws ContactGroupImportException
     */
    private function readHeaders(array $headerRow): array
    {
        // Column role => the aliases that name it. `Désinscrit` is
        // recognised so that a second one is caught like any other
        // duplicate, and then deliberately never used: no import can
        // re-subscribe anybody, and none can unsubscribe anybody either —
        // that is the recipient's own decision.
        $roles = [
            self::COLUMN_EMAIL => self::EMAIL_ALIASES,
            self::COLUMN_NAME => self::NAME_ALIASES,
            self::COLUMN_UNSUBSCRIBED => self::UNSUBSCRIBED_ALIASES,
        ];

        /** @var array<string, int> $found column label => index */
        $found = [];
        /** @var array<string, true> $duplicated */
        $duplicated = [];

        foreach ($headerRow as $index => $cell) {
            $normalised = self::normalizeHeader((string) ($cell ?? ''));
            if ($normalised === '') {
                continue;
            }
            foreach ($roles as $label => $aliases) {
                if (!in_array($normalised, $aliases, true)) {
                    continue;
                }
                if (isset($found[$label])) {
                    // Two columns claiming the same role. Reading from
                    // whichever came first is the silent wrong-column
                    // outcome that matching by header exists to prevent,
                    // so the file is refused instead — once per role,
                    // however many extra columns there are.
                    $duplicated[$label] = true;
                    break;
                }
                $found[$label] = $index;
                break;
            }
        }

        $errors = [];
        foreach (array_keys($duplicated) as $label) {
            $errors[] = 'Le fichier contient deux colonnes « ' . $label . ' ». Une seule est attendue : le site ne '
                . 'peut pas deviner laquelle lire, et lire la mauvaise remplacerait les contacts du groupe en silence.';
        }
        if (!isset($found[self::COLUMN_EMAIL])) {
            $errors[] = 'Le fichier doit contenir une colonne « ' . self::COLUMN_EMAIL . ' » — c\'est celle qui '
                . 'porte les adresses email. Les colonnes sont reconnues par leur en-tête, jamais par leur position.';
        }
        if ($errors !== []) {
            throw new ContactGroupImportException($errors);
        }

        return [$found[self::COLUMN_NAME] ?? null, $found[self::COLUMN_EMAIL]];
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
