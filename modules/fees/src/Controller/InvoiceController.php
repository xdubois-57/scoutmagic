<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Controller;

use Core\Exception\UserFacingMessage;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Fees\Repository\FeesImportRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Service\InvoiceImportService;
use Modules\Fees\Service\InvoiceSeasonService;
use Modules\Fees\Service\InvoiceVerificationService;
use Modules\Fees\Value\InvoiceImportOutcome;
use Modules\Finance\Api\ExpenseReceiptInterface;
use Twig\Environment;

/**
 * The season's invoices, and the screen that adds one to it.
 *
 * The import has three states and **two of them are failures**, told apart
 * on purpose: a file that does not add up is the document's problem, and a
 * section this site has never heard of is the site's. The second one is
 * not an error the treasurer caused, and the screen says so before it says
 * anything else.
 */
class InvoiceController extends AbstractController
{
    private const PATH = '/admin/fees/factures';
    private const IMPORT_PATH = '/admin/fees/factures/import';

    /** 20 MB: a federation invoice is a few pages of text, never a scan of a hundred. */
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        protected Environment $twig,
        private InvoiceImportService $importService,
        private InvoiceSeasonService $season,
        private InvoiceVerificationService $verification,
        private InvoiceRepository $invoices,
        private RosterSnapshotRepository $snapshots,
        private FeesImportRepository $imports,
        private ScoutYearResolver $scoutYearResolver,
        /**
         * The members this login reaches, resolved once in the composition
         * root the way every other consumer of them gets them — never
         * re-derived here, where a second resolution is a second chance to
         * resolve fewer addresses than the rest of the site does.
         *
         * @var int[]
         */
        private array $linkedMemberIds,
        private JournalService $journal,
        /**
         * Optional (ARCHITECTURE.md §7.5): with `finance` disabled the
         * checkbox simply is not offered, the PDF is not kept, and the
         * verification works exactly the same. Never a hard dependency,
         * and never a second encrypted storage path of this module's own.
         */
        private ?ExpenseReceiptInterface $expenseReceipts = null
    ) {
    }

    /**
     * GET /admin/fees/factures — the season, in the order it happened.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $year = $this->effectiveYear();
        $sequence = $this->season->sequence($year->id);

        $rows = [];
        foreach ($sequence as $entry) {
            $rows[] = $entry + ['discrepancies' => $this->verification->countDiscrepancies($entry['invoice'])];
        }

        return $this->render('@fees/invoices.html.twig', [
            'scout_year_label' => $year->label,
            'rows' => $rows,
            'net_total_cents' => $this->season->netTotalCents($year->id),
            'last_import_at' => $this->imports->findLastImportAt($year->id),
        ]);
    }

    /**
     * GET /admin/fees/factures/import — the deposit state.
     *
     * It reminds the date of the last Desk import before the file is even
     * chosen. That reminder is what keeps a treasurer out of the other two
     * states.
     *
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        return $this->render('@fees/invoice_import.html.twig', $this->formContext());
    }

    /**
     * POST /admin/fees/factures/import
     *
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::IMPORT_PATH)) !== null) {
            return $guard;
        }

        $file = $request->getFile('invoice');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->render('@fees/invoice_import.html.twig', $this->formContext([
                'upload_error' => 'Aucun fichier fourni, ou une erreur est survenue pendant le téléversement.',
            ]));
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return $this->render('@fees/invoice_import.html.twig', $this->formContext([
                'upload_error' => 'Ce fichier dépasse la taille maximale autorisée (20 Mo).',
            ]));
        }

        $content = @file_get_contents((string) ($file['tmp_name'] ?? ''));
        if ($content === false) {
            return $this->render('@fees/invoice_import.html.twig', $this->formContext([
                'upload_error' => "Le fichier envoyé n'a pas pu être lu.",
            ]));
        }

        $year = $this->effectiveYear();
        $outcome = $this->importService->import($content, $year->id, AuthSession::getUserAccountId());

        if (!$outcome->isStored()) {
            return $this->render('@fees/invoice_import.html.twig', $this->formContext(['outcome' => $outcome]));
        }

        $this->keepPdfIfAsked($request, $outcome, $content, (string) ($file['name'] ?? 'facture.pdf'));

        FlashMessage::set('success', 'Facture ' . $outcome->documentNumber . ' importée.');

        return $this->redirect(self::PATH);
    }

    /**
     * The PDF is kept as a receipt in Finances, or not at all.
     *
     * A failure here never loses the import: the verification is what the
     * treasurer came for, and the document is still in their downloads.
     */
    private function keepPdfIfAsked(Request $request, InvoiceImportOutcome $outcome, string $content, string $filename): void
    {
        $accountId = (int) $request->getBody('finance_account_id', 0);
        if ($this->expenseReceipts === null || $accountId <= 0 || $outcome->invoiceId === null) {
            return;
        }

        try {
            // Never the client-declared $_FILES type, which is
            // attacker-controlled and would walk straight through the
            // receipt service's MIME allowlist (SECURITY.md, the same
            // reasoning as Finance\Controller\ReceiptController). The
            // parser has already proved this is a PDF with a text layer;
            // finfo is what SAYS so to the storage.
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($content);

            $fileId = $this->expenseReceipts->storeReceipt(
                $content,
                $detected !== false ? $detected : 'application/octet-stream',
                $filename,
                $accountId,
                null,
                null,
                (string) AuthSession::getRole(),
                $this->linkedMemberIds,
                AuthSession::getUserAccountId()
            );
            $this->invoices->attachFinanceFile($outcome->invoiceId, $fileId);
            $this->journal->log(
                'fees',
                'fees_invoice_receipt_kept',
                'info',
                'PDF de la facture conservé comme justificatif dans Finances.',
                ['invoice_id' => $outcome->invoiceId, 'file_id' => $fileId],
                AuthSession::getUserAccountId()
            );
        } catch (\Throwable $e) {
            FlashMessage::set('warning', UserFacingMessage::from(
                $e,
                "La facture a bien été importée, mais le PDF n'a pas pu être conservé dans Finances."
            ));
        }
    }

    /**
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function formContext(array $extra = []): array
    {
        $year = $this->effectiveYear();
        $latestSnapshot = $this->snapshots->findLatestForYear($year->id);

        return $extra + [
            // A real ancestor PAGE, not a menu category (design.md §7.3):
            // the season is where this screen came from and where it
            // returns to, and the nav cannot offer it — the module has one
            // menu entry.
            'breadcrumb_trail' => [['label' => 'Factures de la fédération', 'url' => self::PATH]],
            'scout_year_label' => $year->label,
            'last_import_at' => $this->imports->findLastImportAt($year->id),
            'latest_snapshot' => $latestSnapshot,
            'last_ignored_row_count' => $this->invoices->findLastIgnoredRowCount($year->id),
            'finance_account_options' => $this->financeAccountOptions(),
            'outcome' => null,
            'upload_error' => null,
        ];
    }

    /**
     * The accounts Finances lets this login attach a receipt to, as the
     * form-field partial wants them. Empty whenever finance is disabled,
     * which is what makes the whole control disappear (§7.5) — never an
     * error, and never a checkbox that would fail on submit.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function financeAccountOptions(): array
    {
        $accounts = $this->expenseReceipts?->receiptAccounts(
            (string) AuthSession::getRole(),
            $this->linkedMemberIds
        ) ?? [];
        if ($accounts === []) {
            return [];
        }

        $options = [['value' => '', 'label' => 'Ne pas conserver le PDF']];
        foreach ($accounts as $id => $name) {
            $options[] = ['value' => (string) $id, 'label' => $name];
        }

        return $options;
    }

    private function effectiveYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        $role = Role::fromString(AuthSession::getRole());

        return $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
    }
}
