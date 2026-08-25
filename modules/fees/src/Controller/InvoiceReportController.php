<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Service\InvoiceVerificationService;
use Modules\Fees\Value\NominativeDiscrepancy;
use Modules\Fees\Value\ReconstitutedLine;
use Modules\Fees\Value\StoredInvoice;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Twig\Environment;

/**
 * One invoice, checked against the roster the federation billed.
 *
 * Two tabs, and they answer two different questions: **how many** (the
 * reconstituted lines) and **who** (the nominative discrepancies). Running
 * them together is what produced, in the version this replaced, a screen
 * that said a section was one over and left a treasurer to work out which
 * of thirty children it was.
 *
 * Conforming lines are collapsed by default and counted, because a report
 * whose first screenful is forty rows of "conforme" hides the two that are
 * not.
 */
class InvoiceReportController extends AbstractController
{
    private const PATH = '/admin/fees/factures';

    private const VIEWS = [
        'lignes' => 'Lignes reconstituées',
        'nominatif' => 'Écarts nominatifs',
    ];

    /**
     * The French word for each kind. The ORDER is
     * `NominativeDiscrepancy::ORDER`'s and lives there, so the export
     * cannot end up sorted differently from the screen; this map only
     * names them.
     */
    private const KIND_LABELS = [
        NominativeDiscrepancy::BILLED_BUT_LEAVING => 'Facturé mais parti',
        NominativeDiscrepancy::NOT_ON_INVOICE => 'Membre absent de la facture',
        NominativeDiscrepancy::DIFFERENT_SECTION => 'Section différente',
        NominativeDiscrepancy::DIFFERENT_CATEGORY => 'Catégorie différente',
        NominativeDiscrepancy::BREVET_REDUCTION_MISSING => 'Réduction breveté non appliquée',
    ];

    public function __construct(
        protected Environment $twig,
        private InvoiceRepository $invoices,
        private InvoiceVerificationService $verification,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journal
    ) {
    }

