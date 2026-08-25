<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\FileRepository;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\DeskImportService;
use Core\Import\FunctionRepository;
use Core\Import\ImportException;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportReportPresenter;
use Core\Import\ImportRetentionService;
use Core\Import\RosterReplacementRefusedException;
use Core\Import\RosterSnapshotRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\UserAccountRepository;
use Modules\Registration\Api\ReconciliationTrigger;
use Twig\Environment;

class ImportController extends AbstractController
{
    /**
     * Typed server-side to carry an import through the roster-replacement
     * barrier ({@see \Core\Import\RosterReplacementGuard}). The word says
     * what is about to happen — the roster is replaced — rather than
     * asking for agreement in the abstract; a generic « êtes-vous sûr ? »
     * is a thing people learn to click.
     *
     * Same mechanism as Maintenance's danger zone (REINITIALISER /
     * EFFACER / RESTAURER, `MaintenanceController`): the check that
     * counts is this one, on the server. Whatever the browser does with
     * the submit button is a convenience, never the gate.
     */
    private const KEYWORD_REPLACE_ROSTER = 'REMPLACER';

    public function __construct(
        protected Environment $twig,
        private DeskImportService $importService,
        private ScoutYearResolver $scoutYearResolver,
        private ImportJournalRepository $importJournalRepo,
        private FunctionRepository $functionRepo,
        private ImportRetentionService $retentionService,
        private RosterSnapshotRepository $rosterSnapshotRepo,
        private FileRepository $fileRepository,
        private UserAccountRepository $userAccountRepository,
        private ImportReportPresenter $reportPresenter,
        private string $storagePath,
        private ?ReconciliationTrigger $registrationReconciliation = null
    ) {
    }

    /**
     * GET /admin/import — render the import page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
        $years = $this->scoutYearResolver->listYears();
        $journals = $this->importJournalRepo->findByYear($currentYear['id']);
        $lastImport = count($journals) > 0 ? $journals[0] : null;
        $unconfirmed = $this->functionRepo->findUnconfirmed();

        return $this->render('admin/import.html.twig', [
            'current_year' => $currentYear,
            'years' => $years,
            'last_import' => $lastImport,
            'last_import_author' => $lastImport !== null ? $this->authorName($lastImport['user_account_id'] ?? null) : null,
            'unconfirmed_count' => count($unconfirmed),
            'retention_years' => $this->retentionService->retentionYears(),
            'import_result' => null,
        ]);
    }

    /**
     * GET /admin/import/historique — the year's imports, each with its
     * counters and its kept file.
     *
     * @param array<string, string> $params
     */
    public function history(Request $request, array $params): Response
    {
        $years = $this->scoutYearResolver->listYears();
        $requested = (int) $request->getQuery('annee', '0');
        $selectedYear = null;
        foreach ($years as $year) {
            if ($year['id'] === $requested) {
                $selectedYear = $year;
                break;
            }
        }
        $selectedYear ??= $this->scoutYearResolver->getCurrentPublicYear();

        $imports = $this->importJournalRepo->findRecordsByYear((int) $selectedYear['id']);

        $rows = [];
        $totalBytes = 0;
        foreach ($imports as $import) {
            $fileRecord = $import->fileId !== null ? $this->fileRepository->findById($import->fileId) : null;
            $totalBytes += $fileRecord !== null ? $fileRecord->sizeBytes : 0;
            $rows[] = [
                'import' => $import,
                'author' => $this->authorName($import->userAccountId),
                'file' => $fileRecord,
                'snapshot' => $this->rosterSnapshotRepo->findByImport($import->id),
                // The season's first import has no predecessor: 255 members
                // arriving is the starting point, not a movement.
                'first_of_season' => $this->importJournalRepo->findPreviousInYear($import->scoutYearId, $import->id) === null,
            ];
        }

        // Which seasons the retention has already taken — stated rather
        // than left as an unexplained gap in the year picker.
        $purgedYears = [];
        foreach ($this->retentionService->yearsBeyondRetention() as $purgedYearId) {
            foreach ($years as $year) {
                if ($year['id'] === $purgedYearId) {
                    $purgedYears[] = $year;
                }
            }
        }

        return $this->render('admin/import_history.html.twig', [
            'years' => $years,
            'selected_year' => $selectedYear,
            'rows' => $rows,
            'total_bytes' => $totalBytes,
            'retention_years' => $this->retentionService->retentionYears(),
            'purged_years' => $purgedYears,
        ]);
    }

