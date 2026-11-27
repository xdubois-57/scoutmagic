<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Config\ScoutYearService;
use Core\Service\DateInput;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Modules\Fees\Invoice\InvoiceProblem;
use Modules\Fees\Invoice\InvoiceReadResult;
use Modules\Fees\Invoice\InvoiceReader;
use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Repository\InvoiceMemberMatchRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Value\InvoiceImportOutcome;
use Core\Import\RosterSnapshot;

/**
 * Reads a federation invoice and, only if everything holds, keeps it.
 *
 * **Nothing is stored until the three arithmetic checks pass.** There is no
 * partial import and no "imported with warnings": a document whose total
 * does not fall on the centime is not a document anything can be checked
 * against, and half of it in the database would be worse than none.
 *
 * The two failures are told apart on purpose, because they need different
 * answers from different people
 * ({@see \Modules\Fees\Value\InvoiceImportOutcome}). A section code the
 * site does not know is **not** a mapping somebody forgot to enter — it is
 * a roster that has not been re-imported since Desk moved on, and the only
 * useful control is a button that imports Desk.
 *
 * An unknown tariff reference is a different matter and blocks nothing: it
 * simply leaves the category check off on its own line, which the
 * verification report says out loud rather than passing over.
 */
class InvoiceImportService
{
    public function __construct(
        private InvoiceReader $reader,
        private InvoiceRepository $invoices,
        private InvoiceMemberMatchRepository $memberMatches,
        private RosterSnapshotRepository $snapshots,
        private SectionService $sections,
        private JournalService $journal,
        // Which scout year an issue date falls in, and the labels the
        // screen names (issue #1468). Null only where a test builds the
        // service for something else: the year is then not checked.
        private ?ScoutYearService $scoutYears = null
    ) {
    }

    /**
     * A refused reading, said so that the support can reconstruct it
     * without the PDF (#1327): where it stopped, the stable codes of what
     * failed, and the reading's statistics — counts and yes/no, line
     * numbers at most. Never a word of the document: no name, no date of
     * birth, no reference that identifies a person, no line of text, no
     * exception message. The problems' own sentences, which name a tariff
     * line and its section, stay on the screen.
     */
    private function journalRefusal(InvoiceReadResult $reading, int $scoutYearId, ?int $importedBy): void
    {
        $kinds = array_values(array_unique(array_map(
            static fn(InvoiceProblem $problem): string => $problem->kind,
            $reading->problems
        )));
        $stages = array_values(array_unique(array_map(
            static fn(InvoiceProblem $problem): string => $problem->stage(),
            $reading->problems
        )));
        $unreadable = array_filter(
            $reading->problems,
            static fn(InvoiceProblem $problem): bool => $problem->isFormatProblem()
        ) !== [];
        $ambiguous = array_any(
            $reading->problems,
            static fn(InvoiceProblem $problem): bool => $problem->kind === InvoiceProblem::AMBIGUOUS_REPETITION
        );

        $this->journal->log(
            'fees',
            'fees_invoice_refused',
            'info',
            match (true) {
                $unreadable => 'Facture refusée : son format n\'a pas pu être interprété.',
                $ambiguous => 'Facture refusée : des lignes identiques sans liste nominative restent ambiguës.',
                default => 'Facture refusée : la lecture ne retombe pas sur son total.',
            },
            [
                'scout_year_id' => $scoutYearId,
                'stage' => $stages[0] ?? 'recognition',
                'codes' => $kinds,
                'problems' => count($reading->problems),
                'reading' => $reading->stats->toArray(),
            ],
            $importedBy
        );
    }

