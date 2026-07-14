<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\DeskImportService;
use Core\Import\FunctionRepository;
use Core\Import\ImportException;
use Core\Import\ImportJournalRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

class ImportController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private DeskImportService $importService,
        private ScoutYearResolver $scoutYearResolver,
        private ImportJournalRepository $importJournalRepo,
        private FunctionRepository $functionRepo,
        private string $storagePath
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
            'unconfirmed_count' => count($unconfirmed),
            'import_result' => null,
        ]);
    }

    /**
     * POST /admin/import — handle the CSV upload and import.
     *
     * @param array<string, string> $params
     */
    public function import(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
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
        $tempPath = $tempDir . '/' . uniqid('import_') . '.csv';
        move_uploaded_file($file['tmp_name'], $tempPath);

        $importedBy = AuthSession::getUserAccountId() ?? 0;

        try {
            $result = $this->importService->import($tempPath, $scoutYearId, $importedBy);
        } catch (ImportException $e) {
            FlashMessage::set('error', $e->getMessage());
            // Clean up temp file on error
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            return $this->redirect('/admin/import');
        }

        $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
        $years = $this->scoutYearResolver->listYears();
        $journals = $this->importJournalRepo->findByYear($scoutYearId);
        $lastImport = count($journals) > 0 ? $journals[0] : null;
        $unconfirmed = $this->functionRepo->findUnconfirmed();

        return $this->render('admin/import.html.twig', [
            'current_year' => $currentYear,
            'years' => $years,
            'last_import' => $lastImport,
            'unconfirmed_count' => count($unconfirmed),
            'import_result' => $result,
        ]);
    }
}
