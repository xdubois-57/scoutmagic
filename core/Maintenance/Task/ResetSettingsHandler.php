<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\File\FileRepository;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Background "Paramètres par défaut" reset — scheduled by
 * Core\Http\Controller\MaintenanceController::resetSettings() after its own
 * server-side keyword confirmation check. Resets every Core\Config\
 * SettingService-registered setting (core and every module) back to its
 * declared default (Core\Config\SettingRepository::resetAllToDefaults()) —
 * never touches accounts, members, editable content, uploaded files, or any
 * module's own data tables.
 */
class ResetSettingsHandler implements TaskHandlerInterface
{
    private const KEEP_BACKUPS = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = isset($payload['requested_by_user_account_id']) && $payload['requested_by_user_account_id'] !== null
            ? (int) $payload['requested_by_user_account_id']
            : null;

        $pdo = $context->connection->getPdo();
        $basePath = dirname($context->storagePath);
        $backupService = new BackupService($context->connection, $context->storagePath, $basePath);
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        try {
            $dbDumpPath = $backupService->createDatabaseDump();
            $filesZipPath = $backupService->createFileBackup(true);

            $backupId = $backupRepository->create('auto_reset', $requestedBy);
            $zipFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $filesZipPath),
                'sauvegarde.zip',
                'application/zip',
                (int) filesize($filesZipPath),
                'admin',
                null,
                $requestedBy
            );
            $dbDumpFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $dbDumpPath),
                'database.sql',
                'application/sql',
                (int) filesize($dbDumpPath),
                'admin',
                null,
                $requestedBy
            );
            $backupRepository->markCompleted($backupId, $zipFileId, $dbDumpFileId);

            $context->settings->resetAllToDefaults();

            $context->journal->log(
                'core',
                'settings_reset',
                'info',
                'Paramètres réinitialisés aux valeurs par défaut',
                ['backup_id' => $backupId],
                $requestedBy
            );

            $this->purgeBeyondLimit($backupRepository, $fileRepository, $context->storagePath);

            if ($requestedBy !== null) {
                $context->notifications?->notify(
                    $requestedBy,
                    'Réinitialisation terminée',
                    'La réinitialisation des paramètres par défaut est terminée.',
                    '/config/maintenance'
                );
            }
        } catch (\Throwable $e) {
            $context->journal->log(
                'core',
                'settings_reset_failed',
                'info',
                'Échec de la réinitialisation des paramètres',
                ['error' => $e->getMessage()],
                $requestedBy
            );

            if ($requestedBy !== null) {
                $context->notifications?->notify(
                    $requestedBy,
                    'Échec de la réinitialisation',
                    'La réinitialisation des paramètres par défaut a échoué.',
                    '/config/maintenance'
                );
            }
        }
    }

    /**
     * Deletes (file + row) every backup beyond the KEEP_BACKUPS most recent
     * — same purge as the other background Maintenance tasks.
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
