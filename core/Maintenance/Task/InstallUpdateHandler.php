<?php

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
use Core\Maintenance\UpdateHistoryRepository;
use Core\Maintenance\VersionFile;
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
 * (reuses Core\Database\MigrationRunner, same DDL-diff engine as every
 * normal request) → VERSION file written → completed. Any throwable from
 * downloading through the VERSION write triggers an automatic rollback via
 * the same backup just taken; a throwable from the backup step itself
 * cannot be rolled back (nothing was changed yet) and is just recorded as
 * failed.
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
        if ($history === null || $history->status !== 'pending') {
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
                $migrationRunner->migrate([$basePath . '/schema/core.sql']);

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
            } catch (\Throwable $installError) {
                $context->journal->log(
                    'core',
                    'update_failed',
                    'info',
                    'Échec de l\'installation de la mise à jour',
                    ['version_from' => $history->versionFrom, 'version_to' => $history->versionTo, 'error' => $installError->getMessage()],
                    $history->requestedBy
                );

                try {
                    $backupService->restoreDatabase($dbDumpPath);
                    $backupService->restoreFiles($filesZipPath);
                    $updateHistoryRepository->markRolledBack($historyId, $installError->getMessage());
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

    private function download(string $url, string $destPath): void
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

        $ok = @copy($url, $destPath, $context);
        if (!$ok || !is_file($destPath) || filesize($destPath) === 0) {
            throw new UpdateException('Le téléchargement de la mise à jour a échoué.');
        }
    }

    private function extract(string $zipPath, string $destDir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('L\'archive de mise à jour est invalide.');
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
