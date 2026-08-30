<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Controller;

use Core\Config\ScoutYearService;
use Core\Exception\UserFacingMessage;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Service\IntegerInput;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Service\AttestationsException;
use Modules\Attestations\Service\BatchDepositService;
use Modules\Attestations\Service\PageCountMismatchException;
use Modules\Attestations\Value\AttestationCategory;
use Twig\Environment;

/**
 * Depositing a file, and the list of what has been deposited.
 *
 * `role_min: admin` — the same floor as the member sheet and the journal,
 * and never lower. What lands here is the whole unit's nominative paperwork
 * in one document.
 */
class AttestationsController extends AbstractController
{
    public const PATH = '/admin/attestations';

    /**
     * A deposited file is one PDF of a few hundred pages; the site-wide
     * `upload_max_filesize` is 32M (public/.user.ini) and this is well
     * under it. The cap is here as well as there because a file this size
     * is read whole into memory to be parsed, and a ceiling stated in one
     * place is a ceiling nobody can reason about.
     */
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(
        protected Environment $twig,
        private BatchRepository $batches,
        private ScoutYearService $scoutYears,
        private ScoutYearResolver $scoutYearResolver,
        private BatchDepositService $deposits,
        private JournalService $journal,
        private string $storagePath
    ) {
    }

    /**
     * GET /admin/attestations
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@attestations/index.html.twig', $this->pageContext());
    }

    /**
     * POST /admin/attestations — deposit a file.
     *
     * The refusals are rendered rather than flashed-and-redirected: a
     * page-count mismatch has three numbers and a subtraction to show, and
     * a reader who only sees « le découpage a échoué » retries the same
     * file instead of going back to the federation.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH)) !== null) {
            return $guard;
        }

        $scoutYearId = IntegerInput::id($request->getBody('scout_year_id', ''));
        $category = AttestationCategory::tryFromValue((string) $request->getBody('category', ''));
        $label = trim((string) $request->getBody('label', ''));

        if ($scoutYearId === null || $category === null || $label === '') {
            return $this->renderWithError(
                'Choisissez une année scoute, une catégorie et un libellé avant de déposer le fichier.'
            );
        }

        if ($this->scoutYears->findById($scoutYearId) === null) {
            return $this->renderWithError('Cette année scoute n\'existe pas.');
        }

        $upload = $this->validatedUpload();
        if (is_string($upload)) {
            return $this->renderWithError($upload);
        }

        $temporaryPath = $this->moveToTemporaryFile($upload);
        if ($temporaryPath === null) {
            return $this->renderWithError(
                'Le fichier n\'a pas pu être enregistré sur le serveur. Réessayez dans un instant.'
            );
        }

        try {
            $batchId = $this->deposits->deposit(
                $temporaryPath,
                $scoutYearId,
                $category,
                $label,
                AuthSession::getUserAccountId()
            );
        } catch (PageCountMismatchException $e) {
            $this->journalRefusal($e);

            return $this->render('@attestations/index.html.twig', array_merge(
                $this->pageContext(
                    ['scout_year_id' => $scoutYearId, 'category' => $category->value, 'label' => $label]
                ),
                [
                    'refusal' => [
                        'page_count' => $e->pageCount,
                        'pages_per_document' => $e->pagesPerDocument,
                        'remainder' => $e->remainder(),
                    ],
                ]
            ));
        } catch (AttestationsException $e) {
            return $this->renderWithError($e->getMessage(), $scoutYearId, $category->value, $label);
        } catch (\Throwable $e) {
            return $this->renderWithError(
                UserFacingMessage::from($e, 'Le dépôt a échoué. Réessayez dans un instant.'),
                $scoutYearId,
                $category->value,
                $label
            );
        } finally {
            // The deposited file holds every family's certificate in one
            // document and has no use once the pieces are out of it. It
            // goes in a `finally` so a crash cannot leave it on disk —
            // exactly the posture the Desk import takes with its own
            // plaintext window (SECURITY.md §13).
            @unlink($temporaryPath);
        }

        return $this->redirect(self::PATH . '/' . $batchId);
    }

    /**
     * The uploaded file, or the French sentence explaining why it is not
     * acceptable.
     *
     * The declared MIME type is never trusted (SECURITY.md §27): the type
     * is read from the bytes with `finfo`, and a detection failure resolves
     * to a value the allowlist does not hold rather than to whatever the
     * client claimed.
     *
     * @return array{name: string, tmp_name: string, size: int}|string
     */
    private function validatedUpload(): array|string
    {
        /** @var array<string, mixed>|null $file */
        $file = $_FILES['pdf_file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'Choisissez le PDF reçu de la fédération.';
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return 'Ce fichier est vide.';
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return sprintf(
                'Ce fichier dépasse la taille maximale de %d Mo.',
                intdiv(self::MAX_UPLOAD_BYTES, 1024 * 1024)
            );
        }

        $temporaryName = (string) ($file['tmp_name'] ?? '');
        if ($temporaryName === '' || !is_readable($temporaryName)) {
            return 'Ce fichier n\'a pas pu être lu. Réessayez.';
        }

        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($temporaryName);
        if ($detected !== 'application/pdf') {
            return 'Ce fichier n\'est pas un PDF. Déposez le document reçu de la fédération, tel quel.';
        }

        return [
            'name' => (string) ($file['name'] ?? 'attestations.pdf'),
            'tmp_name' => $temporaryName,
            'size' => $size,
        ];
    }

