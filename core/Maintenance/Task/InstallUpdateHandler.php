<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Exception\UserFacingMessage;
use Core\File\FileRepository;
use Core\Http\StreamResponseHeaders;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\OpcodeCache;
use Core\Maintenance\UpdateException;
use Core\Maintenance\UpdateHistory;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Maintenance\VersionFile;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Background installation of either a GitHub release or (development mode)
 * a branch archive — scheduled by Core\Http\Controller\
 * MaintenanceController::installUpdate() (manual) or
 * Core\Maintenance\GitHubWebhookService (automatic, via the GitHub
 * webhook). Never re-queries GitHub: $payload['download_url'] is the
 * artifact URL already resolved by the caller, and $payload['version_to']
 * (via the UpdateHistory row) already carries either the release tag or a
 * "dev-{short sha}" string.
 *
 * Steps, each recorded in update_history.status: backing_up (a real,
 * restorable Core\Maintenance\BackupService backup — not shortcut, since
 * it's the only thing rollback can restore from) → downloading → installing
 * (copy over the live install, excluding storage/ and VERSION, then clear
 * storage/temp/twig_cache so no pre-update compiled template lingers,
 * see clearCompiledTemplateCache()) → migrating
 * (reuses Core\Database\MigrationRunner, same chunked/resumable DDL-diff
 * engine as every normal request) → VERSION file written → completed.
 * migrating can span more than one invocation of this handler:
 * MigrationRunner::migrate() returns early (MigrationResult::$complete
 * false) once it hits its own internal time budget, in which case this
 * handler reschedules itself (scheduleMigrationResume()) rather than
 * treating the update as finished — update_history.status stays
 * 'migrating', and the next invocation re-enters via the status==='migrating'
 * branch below, which retries ONLY the migration step (backing_up/
 * downloading/installing already happened and must never be repeated).
 * Any throwable from downloading through a completed migration triggers an
 * automatic rollback via the same backup just taken (see
 * rollbackToSafetyBackup(), reused by both the initial attempt — which
 * still holds the backup's file paths locally — and a resumed attempt,
 * which reconstructs them from update_history.backup_id); a throwable from
 * the backup step itself cannot be rolled back (nothing was changed yet)
 * and is just recorded as failed.
 */
class InstallUpdateHandler implements TaskHandlerInterface
{
    private const KEEP_BACKUPS = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $historyId = (int) ($payload['history_id'] ?? 0);
        $downloadUrl = (string) ($payload['download_url'] ?? '');
        // Defaults to 'release' (not 'branch') so an already-queued task
        // scheduled before this field existed still installs correctly.
        $sourceType = (string) ($payload['source_type'] ?? 'release');
        if ($historyId <= 0 || $downloadUrl === '') {
            return;
        }

        $pdo = $context->connection->getPdo();
        $updateHistoryRepository = new UpdateHistoryRepository($pdo);
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        $history = $updateHistoryRepository->findById($historyId);
        if ($history === null) {
            return;
        }

        // A migration left incomplete by a previous attempt's time budget
        // (MigrationResult::$complete false) — resume ONLY the migration
        // step. backing_up/downloading/installing already happened and
        // must never be repeated: installFiles() is not safe to re-run
        // over files that may already reflect the new version.
        if ($history->status === 'migrating') {
            $this->resumeMigration($historyId, $history, $downloadUrl, $sourceType, $context, $updateHistoryRepository, $backupRepository, $fileRepository);
            return;
        }

        if ($history->status !== 'pending') {
            return;
        }

        // This update is genuinely about to start — the clearest possible
        // signal that any OTHER still-non-terminal row is abandoned, not
        // actually running (see UpdateHistoryRepository::
        // markOtherInProgressAsFailed()'s own docblock for why). Without
        // this, a row a crashed/superseded attempt left stuck in
        // 'downloading' etc. would keep matching findInProgress() and
        // block every visitor behind Core\Maintenance\MaintenanceGate
        // until its own 15-minute staleness fallback finally caught it.
        $updateHistoryRepository->markOtherInProgressAsFailed($historyId);

        $basePath = dirname($context->storagePath);
        $backupService = new BackupService($context->connection, $context->storagePath, $basePath);
        $tempDir = $context->storagePath . '/temp/update_' . $historyId;

        $dbDumpPath = null;
        $filesZipPath = null;

        try {
            // Step 1: mandatory safety backup — the only thing an automatic
            // rollback can restore from, so it must be a genuine, restorable
            // backup (DB dump + full file tree, gallery included).
            $updateHistoryRepository->setStatus($historyId, 'backing_up');
            $dbDumpPath = $backupService->createDatabaseDump();
            $filesZipPath = $backupService->createFileBackup(true);

            $backupId = $backupRepository->create('auto_update', $history->requestedBy);
            $zipFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $filesZipPath),
                'sauvegarde.zip',
                'application/zip',
                (int) filesize($filesZipPath),
                'admin',
                null,
                $history->requestedBy
            );
            $dbDumpFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $dbDumpPath),
                'database.sql',
                'application/sql',
                (int) filesize($dbDumpPath),
                'admin',
                null,
                $history->requestedBy
            );
            $backupRepository->markCompleted($backupId, $zipFileId, $dbDumpFileId);
            $updateHistoryRepository->setBackupId($historyId, $backupId);

            try {
                // Steps 2-5: download, install, migrate, write VERSION.
                $updateHistoryRepository->setStatus($historyId, 'downloading');
                if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
                    throw new UpdateException('Impossible de créer le dossier temporaire de mise à jour.');
                }
                $artifactPath = $tempDir . '/artifact.zip';
                $this->download($downloadUrl, $artifactPath);

                $extractedDir = $tempDir . '/extracted';
                mkdir($extractedDir, 0755, true);
                $this->extract($artifactPath, $extractedDir);

                $updateHistoryRepository->setStatus($historyId, 'installing');
                $sourceRoot = $sourceType === 'branch'
                    ? $this->resolveBranchArchiveRoot($extractedDir)
                    : $extractedDir;
                $this->installFiles($sourceRoot, $basePath);
                $this->clearCompiledTemplateCache($context->storagePath);
                $this->dropStaleCompiledCode();

                $updateHistoryRepository->setStatus($historyId, 'migrating');
                $migrationRunner = new MigrationRunner(
                    $context->connection,
                    new SchemaIntrospector($pdo),
                    new SchemaComparator(),
                    new SqlParser()
                );
                $migrationResult = $migrationRunner->migrate([$basePath . '/schema/core.sql']);

                if (!$migrationResult->complete) {
                    $this->scheduleMigrationResume($context, $historyId, $downloadUrl, $sourceType);
                    return;
                }

                $this->refuseUnconvergedMigration($migrationResult);

                $this->finishInstall($historyId, $history, $context, $updateHistoryRepository, $backupRepository, $fileRepository);
            } catch (\Throwable $installError) {
                $this->rollbackToSafetyBackup(
                    $historyId,
                    $history,
                    $context,
                    $updateHistoryRepository,
                    $backupService,
                    (string) $dbDumpPath,
                    (string) $filesZipPath,
                    $installError
                );
            }
        } catch (\Throwable $e) {
            // The safety backup itself failed — nothing was changed yet, so
            // there is nothing to roll back.
            //
            // update_history.error_message is written here and rendered much
            // later as a title="" tooltip on Configuration > Maintenance, so
            // it goes through the gate: \Throwable is caught, and a
            // ZipArchive/PDO/filesystem message must not reach that page.
            // The journal entry immediately below keeps the real text.
            $updateHistoryRepository->markFailed($historyId, UserFacingMessage::from(
                $e,
                'La sauvegarde de sécurité préalable a échoué — aucune modification n\'a été effectuée. '
                . 'Vérifiez l\'espace disque et les droits d\'écriture sur storage/, puis relancez la mise à jour.'
            ));
            $context->journal->log(
                'core',
                'update_failed',
                'info',
                'Échec de la sauvegarde de sécurité préalable à la mise à jour',
                ['error' => $e->getMessage()],
                $history->requestedBy
            );

            $this->announce(
                $context,
                $history,
                'core.update_failed',
                'Échec de la mise à jour',
                'La sauvegarde de sécurité préalable a échoué — aucune modification n\'a été effectuée.'
            );
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Re-entry point for an update whose migration step didn't finish
     * within MigrationRunner's time budget on a previous attempt.
     * backing_up/downloading/installing are NOT repeated — only the
     * migration itself is retried, which resumes automatically from
     * exactly where it left off (MigrationRunner persists its own
     * progress, keyed by the schema files, independent of this handler).
     */
    private function resumeMigration(
        int $historyId,
        UpdateHistory $history,
        string $downloadUrl,
        string $sourceType,
        TaskContext $context,
        UpdateHistoryRepository $updateHistoryRepository,
        BackupRepository $backupRepository,
        FileRepository $fileRepository
    ): void {
        $basePath = dirname($context->storagePath);
        $pdo = $context->connection->getPdo();
        $backupService = new BackupService($context->connection, $context->storagePath, $basePath);

        try {
            $migrationRunner = new MigrationRunner(
                $context->connection,
                new SchemaIntrospector($pdo),
                new SchemaComparator(),
                new SqlParser()
            );
            $migrationResult = $migrationRunner->migrate([$basePath . '/schema/core.sql']);

            if (!$migrationResult->complete) {
                $this->scheduleMigrationResume($context, $historyId, $downloadUrl, $sourceType);
                return;
            }

            $this->refuseUnconvergedMigration($migrationResult);

            $this->finishInstall($historyId, $history, $context, $updateHistoryRepository, $backupRepository, $fileRepository);
        } catch (\Throwable $migrationError) {
            // Unlike the initial attempt, this invocation never created its
            // own backup — reconstruct the safety backup's file paths from
            // update_history.backup_id (set on the original attempt) so the
            // same rollback path applies here too.
            $backup = $history->backupId !== null ? $backupRepository->findById($history->backupId) : null;
            $dbDumpFile = $backup !== null && $backup->dbDumpFileId !== null
                ? $fileRepository->findById($backup->dbDumpFileId)
                : null;
            $filesZipFile = $backup !== null && $backup->fileId !== null
                ? $fileRepository->findById($backup->fileId)
                : null;

            if ($dbDumpFile === null || $filesZipFile === null) {
                $updateHistoryRepository->markFailed(
                    $historyId,
                    'Échec de la migration, et la sauvegarde de sécurité est introuvable pour une restauration '
                    . 'automatique. ' . UserFacingMessage::from(
                        $migrationError,
                        'Consultez le journal des événements pour le détail — une intervention manuelle est nécessaire.'
                    )
                );
                $context->journal->log(
                    'core',
                    'update_failed',
                    'info',
                    'Échec de la migration lors de la reprise d\'une mise à jour, sans sauvegarde disponible pour restauration',
                    ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo, 'error' => $migrationError->getMessage()],
                    $history->requestedBy
                );
                $this->announce(
                    $context,
                    $history,
                    'core.update_failed',
                    'Échec critique de la mise à jour',
                    'La migration a échoué et aucune sauvegarde de sécurité n\'a pu être restaurée automatiquement. Une intervention manuelle est nécessaire.'
                );
                return;
            }

            $this->rollbackToSafetyBackup(
                $historyId,
                $history,
                $context,
                $updateHistoryRepository,
                $backupService,
                $context->storagePath . '/' . $dbDumpFile->relativePath,
                $context->storagePath . '/' . $filesZipFile->relativePath,
                $migrationError
            );
        }
    }

    /**
     * Reschedules this same task so a migration left incomplete by the time
     * budget gets another turn shortly — update_history.status is left at
     * 'migrating' (already set by the caller), which is exactly the state
     * the top-level guard in handle() checks to route back into
     * resumeMigration() next time.
     */
    /**
     * An abandoned migration is a failed update, not a degraded one.
     *
     * MigrationRunner gives up after several identically failing passes
     * and caches the schema hash anyway, because a site held on the
     * progress page forever is worse than a schema missing a column. That
     * trade-off is right for a visitor arriving on a site nobody is
     * updating; it is wrong here, where a safety backup exists and
     * rolling back to it is both possible and correct. `complete` alone
     * cannot tell the two apart, which is why MigrationResult carries
     * `converged` — throwing here hands the case to the caller's existing
     * rollback path (status `rolled_back`, not `failed`).
     */
    private function refuseUnconvergedMigration(\Core\Database\MigrationResult $result): void
    {
        if ($result->converged) {
            return;
        }

        throw new \RuntimeException(
            'Schema migration was abandoned without converging: '
            . 'the same statements failed on every attempt.'
        );
    }

    private function scheduleMigrationResume(TaskContext $context, int $historyId, string $downloadUrl, string $sourceType): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('core', 'install_update', 0, [
            'history_id' => $historyId,
            'download_url' => $downloadUrl,
            'source_type' => $sourceType,
        ]);
    }

    /**
     * The tail shared by a fully-completed initial attempt and a
     * fully-completed resumed attempt: VERSION write, completion bookkeeping,
     * backup purge, notification.
     */
    private function finishInstall(
        int $historyId,
        UpdateHistory $history,
        TaskContext $context,
        UpdateHistoryRepository $updateHistoryRepository,
        BackupRepository $backupRepository,
        FileRepository $fileRepository
    ): void {
        $basePath = dirname($context->storagePath);
        VersionFile::write($basePath, $history->versionTo);
        $context->settings->setInternal('site_version', $history->versionTo);

        $updateHistoryRepository->markCompleted($historyId);
        $context->journal->log(
            'core',
            'update_installed',
            'info',
            'Mise à jour installée',
            ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo],
            $history->requestedBy
        );

        $this->purgeBeyondLimit($backupRepository, $fileRepository, $context->storagePath);

        $this->announce(
            $context,
            $history,
            'core.update_installed',
            'Mise à jour terminée',
            "La mise à jour vers la version {$history->versionTo} est terminée."
        );
    }

    /**
     * Tells somebody the update is over, one way or the other.
     *
     * Two audiences, never both at once — an install has exactly one
     * origin, and telling the same superadmin twice about one install is
     * worse than telling them once:
     *
     * - A **requested** install (manual "Installer maintenant",
     *   update_history.requested_by set) notifies its requester and only
     *   them, unchanged: they are watching /config/maintenance poll and
     *   this is the answer to a question they just asked, so it goes out
     *   through notify() — immediate, no preference to consult, no way to
     *   miss it.
     * - An **automatic** install (webhook release, dev-branch push, or the
     *   daily stable check — Core\Maintenance\GitHubWebhookService,
     *   requested_by null) has no requester to answer, which is exactly
     *   why it used to notify nobody at all: several dev builds could
     *   install overnight and leave nothing behind but journal entries.
     *   It now announces itself as a declared type to everyone who wants
     *   it (NotificationService::recipientsForType()) — on by default for
     *   superadmins, available and off by default for admins, per the
     *   type's `default_on_role_min` (Core\Notification\
     *   NotificationRegistry).
     *
     * Both notification paths are best-effort by construction
     * ($context->notifications is null when VAPID keys aren't provisioned)
     * and neither may take an install down after the fact: the update is
     * already installed and its outcome already journaled by the time this
     * runs, so a notification failure is caught and dropped rather than
     * left to surface as a failed scheduled task.
     */
    private function announce(
        TaskContext $context,
        UpdateHistory $history,
        string $typeId,
        string $title,
        string $body
    ): void {
        try {
            if ($history->requestedBy !== null) {
                $context->notifications?->notify($history->requestedBy, $title, $body, '/config/maintenance');
                return;
            }

            $notifications = $context->notifications;
            if ($notifications === null) {
                return;
            }

            $recipients = $notifications->recipientsForType($typeId);
            if ($recipients === []) {
                return;
            }

            $notifications->dispatch($typeId, $recipients, [
                'title' => $title,
                'body' => $body,
                'url' => '/config/maintenance',
            ]);
        } catch (\Throwable $e) {
            $context->journal->log(
                'core',
                'update_notification_failed',
                'info',
                'La notification de fin de mise à jour n\'a pas pu être envoyée',
                ['type_id' => $typeId, 'error' => $e->getMessage()],
                $history->requestedBy
            );
        }
    }

    /**
     * Restores the safety backup taken before this update attempt and
     * records the outcome — shared by the initial attempt's catch block
     * (which already holds $dbDumpPath/$filesZipPath locally) and
     * resumeMigration()'s catch block (which reconstructs them from
     * update_history.backup_id first).
     */
    private function rollbackToSafetyBackup(
        int $historyId,
        UpdateHistory $history,
        TaskContext $context,
        UpdateHistoryRepository $updateHistoryRepository,
        BackupService $backupService,
        string $dbDumpPath,
        string $filesZipPath,
        \Throwable $error
    ): void {
        $context->journal->log(
            'core',
            'update_failed',
            'info',
            'Échec de l\'installation de la mise à jour',
            ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo, 'error' => $error->getMessage()],
            $history->requestedBy
        );

        try {
            $backupService->restoreDatabase($dbDumpPath);
            $backupService->restoreFiles($filesZipPath);
            // Same write-site rule as markFailed() above: this string is
            // rendered as a title="" tooltip on the maintenance page.
            $updateHistoryRepository->markRolledBack($historyId, UserFacingMessage::from(
                $error,
                'L\'installation de la mise à jour a échoué — la version précédente a été restaurée '
                . 'automatiquement. Le détail est dans le journal des événements.'
            ));
            $context->journal->log(
                'core',
                'update_rolled_back',
                'info',
                'Restauration automatique effectuée après échec de mise à jour',
                ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo],
                $history->requestedBy
            );
            $notifyTitle = 'Échec de la mise à jour';
            $notifyBody = "La mise à jour vers la version {$history->versionTo} a échoué — une restauration automatique a été effectuée.";
        } catch (\Throwable $rollbackError) {
            $updateHistoryRepository->markFailed(
                $historyId,
                'Échec de la mise à jour et de la restauration automatique. ' . UserFacingMessage::from(
                    $rollbackError,
                    'Consultez le journal des événements pour le détail — une intervention manuelle est nécessaire.'
                )
            );
            $context->journal->log(
                'core',
                'update_rollback_failed',
                'info',
                'La restauration automatique après échec de mise à jour a elle-même échoué',
                ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo, 'error' => $rollbackError->getMessage()],
                $history->requestedBy
            );
            $notifyTitle = 'Échec critique de la mise à jour';
            $notifyBody = 'La mise à jour a échoué et la restauration automatique a également échoué. Une intervention manuelle est nécessaire.';
        }

        $this->announce($context, $history, 'core.update_failed', $notifyTitle, $notifyBody);
    }

    /**
     * The zipball URL (release asset or branch archive, both served from
     * api.github.com) is fetched unauthenticated, so it's subject to
     * GitHub's 60-requests/hour-per-IP anonymous rate limit — a burst of
     * webhook-triggered installs (several pushes close together) can hit
     * this. `ignore_errors: true` means a 403/5xx response still "succeeds"
     * as far as copy() is concerned, just with a JSON error body instead of
     * zip bytes — extract() would otherwise report that as a generic
     * "archive invalide" with no indication it was really a rate limit or
     * a transient GitHub outage. The HTTP status is checked explicitly so
     * only genuinely transient statuses (429/403/5xx, or no response at
     * all) are retried, for up to DOWNLOAD_RETRY_WINDOW_SECONDS total
     * before giving up.
     */
    private const DOWNLOAD_RETRY_WINDOW_SECONDS = 60;

    /** GitHub download URLs redirect across a couple of its own hosts; a
     * legitimate chain is short, so cap it low and re-validate each hop. */
    private const MAX_DOWNLOAD_REDIRECTS = 5;

    private function download(string $url, string $destPath): void
    {
        // The artifact is unpacked over the live PHP tree, so it must come
        // from GitHub over https and nowhere else — refuse before the first
        // byte is fetched (see Core\Maintenance\GitHubUrlValidator).
        if (!\Core\Maintenance\GitHubUrlValidator::isAllowed($url)) {
            throw new UpdateException('URL de mise à jour refusée : la source doit être GitHub (https).');
        }

        $deadline = microtime(true) + self::DOWNLOAD_RETRY_WINDOW_SECONDS;
        $lastError = 'raison inconnue';

        while (true) {
            [$ok, $statusCode, $lastError] = $this->attemptDownload($url, $destPath);
            if ($ok) {
                return;
            }

            $remaining = $deadline - microtime(true);
            if (!$this->isTransientDownloadFailure($statusCode) || $remaining <= 0) {
                break;
            }

            usleep((int) (min(5.0, max(1.0, $remaining)) * 1_000_000));
        }

        throw new UpdateException("Le téléchargement de la mise à jour a échoué ({$lastError}).");
    }

    /**
     * @return array{0: bool, 1: int|null, 2: string} success, HTTP status
     *         (null if the connection itself never got a response), and a
     *         short human-readable reason for a failed attempt
     */
    private function attemptDownload(string $url, string $destPath): array
    {
        // Redirects are followed by hand (follow_location => 0) so every hop
        // is re-checked against the GitHub allowlist. A GitHub download
        // legitimately redirects (api.github.com → codeload, github.com →
        // objects.githubusercontent.com), but a redirect to any other host
        // must abort the download rather than be followed blindly.
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_DOWNLOAD_REDIRECTS; $hop++) {
            if (!\Core\Maintenance\GitHubUrlValidator::isAllowed($currentUrl)) {
                return [false, null, 'redirection hors GitHub refusée'];
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: ScoutMagic-Updater\r\n",
                    'timeout' => 300,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                ],
            ]);

            // file_get_contents() rather than copy(): both reach the same
            // stream wrapper, but only the former reliably records the
            // response headers — same convention as Core\Maintenance\
            // GitHubReleaseClient::httpGet(). Cleared first so that one hop
            // of this redirect chain can never be read as the next one's.
            StreamResponseHeaders::clear();
            $body = @file_get_contents($currentUrl, false, $context);
            if ($body === false) {
                return [false, null, 'connexion impossible'];
            }

            $responseHeaders = StreamResponseHeaders::last();
            $statusCode = $this->parseHttpStatus($responseHeaders);

            if ($statusCode !== null && $statusCode >= 300 && $statusCode < 400) {
                $location = $this->parseLocationHeader($responseHeaders);
                if ($location === null) {
                    return [false, $statusCode, "redirection {$statusCode} sans destination"];
                }
                $currentUrl = $location;
                continue;
            }

            if ($statusCode !== null && $statusCode >= 400) {
                return [false, $statusCode, "HTTP {$statusCode}"];
            }

            if (@file_put_contents($destPath, $body) === false || !is_file($destPath) || filesize($destPath) === 0) {
                @unlink($destPath);
                return [false, $statusCode, 'écriture du fichier temporaire impossible'];
            }

            return [true, $statusCode, ''];
        }

        return [false, null, 'trop de redirections'];
    }

    /**
     * The Location header of the last response, or null. Case-insensitive,
     * last-wins (a later header of the same name supersedes an earlier one).
     *
     * @param string[]|null $headers
     */
    private function parseLocationHeader(?array $headers): ?string
    {
        if ($headers === null) {
            return null;
        }

        $location = null;
        foreach ($headers as $header) {
            if (preg_match('/^location:\s*(.+)$/i', trim($header), $m) === 1) {
                $location = trim($m[1]);
            }
        }

        return $location;
    }

    private function isTransientDownloadFailure(?int $statusCode): bool
    {
        return $statusCode === null || $statusCode === 403 || $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * @param array<int, string> $headers
     */
    private function parseHttpStatus(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                // The last status line wins (follow_location can leave
                // several in the raw header list after a redirect).
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    private function extract(string $zipPath, string $destDir): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new UpdateException("L'archive de mise à jour est invalide (code {$openResult}).");
        }
        $extracted = $zip->extractTo($destDir);
        $zip->close();

        if (!$extracted) {
            throw new UpdateException('L\'extraction de l\'archive de mise à jour a échoué.');
        }
    }

    /**
     * Copies every top-level entry of the extracted artifact over the live
     * install, except storage/ (all live uploads/keys/config/temp — never
     * touched by an update) and VERSION (written separately once the rest
     * has succeeded). Anything unit-specific that isn't part of the source
     * tree (config/app.php, .env) was already excluded from the artifact
     * itself by scripts/release.sh, so there's nothing else to special-case
     * here.
     */
    private function installFiles(string $sourceDir, string $destDir): void
    {
        $excludedTopLevel = ['storage', 'VERSION'];

        $this->replacedPhpFiles = [];

        foreach (scandir($sourceDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $excludedTopLevel, true)) {
                continue;
            }
            $this->copyRecursive($sourceDir . '/' . $entry, $destDir . '/' . $entry, $destDir);
        }
    }

    /**
     * Every `.php` path installFiles() has just overwritten, so the OPcache
     * sweep below can name them instead of evicting the whole shared cache.
     * Reset at the start of each installFiles() run rather than accumulated
     * across the two entry points, since a resumed migration never re-runs
     * the copy and must not re-invalidate a list from a previous attempt.
     *
     * @var string[]
     */
    private array $replacedPhpFiles = [];

    /**
     * The compiled-code half of clearCompiledTemplateCache(), and the
     * reason it is not enough on its own.
     *
     * The copy above has replaced the source of every changed class, but
     * OPcache re-reads a file's mtime at most once per
     * `opcache.revalidate_freq` seconds — 60 on a stock installation. Left
     * alone, the next minute of requests executes the PREVIOUS version of
     * the application against the templates, help topics, manifests and
     * schema that were just replaced on disk, which is precisely the mixed
     * state that returned 500 on every route after a real update (see
     * Core\Maintenance\OpcodeCache). Invalidating here closes that window
     * to nothing.
     *
     * `clearstatcache()` goes with it: `realpath_cache_ttl` is 120 seconds
     * by default, and the same request that copied the tree is about to
     * run migrations and write VERSION through paths it stat'ed before the
     * copy.
     */
    private function dropStaleCompiledCode(): void
    {
        clearstatcache(true);
        OpcodeCache::invalidateFiles($this->replacedPhpFiles);
        $this->replacedPhpFiles = [];
    }

    /**
     * Unconditional, version-agnostic: wipes the entire storage/temp/
     * twig_cache tree (every Core\View\TwigFactory version subdirectory,
     * not just the one matching $history->versionTo) on every single
     * install this handler completes — a stable release exactly like a
     * development-mode branch/commit install, never gated on whether the
     * version string actually changed. TwigFactory's per-version cache
     * directory (storage/temp/twig_cache/{version}) already makes a
     * genuinely new VERSION self-healing on its own, but this explicit
     * sweep is the belt to that belt-and-suspenders: it doesn't depend on
     * VERSION having changed at all, so it also covers re-installing the
     * exact same version/commit (nothing left over from a half-applied
     * previous attempt) and any future deploy path that might not bump
     * VERSION for some reason. Runs right after installFiles() (which
     * deliberately never touches storage/) and before VersionFile::write(),
     * so it always targets whatever was compiled under the *previous*
     * version — never the one about to be written. TwigFactory recreates
     * whichever subdirectory a request actually needs, lazily, on demand.
     */
    private function clearCompiledTemplateCache(string $storagePath): void
    {
        $this->removeDirectory($storagePath . '/temp/twig_cache');
    }

    /**
     * GitHub's branch/commit zipball always wraps its contents in a single
     * top-level "{owner}-{repo}-{sha}/" directory — unlike scripts/
     * release.sh's artifact, which zips the repo contents directly at the
     * top level (`zip -r artifact.zip .`). Only ever called for
     * source_type "branch" (never for a release artifact, which must
     * never have this stripping applied even if it coincidentally had a
     * single top-level entry).
     */
    private function resolveBranchArchiveRoot(string $extractedDir): string
    {
        $entries = array_values(array_diff(scandir($extractedDir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($extractedDir . '/' . $entries[0])) {
            return $extractedDir . '/' . $entries[0];
        }

        return $extractedDir;
    }

    /**
     * Every copy() and mkdir() here is checked, and a failure aborts the
     * whole install by throwing — which is what hands control to
     * rollbackToSafetyBackup() and leaves the site on a consistent tree.
     *
     * These return values used to be discarded. A single file that could
     * not be written (a permission or ownership quirk, an open_basedir
     * restriction, a quota, a locked file — the kind of "hosting gap"
     * shared hosting produces routinely) was therefore skipped silently:
     * the install ran to completion, no rollback was triggered, and
     * VERSION was written for the new version over a tree that was only
     * partly updated. That is not a theoretical failure — it took the
     * Maintenance page down with "Unknown 'markdown' filter in
     * config/maintenance.html.twig" after an update landed the new
     * template but left the previous Core\View\TwigFactory (which
     * registers that filter) in place. A half-applied update must fail
     * loudly and roll back, never report success.
     *
     * @throws UpdateException on the first file or directory that cannot be written
     */
    private function copyRecursive(string $source, string $dest, string $root): void
    {
        if (is_dir($source)) {
            // The is_dir() re-check covers the harmless race where a
            // concurrent mkdir() of the same path won.
            if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
                throw new UpdateException(self::writeFailureMessage('créer le répertoire', $dest, $root));
            }
            foreach (scandir($source) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->copyRecursive($source . '/' . $entry, $dest . '/' . $entry, $root);
            }
        } elseif (!@copy($source, $dest)) {
            throw new UpdateException(self::writeFailureMessage('remplacer le fichier', $dest, $root));
        } elseif (str_ends_with($dest, '.php')) {
            // Noted, not acted on: dropStaleCompiledCode() invalidates the
            // whole list once the copy has succeeded end to end, so a file
            // replaced just before an aborted install is never invalidated
            // out from under the rollback that is about to restore it.
            $this->replacedPhpFiles[] = $dest;
        }
    }

    /**
     * Which file the update stopped on, said to the admin who has to go
     * and fix it.
     *
     * The path is what makes this actionable — an interrupted update with
     * no name in it leaves an admin with a broken site and nowhere to
     * start. It is given RELATIVE to the install root
     * (`core/View/TwigFactory.php`, not
     * `/var/www/vhosts/unite.be/httpdocs/core/View/TwigFactory.php`)
     * because the absolute prefix is the part that is both useless to
     * them — they know where their own site lives — and the part worth
     * not printing onto a page: it names the hosting account and often
     * the customer id above it.
     *
     * `error_get_last()` used to be folded in for the reason
     * ("Permission denied", "Disk quota exceeded"). It is gone: the last
     * PHP warning at this point is not reliably the one from the
     * suppressed call just above — anything in between with its own
     * suppressed warning wins — so it was as likely to name an unrelated
     * failure as the real one, and it is raw English either way. What is
     * certain is which file, and that is what this now says.
     */
    private static function writeFailureMessage(string $action, string $path, string $root): string
    {
        $relative = str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : basename($path);

        return "La mise à jour n'a pas pu {$action} « {$relative} » — vérifiez les droits d'écriture "
            . 'et l\'espace disque sur le serveur. L\'installation a été interrompue pour ne pas laisser '
            . 'le site avec une mise à jour partiellement appliquée.';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    /**
     * Deletes (file + row) every backup beyond the KEEP_BACKUPS most recent
     * — same purge as Task\CreateBackupHandler and
     * MaintenanceController::purgeBeyondLimit(), duplicated rather than
     * shared per this codebase's established tolerance for this specific
     * small duplication.
     */
    private function purgeBeyondLimit(BackupRepository $backupRepository, FileRepository $fileRepository, string $storagePath): void
    {
        foreach ($backupRepository->findBeyond(self::KEEP_BACKUPS) as $old) {
            foreach ([$old->fileId, $old->dbDumpFileId] as $fileId) {
                if ($fileId === null) {
                    continue;
                }
                $file = $fileRepository->findById($fileId);
                if ($file !== null) {
                    @unlink($storagePath . '/' . $file->relativePath);
                    $fileRepository->delete($fileId);
                }
            }
            $backupRepository->delete($old->id);
        }
    }

    private function relativePath(string $storagePath, string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($storagePath)), '/');
    }
}