    /**
     * GET /admin/import/{id}/rapport — what one import did, as it did it.
     *
     * Reads the frozen diff and nothing else. There are no attention
     * points here: those are a current state of the unit and live on
     * their own page. A report that mixed the two would be a page whose
     * top half is dated and whose bottom half is not.
     *
     * @param array<string, string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $import = $this->importJournalRepo->findById((int) ($params['id'] ?? 0));
        if ($import === null) {
            return $this->notFound();
        }

        $years = $this->scoutYearResolver->listYears();
        $scoutYear = null;
        foreach ($years as $year) {
            if ($year['id'] === $import->scoutYearId) {
                $scoutYear = $year;
                break;
            }
        }

        $diff = $this->importJournalRepo->findDiff($import->id);
        $previous = $diff?->previousImportId !== null
            ? $this->importJournalRepo->findById($diff->previousImportId)
            : null;

        return $this->render('admin/import_report.html.twig', [
            'import' => $import,
            'scout_year' => $scoutYear,
            'author' => $this->authorName($import->userAccountId),
            'file' => $import->fileId !== null ? $this->fileRepository->findById($import->fileId) : null,
            'previous_import' => $previous,
            // A row written before diffs existed carries none at all —
            // distinct from an import that had nothing to compare
            // against, which stores an explicitly unavailable one.
            'report' => $diff !== null ? $this->reportPresenter->present($import, $diff) : null,
        ]);
    }

    /**
     * The importer's readable name, decrypted one row at a time for the
     * handful a screen shows. Null when the account is gone — an import is
     * kept longer than the account that ran it may be.
     */
    private function authorName(?int $userAccountId): ?string
    {
        if ($userAccountId === null) {
            return null;
        }

        $account = $this->userAccountRepository->findById($userAccountId);
        if ($account === null) {
            return null;
        }

        $name = trim(($account->firstName ?? '') . ' ' . ($account->lastName ?? ''));

        return $name !== '' ? $name : $account->email;
    }

    /**
     * POST /admin/import — handle the CSV upload and import.
     *
     * @param array<string, string> $params
     */
    public function import(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/import')) !== null) {
            return $guard;
        }

        $scoutYearId = (int) $request->getBody('scout_year_id', '0');
        if ($scoutYearId === 0) {
            FlashMessage::set('error', 'Année scoute invalide.');
            return $this->redirect('/admin/import');
        }

        // Validate file upload
        $file = $_FILES['csv_file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            FlashMessage::set('error', 'Veuillez sélectionner un fichier CSV valide.');
            return $this->redirect('/admin/import');
        }

        // Validate file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            FlashMessage::set('error', 'Le fichier doit être au format CSV.');
            return $this->redirect('/admin/import');
        }

        // Validate file size (10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            FlashMessage::set('error', 'Le fichier dépasse la taille maximale de 10 Mo.');
            return $this->redirect('/admin/import');
        }

        // Save to temp
        $tempDir = $this->storagePath . '/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempPath = $tempDir . '/import_' . bin2hex(random_bytes(16)) . '.csv';
        move_uploaded_file($file['tmp_name'], $tempPath);

        $importedBy = AuthSession::getUserAccountId() ?? 0;

        $confirmed = strtoupper(trim((string) $request->getBody('confirm_keyword', ''))) === self::KEYWORD_REPLACE_ROSTER;

        // The deposited file is the only moment this CSV exists in clear on
        // disk, and it is the densest personal-data artefact the site ever
        // holds (SECURITY.md §13). The window closes here, in a `finally`,
        // on every path out of this block — success, refusal, unreadable
        // file, or a crash three layers down. The kept copy the import
        // registers is encrypted; the plaintext never survives the request.
        try {
            $result = $this->importService->import($tempPath, $scoutYearId, $importedBy, $confirmed);
        } catch (RosterReplacementRefusedException $e) {
            // Nothing was written, and nothing is kept: a refused import
            // registers no encrypted copy either, so it leaves no trace of
            // the unit's personal data behind. Forcing the import
            // therefore means depositing the file again — the barrier
            // screen says so.
            $years = $this->scoutYearResolver->listYears();
            $targetYear = null;
            foreach ($years as $year) {
                if ($year['id'] === $scoutYearId) {
                    $targetYear = $year;
                    break;
                }
            }

            return $this->render('admin/import_barrier.html.twig', [
                'assessment' => $e->assessment,
                'scout_year_id' => $scoutYearId,
                'scout_year' => $targetYear,
                'confirmation_word' => self::KEYWORD_REPLACE_ROSTER,
            ]);
        } catch (ImportException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/admin/import');
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        // Optional module hook (ARCHITECTURE.md §7.5) — confronts every
        // 'accepted' registration request for this year against the
        // members just imported. A no-op when the registration module is
        // disabled ($registrationReconciliation is null then).
        $this->registrationReconciliation?->reconcileForYear($scoutYearId);

        $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
        $years = $this->scoutYearResolver->listYears();
        $journals = $this->importJournalRepo->findByYear($scoutYearId);
        $lastImport = count($journals) > 0 ? $journals[0] : null;
        $unconfirmed = $this->functionRepo->findUnconfirmed();

        return $this->render('admin/import.html.twig', [
            'current_year' => $currentYear,
            'years' => $years,
            'last_import' => $lastImport,
            'last_import_author' => $lastImport !== null ? $this->authorName($lastImport['user_account_id'] ?? null) : null,
            'unconfirmed_count' => count($unconfirmed),
            'retention_years' => $this->retentionService->retentionYears(),
            'import_result' => $result,
        ]);
    }
}
