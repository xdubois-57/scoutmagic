<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Exception\UserFacingMessage;
use Core\File\FileRepository;
use Core\Maintenance\Backup;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Background generation of a full (config/no-gallery/with-gallery)
 * password-protected backup — scheduled by Core\Http\Controller\
 * MaintenanceController::createFullBackup(), too slow for a synchronous
 * request (module spec). Notifies the requesting admin via
 * $context->notifications when done, success or failure.
 */
class CreateBackupHandler implements TaskHandlerInterface
{
    private const KEEP = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $backupId = (int) ($payload['backup_id'] ?? 0);
        $scope = (string) ($payload['scope'] ?? '');
        $encryptedPassword = (string) ($payload['encrypted_password'] ?? '');
        if ($backupId <= 0 || $scope === '' || $encryptedPassword === '') {
            return;
        }

        $pdo = $context->connection->getPdo();
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        $backup = $backupRepository->findById($backupId);
        if ($backup === null || $backup->status !== 'pending') {
            return;
        }

        $backupRepository->markInProgress($backupId);

        try {
            $rawPassword = base64_decode($encryptedPassword, true);
            if ($rawPassword === false) {
                throw new \RuntimeException('Mot de passe de sauvegarde illisible.');
            }
            $password = $context->encryption->decrypt($rawPassword, 'backup_password');

            $basePath = dirname($context->storagePath);
            $backupService = new BackupService($context->connection, $context->storagePath, $basePath);
            $result = $backupService->createFullBackup($scope, $password);

            $zipFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $result['zipPath']),
                'sauvegarde.zip',
                'application/zip',
                (int) filesize($result['zipPath']),
                'admin',
                null,
                $backup->requestedBy
            );
            $dbDumpFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $result['dbDumpPath']),
                'database.sql',
                'application/sql',
                (int) filesize($result['dbDumpPath']),
                'admin',
                null,
                $backup->requestedBy
            );

            $backupRepository->markCompleted($backupId, $zipFileId, $dbDumpFileId);
            $this->purgeBeyondLimit($backupRepository, $fileRepository, $context->storagePath);

            $context->journal->log(
                'core',
                'backup_completed',
                'info',
                'Sauvegarde complète générée',
                ['backup_id' => $backupId, 'scope' => $scope],
                $backup->requestedBy
            );

            if ($backup->requestedBy !== null) {
                $context->notifications?->dispatch(
                    'core.backup_completed',
                    [['userAccountId' => $backup->requestedBy, 'memberId' => null]],
                    [
                        'title' => 'Sauvegarde prête',
                        'body' => 'Votre sauvegarde est prête à être téléchargée.',
                        'url' => '/config/maintenance',
                    ]
                );
            }
        } catch (\Throwable $e) {
            // backups.error_message is written here and rendered later as a
            // title="" tooltip on Configuration > Maintenance. \Throwable is
            // caught, so anything at all can arrive — the gate keeps a
            // ZipArchive/PDO message off that page while the journal entry
            // just below keeps the real text.
            $backupRepository->markFailed($backupId, substr(UserFacingMessage::from(
                $e,
                'La sauvegarde n\'a pas pu être générée — vérifiez l\'espace disque disponible et les droits '
                . 'd\'écriture sur storage/, puis relancez-la.'
            ), 0, 500));
            $context->journal->log(
                'core',
                'backup_failed',
                'info',
                'Échec de la génération d\'une sauvegarde',
                ['backup_id' => $backupId, 'scope' => $scope, 'error' => $e->getMessage()],
                $backup->requestedBy
            );

            if ($backup->requestedBy !== null) {
                $context->notifications?->dispatch(
                    'core.backup_failed',
                    [['userAccountId' => $backup->requestedBy, 'memberId' => null]],
                    [
                        'title' => 'Échec de la sauvegarde',
                        'body' => 'La génération de votre sauvegarde a échoué. Consultez la page Maintenance pour plus '
                            . 'de détails.',
                        'url' => '/config/maintenance',
                    ]
                );
            }
        }
    }

    /**
     * Deletes (file + row) every backup beyond the KEEP most recent —
     * module spec's 5-backup cap, enforced after every successful
     * generation.
     */
    private function purgeBeyondLimit(
        BackupRepository $backupRepository,
        FileRepository $fileRepository,
        string $storagePath
    ): void
    {
        foreach ($backupRepository->findBeyond(self::KEEP) as $old) {
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