    /**
     * @param array{name: string, tmp_name: string, size: int} $upload
     */
    private function moveToTemporaryFile(array $upload): ?string
    {
        $directory = $this->storagePath . '/temp';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }

        $path = $directory . '/attestations_' . bin2hex(random_bytes(16)) . '.pdf';

        // move_uploaded_file() in the request that received it; a plain
        // rename would accept a path the caller chose.
        if (is_uploaded_file($upload['tmp_name'])) {
            return move_uploaded_file($upload['tmp_name'], $path) ? $path : null;
        }

        // The same path under test, where no request really uploaded
        // anything: is_uploaded_file() is false, and copying is what lets
        // the deposit be exercised end to end. Same shape and same reason
        // as Core\File\UploadHandler::moveFile(). $_FILES['…']['tmp_name']
        // is written by PHP itself and never by the request body, so the
        // fallback reads no path a caller chose.
        return copy($upload['tmp_name'], $path) ? $path : null;
    }

    private function journalRefusal(PageCountMismatchException $e): void
    {
        // Counters only, and at `warning`: this is the guard rail firing,
        // which is worth being able to find again months later.
        $this->journal->log(
            'attestations',
            'attestation_batch_refused',
            'warning',
            sprintf(
                'Découpage refusé : %d pages pour %d pages par attestation.',
                $e->pageCount,
                $e->pagesPerDocument
            ),
            ['page_count' => $e->pageCount, 'pages_per_document' => $e->pagesPerDocument]
        );
    }

    private function renderWithError(
        string $message,
        ?int $scoutYearId = null,
        ?string $category = null,
        string $label = ''
    ): Response {
        FlashMessage::set('error', $message);

        return $this->render(
            '@attestations/index.html.twig',
            $this->pageContext(['scout_year_id' => $scoutYearId, 'category' => $category, 'label' => $label])
        );
    }

    /**
     * @param array<string, mixed> $form what the reader had already typed,
     *                                   so a refusal never costs them the
     *                                   three fields above the file picker
     *
     * @return array<string, mixed>
     */
    private function pageContext(array $form = []): array
    {
        $years = $this->scoutYearResolver->listYears();

        $yearLabels = [];
        foreach ($years as $year) {
            $yearLabels[(int) $year['id']] = (string) $year['label'];
        }

        $form = $form + ['scout_year_id' => null, 'category' => null, 'label' => ''];

        // The public year is a default, never a decision: the site deduces
        // no date of its own, and a tax certificate covering the year just
        // gone is routinely filed under the one in progress.
        $selectedYear = $form['scout_year_id'] ?? $this->scoutYearResolver->getPublicYearId();

        return [
            'batches' => $this->batches->findRecent(),
            'year_labels' => $yearLabels,
            // Built here rather than through AbstractController::options(),
            // whose label map is keyed by value: a numeric-string key is
            // silently an int key in PHP, so a year id could never be one.
            'year_options' => array_map(
                static fn(array $year): array => [
                    'value' => (string) $year['id'],
                    'label' => (string) $year['label'],
                    'selected' => (int) $year['id'] === $selectedYear,
                ],
                $years
            ),
            'category_options' => $this->options(
                AttestationCategory::labels(),
                (string) ($form['category'] ?? AttestationCategory::Tax->value)
            ),
            'refusal' => null,
            'form' => $form,
        ];
    }
}