    /**
     * @param int|null $confirmedYearId the year the treasurer confirmed for
     *        a document whose issue date falls in another one — honoured
     *        only when it names $scoutYearId, so a confirmation given for
     *        one year never files a document in another
     * @param string|null $confirmedDocumentNumber the number of the document
     *        that confirmation was asked about — honoured only for that
     *        document, so a confirmation sent back with another PDF
     *        attached never files the other one unasked (#1548 review)
     */
    public function import(
        string $pdfContent,
        int $scoutYearId,
        ?int $importedBy,
        ?int $confirmedYearId = null,
        ?string $confirmedDocumentNumber = null
    ): InvoiceImportOutcome {
        $reading = $this->reader->read($pdfContent);
        if (!$reading->isAccepted()) {
            $this->journalRefusal($reading, $scoutYearId, $importedBy);

            return InvoiceImportOutcome::refused($reading->problems, $reading->ignoredRowCount);
        }

        $invoice = $reading->invoice;
        \assert($invoice !== null);

        // One import of a given document at a time, from the lookup across
        // years to the write (#1548 review): otherwise two imports of the
        // same PDF into two different years both find nothing and both
        // store it. The loser is told to retry, and finds the winner then.
        $documentNumber = $invoice->documentNumber;
        if ($documentNumber !== null && !$this->invoices->lockDocumentNumber($documentNumber)) {
            return InvoiceImportOutcome::importInProgress($documentNumber);
        }
        try {
            $yearConfirmed = $confirmedYearId === $scoutYearId
                && $documentNumber !== null
                && $confirmedDocumentNumber === $documentNumber;

            return $this->importRead($invoice, $scoutYearId, $importedBy, $yearConfirmed);
        } finally {
            if ($documentNumber !== null) {
                $this->invoices->unlockDocumentNumber($documentNumber);
            }
        }
    }

    private function importRead(
        ParsedInvoice $invoice,
        int $scoutYearId,
        ?int $importedBy,
        bool $yearConfirmedByTreasurer
    ): InvoiceImportOutcome {

        // A document number identifies one document whatever year is on
        // screen (issue #1468): the same PDF imported while looking at
        // another year used to become a second invoice in a second season.
        $existing = $invoice->documentNumber === null
            ? null
            : $this->invoices->findByDocumentNumberInAnyYear($invoice->documentNumber);
        if ($existing !== null && $existing->scoutYearId === $scoutYearId) {
            return InvoiceImportOutcome::alreadyImported($existing->id, $existing->documentNumber);
        }
        if ($existing !== null) {
            // Ids only: a document number identifies a family's bill
            // (ARCHITECTURE.md §8.6).
            $this->journal->log(
                'fees',
                'invoice_import_duplicate_other_year',
                'warning',
                'Facture déjà importée dans une autre année scoute : rien n\'a été enregistré.',
                [
                    'existing_invoice_id' => $existing->id,
                    'existing_year_id' => $existing->scoutYearId,
                    'target_year_id' => $scoutYearId,
                ],
                $importedBy
            );

            return InvoiceImportOutcome::alreadyImportedInAnotherYear(
                $existing->id,
                $existing->documentNumber,
                $this->yearLabel($existing->scoutYearId)
            );
        }

        [$sectionIdsByCode, $unknown] = $this->resolveSections($invoice);
        if ($unknown !== []) {
            $this->journal->log(
                'fees',
                'fees_invoice_stale_roster',
                'info',
                'Facture valide refusée : le roster du site est en retard sur Desk.',
                ['scout_year_id' => $scoutYearId, 'unknown_sections' => count($unknown)],
                $importedBy
            );

            return InvoiceImportOutcome::staleRoster(
                $unknown,
                $invoice->ignoredRowCount,
                $invoice->documentNumber,
                $invoice->issueDate,
                $invoice->totalCents
            );
        }

        if ($invoice->documentNumber === null) {
            // Two imports of the same document must not become two
            // invoices, and the document number is the only identity there
            // is. Without one there is nothing to be idempotent on.
            return InvoiceImportOutcome::refused([new InvoiceProblem(
                InvoiceProblem::NO_LINE_FOUND,
                "Le numéro de la facture n'a pas été trouvé : sans lui, le site ne peut pas reconnaître un document "
                    . "déjà importé."
            )], $invoice->ignoredRowCount);
        }

        // The season a document belongs to is the one its issue date falls
        // in, not the one on screen when it was imported (issue #1468). A
        // regularisation can be issued after its season ends, so a
        // mismatch is asked, never refused — and asked before anything is
        // written, but only of a document that could be imported at all:
        // asked of one with no number, or billing sections the site does
        // not know, it would send the treasurer round only to be refused
        // on the way back (#1548 review).
        $issueYear = $this->issueYearOf($invoice->issueDate);
        $yearConfirmed = false;
        if ($issueYear !== null && $issueYear['label'] !== $this->yearLabel($scoutYearId)) {
            if (!$yearConfirmedByTreasurer) {
                \assert($invoice->issueDate !== null);

                return InvoiceImportOutcome::yearToConfirm(
                    $invoice->documentNumber,
                    $invoice->issueDate,
                    $issueYear['label'],
                    $this->yearLabel($scoutYearId),
                    $scoutYearId
                );
            }
            $yearConfirmed = true;
        }

        $index = $this->memberMatches->buildIndex($scoutYearId);
        $memberIdsByPersonKey = [];
        foreach ($invoice->people() as $person) {
            $memberIdsByPersonKey[$person->matchKey()] = $index[$person->matchKey()] ?? null;
        }

        $invoiceId = $this->invoices->store(
            $invoice,
            $scoutYearId,
            $this->snapshotFor($scoutYearId, $invoice->issueDate)?->id,
            $importedBy,
            $sectionIdsByCode,
            $memberIdsByPersonKey
        );

        $this->journal->log(
            'fees',
            'fees_invoice_imported',
            'info',
            'Facture de la fédération importée : ' . count($invoice->lines) . ' ligne(s).',
            [
                'scout_year_id' => $scoutYearId,
                'issue_year_id' => $issueYear['id'] ?? null,
                'year_confirmed' => $yearConfirmed,
                'invoice_id' => $invoiceId,
                'line_count' => count($invoice->lines),
                'ignored_rows' => $invoice->ignoredRowCount,
            ],
            $importedBy
        );

        return InvoiceImportOutcome::imported(
            $invoiceId,
            $invoice->documentNumber,
            $invoice->ignoredRowCount,
            // No date, or one that is not a real day (« 31/02/2026 »):
            // either way the year went unchecked (#1548 review).
            $this->scoutYears !== null && $issueYear === null
        );
    }

