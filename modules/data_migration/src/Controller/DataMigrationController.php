<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Controller;

use Core\Exception\UserFacingMessage;
use Core\File\UploadException;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\FormSourceImporter;
use Modules\DataMigration\Importer\Importer;
use Modules\DataMigration\Importer\ImporterRegistry;
use Modules\DataMigration\Importer\PreviewNote;
use Modules\DataMigration\Importer\SourceRejectedException;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\RunService;
use Twig\Environment;

/**
 * « Migration de données » — the home page and the one shared page every
 * import runs on (chantier « Migration de données », lot A).
 *
 * Every route is `role_min: admin`. Every route that names an import asks
 * {@see ImporterRegistry::find()} first, and an import whose module is off
 * is a 404 — at its address, at its AJAX endpoints and for every slice,
 * not only on the home page (spec §2.1).
 */
class DataMigrationController extends AbstractController
{
    public const PATH = '/admin/migration-donnees';

    /**
     * @param array<string, string> $moduleNames enabled module id => its name
     */
    public function __construct(
        protected Environment $twig,
        private ImporterRegistry $importers,
        private RunService $runs,
        private array $moduleNames = []
    ) {
    }

    /**
     * GET /admin/migration-donnees
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $rows = [];
        $interrupted = null;
        foreach ($this->importers->available() as $importer) {
            $run = $this->runs->current($importer);
            $rows[] = [
                'key' => $importer->key(),
                'title' => $importer->title(),
                'description' => $importer->description(),
                'url' => self::PATH . '/' . $importer->key(),
                'status' => $this->homeStatus($run),
                'resume' => $run !== null && $run->state === Run::INTERRUPTED,
            ];
            if ($interrupted === null && $run !== null && $run->state === Run::INTERRUPTED) {
                $interrupted = [
                    'title' => $importer->title(),
                    'percent' => self::executionPercent($run),
                    'imported' => (int) ($run->report['imported'] ?? 0),
                    'unit' => $importer->unitLabel(),
                    'url' => self::PATH . '/' . $importer->key(),
                ];
            }
        }

        return $this->render('@data_migration/index.html.twig', [
            'importers' => $rows,
            'interrupted' => $interrupted,
        ]);
    }

    /**
     * GET /admin/migration-donnees/{importer} — the shared six-step page.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $importer = $this->importers->find((string) ($params['importer'] ?? ''));
        if ($importer === null) {
            return $this->notFound();
        }

        $run = $this->runs->current($importer);
        if ($request->getQuery('nouveau') !== null && $run !== null && !in_array($run->state, [Run::RUNNING], true)) {
            // « Choisir un autre fichier »: the source step, the current
            // run left as it is until a new source replaces it.
            $run = null;
        }

        return $this->render('@data_migration/importer.html.twig', $this->pageContext($importer, $run));
    }

    /**
     * POST /admin/migration-donnees/{importer}/depot (AJAX) — one fragment
     * of the archive (Core\File\ChunkedUploadStore, SECURITY.md §29). With
     * no file and `probe=1`, answers how many bytes this session already
     * sent under that id, so a page reopened resumes the deposit.
     *
     * @param array<string, string> $params
     */
    public function uploadChunk(Request $request, array $params): Response
    {
        $importer = $this->importers->find((string) ($params['importer'] ?? ''));
        if ($importer === null || $importer->archiveFormat() === null) {
            return $this->json(['success' => false, 'error' => 'Import introuvable.'], 404);
        }
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }

        $uploadId = (string) $request->getBody('upload_id', '');
        $chunk = $request->getFile('file');
        if ($chunk === null || empty($chunk['tmp_name'])) {
            if ((string) $request->getBody('probe', '') === '1') {
                return $this->json(['success' => true, 'received' => $this->runs->receivedBytes($uploadId, session_id())]);
            }

            return $this->json(['success' => false, 'error' => 'Aucun fragment envoyé.'], 400);
        }

        try {
            $result = $this->runs->receiveChunk(
                $importer,
                $uploadId,
                session_id(),
                (int) $request->getBody('chunk_offset', '0'),
                (string) $chunk['tmp_name'],
                (string) $request->getBody('last', '0') === '1',
                (string) $request->getBody('file_name', ''),
                AuthSession::getUserAccountId()
            );
        } catch (UploadException $e) {
            return $this->json([
                'success' => false,
                'error' => UserFacingMessage::from($e, 'Ce fragment du fichier n\'a pas pu être enregistré. Relancez l\'envoi.'),
                'received' => $this->runs->receivedBytes($uploadId, session_id()),
            ], 409);
        }

