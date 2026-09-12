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
use Core\Storage\DiskBudget;

/**
 * Background generation of a password-protected backup — the full one
 * (config/no-gallery/with-gallery), scheduled by
 * `MaintenanceController::createFullBackup()`, and since IT-06 the
 * portable one, scheduled by `createPortableBackup()`. Both are too slow
 * for a synchronous request (module spec). Notifies the requesting admin
 * via $context->notifications when done, success or failure.
 *
 * **Two entry points, one background path.** The controllers differ in
 * what they validate and what they journal — one of them is asking the
 * site to package its master key — but from the moment the task is queued
 * there is a single sequence: build, register both files, record the
 * digests through `BackupIntegrity`, purge to the family's quota. The one
 * branch below is which archive to build.
 */
class CreateBackupHandler implements TaskHandlerInterface
{

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
            $backupService = new BackupService(
                $context->connection,
                $context->storagePath,
                $basePath,
                new DiskBudget($context->storagePath, $context->settings)
            );
            // The one branch, and it is here rather than inside the
            // service because the two entry points validate different
            // things: a scope from a fixed list on one side, a passphrase
            // long enough to be the only guard on a master key on the
            // other. The archive-writing path below them is single.
            $result = $scope === Backup::PORTABLE_TYPE
                ? $backupService->createPortableBackup(
                    $password,
                    \Core\Maintenance\VersionFile::read($basePath),
                    $this->installationId($context)
                )
                : $backupService->createFullBackup($scope, $password);

            $zipFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $result['zipPath']),
                // The downloaded name says which kind it is. An operator
                // ends up with several of these in a downloads folder, and
                // the portable one is the archive whose handling rules are
                // different — it is worth being able to tell it apart
                // without opening it.
                $scope === Backup::PORTABLE_TYPE ? 'sauvegarde-portable.zip' : 'sauvegarde.zip',
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

            (new \Core\Maintenance\BackupIntegrity(
                $backupRepository,
                $fileRepository,
                $context->storagePath
            ))->complete($backupId, $zipFileId, $result['zipPath'], $dbDumpFileId, $result['dbDumpPath']);
            (new \Core\Maintenance\BackupRetention(
                $backupRepository,
                $fileRepository,
                $context->storagePath,
                $context->settings,
                \Core\Maintenance\BackupSafetyNet::forPdo($context->connection->getPdo())
            ))->purgeAfterCreating($scope);

            $context->journal->log(
                'core',
                'backup_completed',
                'info',
                // Named by type rather than always « complète »: the same
                // handler now also produces the portable archive, and a
                // journal that calls it something else is a journal
                // somebody will read on the day it matters.
                'Sauvegarde générée : ' . Backup::typeLabel($scope),
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
            $backupRepository->markFailed(
                $backupId,
                substr(
                    UserFacingMessage::from(
                        $e,
                        'La sauvegarde n\'a pas pu être générée — vérifiez l\'espace disque disponible et les droits '
                        . 'd\'écriture sur storage/, puis relancez-la.'
                    ),
                    0,
                    500
                )
            );
            $context->journal->log(
                'core',
                'backup_failed',
                'info',
                'Échec de la génération d\'une sauvegarde : ' . Backup::typeLabel($scope),
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

    private function relativePath(string $storagePath, string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($storagePath)), '/');
    }

    /**
     * The origin identifier a portable archive records, or null.
     *
     * **Read, never generated.** `InstallationIdentityService` mints one
     * lazily on first use, which is right for the usage statistics it
     * belongs to and wrong here: an installation that has never reported
     * anything would acquire a permanent identity as a side effect of
     * taking a backup. A restore assigns a new identifier regardless (D6),
     * so the field is a record of where the archive came from, and "we do
     * not know" is a truthful value for it.
     */
    private function installationId(TaskContext $context): ?string
    {
        $stored = $context->settings->get(\Core\Statistics\InstallationIdentityService::INSTALLATION_ID_SETTING);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }
}
