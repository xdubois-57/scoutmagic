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
use Core\File\FileRepository;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
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
            $updateHistoryRepository->markFailed($historyId, $e->getMessage());
            $context->journal->log(
                'core',
                'update_failed',
                'info',
                'Échec de la sauvegarde de sécurité préalable à la mise à jour',
                ['error' => $e->getMessage()],
                $history->requestedBy
            );

            if ($history->requestedBy !== null) {
                $context->notifications?->notify(
                    $history->requestedBy,
                    'Échec de la mise à jour',
                    'La sauvegarde de sécurité préalable a échoué — aucune modification n\'a été effectuée.',
                    '/config/maintenance'
                );
            }
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
                    'Échec de la migration et sauvegarde de sécurité introuvable pour restauration automatique : ' . $migrationError->getMessage()
                );
                $context->journal->log(
                    'core',
                    'update_failed',
                    'info',
                    'Échec de la migration lors de la reprise d\'une mise à jour, sans sauvegarde disponible pour restauration',
                    ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo, 'error' => $migrationError->getMessage()],
                    $history->requestedBy
                );
                if ($history->requestedBy !== null) {
                    $context->notifications?->notify(
                        $history->requestedBy,
                        'Échec critique de la mise à jour',
                        'La migration a échoué et aucune sauvegarde de sécurité n\'a pu être restaurée automatiquement. Une intervention manuelle est nécessaire.',
                        '/config/maintenance'
                    );
                }
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

        if ($history->requestedBy !== null) {
            $context->notifications?->notify(
                $history->requestedBy,
                'Mise à jour terminée',
                "La mise à jour vers la version {$history->versionTo} est terminée.",
                '/config/maintenance'
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
            $updateHistoryRepository->markRolledBack($historyId, $error->getMessage());
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
                'Échec de la mise à jour et de la restauration automatique : ' . $rollbackError->getMessage()
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

        if ($history->requestedBy !== null) {
            $context->notifications?->notify($history->requestedBy, $notifyTitle, $notifyBody, '/config/maintenance');
        }
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

    private function download(string $url, string $destPath): void
    {
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
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: ScoutMagic-Updater\r\n",
                'timeout' => 300,
                'follow_location' => 1,
                'ignore_errors' => true,
            ],
        ]);

        // file_get_contents() rather than copy(): both reach the same
        // stream wrapper, but $http_response_header is only reliably
        // populated after the former — same convention as Core\Maintenance\
        // GitHubReleaseClient::httpGet(), including checking $body === false
        // and returning before ever touching $http_response_header: when
        // the connection itself never gets a response at all (DNS failure,
        // connection refused), PHP never assigns that variable, and reading
        // it in that branch would be genuinely undefined.
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return [false, null, 'connexion impossible'];
        }

        $statusCode = $this->parseHttpStatus($http_response_header);
        if ($statusCode !== null && $statusCode >= 400) {
            return [false, $statusCode, "HTTP {$statusCode}"];
        }

        if (@file_put_contents($destPath, $body) === false || !is_file($destPath) || filesize($destPath) === 0) {
            @unlink($destPath);
            return [false, $statusCode, 'écriture du fichier temporaire impossible'];
        }

        return [true, $statusCode, ''];
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
                // several in $http_response_header after a redirect).
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

        foreach (scandir($sourceDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $excludedTopLevel, true)) {
                continue;
            }
            $this->copyRecursive($sourceDir . '/' . $entry, $destDir . '/' . $entry);
        }
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

    private function copyRecursive(string $source, string $dest): void
    {
        if (is_dir($source)) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
            foreach (scandir($source) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->copyRecursive($source . '/' . $entry, $dest . '/' . $entry);
            }
        } else {
            copy($source, $dest);
        }
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