    /**
     * The scout year an issue date falls in — its label always, its id
     * when that year exists on this site — or null when the date is not
     * readable or no year service was given.
     *
     * @return array{label: string, id: ?int}|null
     */
    private function issueYearOf(?string $issueDate): ?array
    {
        if ($this->scoutYears === null || $issueDate === null) {
            return null;
        }
        $date = DateInput::iso($issueDate);
        if ($date === null) {
            return null;
        }
        // The year whose own dates hold it, as this site defines them;
        // the usual 1 September rule only for a year not created here.
        foreach ($this->scoutYears->getAll() as $year) {
            if ((string) $year['start_date'] <= $issueDate && $issueDate <= (string) $year['end_date']) {
                return ['label' => (string) $year['label'], 'id' => (int) $year['id']];
            }
        }

        return ['label' => ScoutYearService::labelForDate($date), 'id' => null];
    }

    private function yearLabel(int $scoutYearId): string
    {
        return (string) ($this->scoutYears?->findById($scoutYearId)['label'] ?? $scoutYearId);
    }

    /**
     * Every section the document names, matched on `sections.desk_code`.
     *
     * @return array{array<string, int>, string[]} resolved codes, then the ones this site does not know
     */
    private function resolveSections(ParsedInvoice $invoice): array
    {
        $resolved = [];
        $unknown = [];
        foreach ($invoice->sectionCodes() as $code) {
            $section = $this->sections->findByDeskCode($code);
            if ($section === null) {
                $unknown[] = $code;
                continue;
            }
            $resolved[$code] = (int) $section['id'];
        }

        return [$resolved, $unknown];
    }

    /**
     * The snapshot the invoice will be checked against: the most recent one
     * taken **on or before** the issue date, because that is the roster the
     * federation billed. Falling back to the earliest one when the invoice
     * predates every snapshot is deliberate — it is the closest one after
     * emission, and the verification report then
     * shows the date gap rather than refusing to say anything.
     */
    private function snapshotFor(int $scoutYearId, ?string $issueDate): ?RosterSnapshot
    {
        $snapshots = $this->snapshots->findAllForYear($scoutYearId);
        if ($snapshots === []) {
            return null;
        }

        if ($issueDate !== null) {
            foreach ($snapshots as $snapshot) {
                if ($snapshot->takenAt->format('Y-m-d') <= $issueDate) {
                    return $snapshot;
                }
            }
        }

        return $snapshots[array_key_last($snapshots)];
    }
}
