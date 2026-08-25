<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Journal\JournalService;
use Core\Member\SectionService;
use Modules\Fees\Invoice\InvoiceProblem;
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
        private JournalService $journal
    ) {
    }

    public function import(string $pdfContent, int $scoutYearId, ?int $importedBy): InvoiceImportOutcome
    {
        $reading = $this->reader->read($pdfContent);
        if (!$reading->isAccepted()) {
            $this->journal->log(
                'fees',
                'fees_invoice_refused',
                'info',
                "Facture refusée : la lecture ne retombe pas sur son total.",
                ['scout_year_id' => $scoutYearId, 'problems' => count($reading->problems)],
                $importedBy
            );

            return InvoiceImportOutcome::refused($reading->problems, $reading->ignoredRowCount);
        }

        $invoice = $reading->invoice;
        \assert($invoice !== null);

        $existing = $invoice->documentNumber === null
            ? null
            : $this->invoices->findByDocumentNumber($scoutYearId, $invoice->documentNumber);
        if ($existing !== null) {
            return InvoiceImportOutcome::alreadyImported($existing->id, $existing->documentNumber);
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
                "Le numéro de la facture n'a pas été trouvé : sans lui, le site ne peut pas reconnaître un document déjà importé."
            )], $invoice->ignoredRowCount);
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
                'invoice_id' => $invoiceId,
                'line_count' => count($invoice->lines),
                'ignored_rows' => $invoice->ignoredRowCount,
            ],
            $importedBy
        );

        return InvoiceImportOutcome::imported($invoiceId, $invoice->documentNumber, $invoice->ignoredRowCount);
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
     * federation billed. Falling back to the latest one when the invoice
     * predates every snapshot is deliberate — the verification report then
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