        return $this->json(['success' => true, 'received' => $result['received'], 'complete' => $result['run'] !== null]);
    }

    /**
     * POST /admin/migration-donnees/{importer}/source — step 1 of an import
     * whose source is a form (the ICS calendar).
     *
     * @param array<string, string> $params
     */
    public function submitSource(Request $request, array $params): Response
    {
        $importer = $this->importers->find((string) ($params['importer'] ?? ''));
        if (!$importer instanceof FormSourceImporter) {
            return $this->notFound();
        }
        $page = self::PATH . '/' . $importer->key();
        if (($guard = $this->guardCsrf($request, $page)) !== null) {
            return $guard;
        }

        try {
            $this->runs->acceptForm($importer, $request->getBodyAll(), AuthSession::getUserAccountId());
        } catch (SourceRejectedException | UploadException $e) {
            FlashMessage::set('error', UserFacingMessage::from($e, 'La source n\'a pas pu être lue.'));

            return $this->redirect($page . '?nouveau=1');
        }

        return $this->redirect($page);
    }

    /**
     * POST /admin/migration-donnees/{importer}/avancer (AJAX) — one slice
     * of about five seconds, the page's poll driving the work.
     *
     * @param array<string, string> $params
     */
    public function advance(Request $request, array $params): Response
    {
        $importer = $this->importers->find((string) ($params['importer'] ?? ''));
        if ($importer === null) {
            return $this->json(['success' => false, 'error' => 'Import introuvable.'], 404);
        }
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }

        $run = $this->runs->current($importer);
        if ($run === null) {
            return $this->json(['success' => false, 'error' => 'Aucun import en cours.'], 404);
        }

        return $this->json(['success' => true] + self::progressPayload($this->runs->advance($importer, $run)));
    }

    /**
     * POST /admin/migration-donnees/{importer}/confirmer — step 4.
     *
     * @param array<string, string> $params
     */
    public function confirm(Request $request, array $params): Response
    {
        return $this->act($request, $params, function (Importer $importer, Run $run, Request $request): void {
            $years = $request->getBody('years', []);
            $chosen = is_array($years) ? array_values(array_filter($years, 'is_string')) : [];
            if ($this->runs->confirm($run, $chosen) === null) {
                FlashMessage::set('warning', 'Cet import n\'attend plus de confirmation.');
            }
        });
    }

    /**
     * POST /admin/migration-donnees/{importer}/pause
     *
     * @param array<string, string> $params
     */
    public function pause(Request $request, array $params): Response
    {
        return $this->act($request, $params, function (Importer $importer, Run $run): void {
            $this->runs->pause($run);
        });
    }

    /**
     * POST /admin/migration-donnees/{importer}/reprendre
     *
     * @param array<string, string> $params
     */
    public function resume(Request $request, array $params): Response
    {
        return $this->act($request, $params, function (Importer $importer, Run $run): void {
            $this->runs->resume($run);
        });
    }

    /**
     * POST /admin/migration-donnees/{importer}/abandonner — D9.
     *
     * @param array<string, string> $params
     */
    public function abandon(Request $request, array $params): Response
    {
        return $this->act($request, $params, function (Importer $importer, Run $run): void {
            if ($run->isActive()) {
                $this->runs->abandon($run);
                FlashMessage::set('success', 'Import abandonné. Ce qui était déjà importé est conservé, le fichier est effacé.');
            }
        });
    }

    /**
     * POST /admin/migration-donnees/{importer}/relancer
     *
     * @param array<string, string> $params
     */
    public function restart(Request $request, array $params): Response
    {
        return $this->act($request, $params, function (Importer $importer, Run $run): void {
            if ($this->runs->restart($importer, $run, AuthSession::getUserAccountId()) === null) {
                FlashMessage::set('warning', 'Le fichier n\'est plus sur le serveur. Déposez-le à nouveau.');
            }
        });
    }

    /**
     * The four form actions share their guards: the import exists, the
     * token is valid, there is a run to act on; then back to the page.
     *
     * @param array<string, string> $params
     * @param \Closure(Importer, Run, Request): void $action
     */
    private function act(Request $request, array $params, \Closure $action): Response
    {
        $importer = $this->importers->find((string) ($params['importer'] ?? ''));
        if ($importer === null) {
            return $this->notFound();
        }
        $page = self::PATH . '/' . $importer->key();
        if (($guard = $this->guardCsrf($request, $page)) !== null) {
            return $guard;
        }

        $run = $this->runs->current($importer);
        if ($run !== null) {
            $action($importer, $run, $request);
        }

        return $this->redirect($page);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageContext(Importer $importer, ?Run $run): array
    {
        $format = $importer->archiveFormat();
        $module = $importer->requiredModule();
        $step = self::step($run);
        $base = self::PATH . '/' . $importer->key();

        $context = [
            'importer' => [
                'key' => $importer->key(),
                'title' => $importer->title(),
                'description' => $importer->description(),
                'unit' => $importer->unitLabel(),
                'module_name' => $module !== null ? ($this->moduleNames[$module] ?? $module) : null,
                'is_form' => $importer instanceof FormSourceImporter,
                'max_bytes' => $format?->maxArchiveBytes,
                'effects' => $importer->effects(),
            ],
            'urls' => [
                'page' => $base,
                'upload' => $base . '/depot',
                'source' => $base . '/source',
                'advance' => $base . '/avancer',
                'confirm' => $base . '/confirmer',
                'pause' => $base . '/pause',
                'resume' => $base . '/reprendre',
                'abandon' => $base . '/abandonner',
                'restart' => $base . '/relancer',
                'new' => $base . '?nouveau=1',
                'home' => self::PATH,
            ],
            'step' => $step,
            'run' => $run === null ? null : [
                'state' => $run->state,
                'file_name' => $run->fileName,
                'file_size' => $run->fileSize,
                'format_version' => $run->formatVersion,
                'installation_id' => $run->installationId,
                'exported_on' => self::exportedOn($run->exportedAt),
                'rejection_reason' => $run->rejectionReason,
                'finished_at' => $run->finishedAt,
                'has_file' => $run->filePath !== null || $run->sourceParameters !== [],
                'file_expires_at' => $this->runs->fileExpiresAt($run)?->format('Y-m-d H:i:s'),
            ],
            'progress' => $run !== null ? self::progressPayload($run) : null,
            'breadcrumb_current' => $importer->title(),
        ];

        if ($importer instanceof FormSourceImporter && $step === 'source') {
            $context['source_form'] = [
                'template' => $importer->sourceFormTemplate(),
                'context' => $importer->sourceFormContext((int) AuthSession::getUserAccountId()),
            ];
        }

        if ($run !== null && $run->state === Run::ANALYSED) {
            $context['preview'] = $this->preview($importer, $run);
        }
        if ($run !== null && (
            in_array($run->state, [Run::RUNNING, Run::INTERRUPTED, Run::CONFIRMED, Run::DONE], true)
            || ($run->state === Run::ABANDONED && $run->report !== [])
        )) {
            $context['report'] = self::reportView($run);
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(Importer $importer, Run $run): array
    {
        $analysis = $run->analysis;
        $ignored = array_map('intval', (array) ($analysis['ignored'] ?? []));
        arsort($ignored);
        $missing = array_map('intval', (array) ($analysis['missing_years'] ?? []));
        ksort($missing);

        $summary = new AnalysisSummary(
            (int) ($analysis['total'] ?? 0),
            (int) ($analysis['to_import'] ?? 0),
            (int) ($analysis['present'] ?? 0),
            (int) ($analysis['ambiguous'] ?? 0),
            $ignored,
            array_map('intval', (array) ($analysis['tags'] ?? [])),
            $missing
        );

        return [
            'to_import' => $summary->toImport,
            'present' => $summary->present,
            'ambiguous' => $summary->ambiguous,
            'ignored' => $summary->ignored(),
            'errors' => (int) ($analysis['errors'] ?? 0),
            'ignored_reasons' => $ignored,
            'samples' => array_values((array) ($analysis['samples'] ?? [])),
            'missing_years' => $missing,
            'notes' => array_map(
                static fn (PreviewNote $note): array => ['level' => $note->level, 'text' => $note->text],
                $importer->previewNotes($summary)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reportView(Run $run): array
    {
        $report = $run->report;
        $ignored = array_map('intval', (array) ($report['ignored'] ?? []));
        arsort($ignored);

        return [
            'imported' => (int) ($report['imported'] ?? 0),
            'present' => (int) ($report['present'] ?? 0),
            'ignored' => array_sum($ignored),
            'errors' => (int) ($report['errors'] ?? 0),
            'ignored_reasons' => $ignored,
            'samples' => array_values((array) ($report['samples'] ?? [])),
            'error_samples' => array_values((array) ($report['error_samples'] ?? [])),
            'to_import' => (int) ($run->analysis['to_import'] ?? 0),
            'total' => (int) ($run->analysis['total'] ?? 0),
            'percent' => self::executionPercent($run),
        ];
    }

    /**
     * What the page's poll needs to redraw the bar, and when to reload.
     *
     * @return array<string, mixed>
     */
    private static function progressPayload(Run $run): array
    {
        $phase = $run->phase();
        $percent = null;
        if ($run->state === Run::UPLOADED && $phase === ImportEngine::PHASE_VERIFY) {
            $steps = (int) ($run->progress['steps'] ?? 0);
            $percent = $steps > 0 ? (int) floor(100 * $run->position() / $steps) : 0;
        } elseif (in_array($run->state, [Run::CONFIRMED, Run::RUNNING, Run::INTERRUPTED, Run::DONE], true)) {
            $percent = self::executionPercent($run);
        }

        return [
            'state' => $run->state,
            'phase' => $phase,
            'position' => $run->position(),
            'percent' => $percent,
            'imported' => (int) ($run->report['imported'] ?? 0),
            'present' => (int) ($run->report['present'] ?? 0),
            'ignored' => array_sum(array_map('intval', (array) ($run->report['ignored'] ?? []))),
            'errors' => (int) ($run->report['errors'] ?? 0),
            'analysed' => (int) ($run->analysis['total'] ?? 0),
        ];
    }

    private static function executionPercent(Run $run): int
    {
        $total = (int) ($run->analysis['total'] ?? 0);
        if ($run->state === Run::DONE) {
            return 100;
        }
        if ($total === 0 || $run->phase() !== ImportEngine::PHASE_EXECUTE) {
            return 0;
        }

        return (int) min(99, floor(100 * $run->position() / $total));
    }

    /** Which of the six steps the page shows. */
    private static function step(?Run $run): string
    {
        return match ($run?->state) {
            null, Run::ABANDONED => 'source',
            Run::UPLOADED => 'analyse',
            Run::ANALYSED => 'preview',
            Run::CONFIRMED, Run::RUNNING => 'run',
            Run::INTERRUPTED => 'interrupted',
            Run::DONE => 'report',
            default => 'source',
        };
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function homeStatus(?Run $run): array
    {
        return match ($run?->state) {
            null => ['label' => 'Jamais lancé', 'tone' => 'info'],
            Run::DONE => ['label' => 'Importé le ' . self::date($run->finishedAt ?? $run->updatedAt), 'tone' => 'done'],
            Run::INTERRUPTED => ['label' => 'Interrompu à ' . self::executionPercent($run) . ' %', 'tone' => 'pending'],
            Run::RUNNING, Run::CONFIRMED => ['label' => 'En cours', 'tone' => 'pending'],
            Run::UPLOADED => ['label' => 'Analyse à terminer', 'tone' => 'info'],
            Run::ANALYSED => ['label' => 'En attente de confirmation', 'tone' => 'info'],
            default => $run->rejectionReason !== null
                ? ['label' => 'Fichier refusé', 'tone' => 'failed']
                : ['label' => 'Abandonné', 'tone' => 'neutral'],
        };
    }

    /** The manifest's ISO 8601 moment, as the day it names in its own time zone. */
    private static function exportedOn(?string $iso): ?string
    {
        return \Core\Service\DateInput::fromStorage($iso)?->format('d/m/Y');
    }

    private static function date(string $stored): string
    {
        return \Core\Service\DateInput::fromStorage($stored)?->format('d/m/Y') ?? '';
    }
}