    /**
     * GET /admin/fees/factures/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $invoice = $this->invoiceOr404($params);
        if (!$invoice instanceof StoredInvoice) {
            return $invoice;
        }

        $view = (string) $request->getQuery('vue', 'lignes');
        if (!array_key_exists($view, self::VIEWS)) {
            $view = 'lignes';
        }

        $lines = $this->verification->reconstitutedLines($invoice);
        $discrepancies = $this->verification->nominativeDiscrepancies($invoice);

        return $this->render('@fees/invoice_report.html.twig', [
            'invoice' => $invoice,
            'view' => $view,
            'views' => self::VIEWS,
            'view_counts' => [
                'lignes' => count(array_filter($lines, static fn(ReconstitutedLine $l): bool => !$l->matches())),
                'nominatif' => count($discrepancies),
            ],
            'lines' => $lines,
            'conforming_count' => count(array_filter($lines, static fn(ReconstitutedLine $l): bool => $l->matches())),
            'discrepancies' => $discrepancies,
            'kind_labels' => self::KIND_LABELS,
            'unmatched_people' => $this->verification->unmatchedPeopleCount($invoice),
            'snapshot_gap_days' => $this->verification->snapshotDateGapInDays($invoice),
            'breadcrumb_current' => 'Facture ' . $invoice->documentNumber,
            'breadcrumb_trail' => [['label' => 'Factures de la fédération', 'url' => self::PATH]],
        ]);
    }

    /**
     * GET /admin/fees/factures/{id}/export
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $invoice = $this->invoiceOr404($params);
        if (!$invoice instanceof StoredInvoice) {
            return $invoice;
        }

        $xlsx = $this->buildXlsx($invoice);

        $this->journal->log(
            'fees',
            'fees_invoice_report_exported',
            'info',
            'Export du rapport de vérification d\'une facture',
            ['invoice_id' => $invoice->id, 'scout_year_id' => $invoice->scoutYearId],
            AuthSession::getUserAccountId()
        );

        return (new Response($xlsx))
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="verification-facture.xlsx"')
            ->setHeader('Content-Length', (string) strlen($xlsx));
    }

    /**
     * Two sheets in the column order of the two tabs, so a treasurer
     * reading the export recognises the screen they came from rather than
     * having to map one onto the other.
     */
    private function buildXlsx(StoredInvoice $invoice): string
    {
        $spreadsheet = new Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lignes reconstituées');
        $this->writeRow($sheet, 1, [
            'Référence', 'Description', 'Section', 'P.U. (€)',
            'Quantité facturée', 'Quantité attendue', 'Écart', 'Écart (€)', 'Verdict',
        ]);

        $rowNum = 2;
        foreach ($this->verification->reconstitutedLines($invoice) as $line) {
            $this->writeRow($sheet, $rowNum, [
                $line->reference,
                $line->descriptor,
                $line->sectionLabel ?? '',
                self::euros($line->unitPriceCents),
                (string) $line->billedQuantity,
                $line->expectedQuantity === null ? '' : (string) $line->expectedQuantity,
                $line->difference() === null ? '' : self::signed($line->difference()),
                $line->differenceCents() === null ? '' : self::euros($line->differenceCents()),
                self::lineVerdict($line),
            ]);
            $rowNum++;
        }

        $nominative = $spreadsheet->createSheet();
        $nominative->setTitle('Écarts nominatifs');
        $this->writeRow($nominative, 1, [
            'Type d\'écart', 'Membre', 'Section facturée', 'Section dans Desk',
            'Catégorie facturée', 'Catégorie dans Desk', 'Incidence (€)',
        ]);

        $rowNum = 2;
        foreach ($this->verification->nominativeDiscrepancies($invoice) as $item) {
            $this->writeRow($nominative, $rowNum, [
                self::KIND_LABELS[$item->kind] ?? $item->kind,
                trim($item->firstName . ' ' . $item->lastName),
                $item->billedSectionLabel ?? '',
                $item->rosterSectionLabel ?? '',
                $item->billedCategoryLabel ?? '',
                $item->rosterCategoryLabel ?? '',
                $item->costsNothingByNature()
                    ? 'Sans incidence'
                    : ($item->costCents === null ? '' : self::euros($item->costCents)),
            ]);
            $rowNum++;
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Every cell written explicitly as a string, including the numbers.
     *
     * A reference, a Desk section name or a member's name starting with
     * `=` must not become a live formula in the treasurer's spreadsheet
     * (SECURITY.md §23) — and a per-column exception is how one of them
     * eventually slips through, so there is none.
     *
     * @param string[] $values
     */
    private function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $rowNum, array $values): void
    {
        foreach ($values as $index => $value) {
            $sheet->setCellValueExplicit([$index + 1, $rowNum], $value, DataType::TYPE_STRING);
        }
    }

    private static function lineVerdict(ReconstitutedLine $line): string
    {
        if (!$line->isDetermined()) {
            return match ($line->undeterminedReason) {
                ReconstitutedLine::UNDETERMINED_UNKNOWN_REFERENCE => 'Référence inconnue — non vérifiée',
                ReconstitutedLine::UNDETERMINED_NO_SECTION => 'Sans section — non vérifiée',
                ReconstitutedLine::UNDETERMINED_GLOBAL_ADJUSTMENT => 'Ajustement global — non vérifié',
                ReconstitutedLine::UNDETERMINED_NO_SNAPSHOT => 'Aucune photographie — non vérifiée',
                default => 'Non vérifiée',
            };
        }

        return $line->matches() ? 'Conforme' : 'Écart';
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ');
    }

    private static function signed(int $value): string
    {
        return $value > 0 ? '+' . $value : (string) $value;
    }

    /**
     * @param array<string, string> $params
     * @return StoredInvoice|Response the invoice, or the 404 to return instead
     */
    private function invoiceOr404(array $params): StoredInvoice|Response
    {
        $invoice = $this->invoices->findById((int) ($params['id'] ?? 0));
        // An invoice of another scout year is the same answer as one that
        // does not exist: the year a session is on is the year it reads.
        if ($invoice === null || $invoice->scoutYearId !== $this->effectiveYear()->id) {
            return $this->render('errors/404.html.twig')->setStatusCode(404);
        }

        return $invoice;
    }

    private function effectiveYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        $role = Role::fromString(AuthSession::getRole());

        return $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
    }
}
