<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Database\MigrationRunner;
use Core\Database\SchemaFiles;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\File\FileRepository;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\RequesterNotice;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;

/**
 * Background "Restaurer un backup" — scheduled by Core\Http\Controller\
 * MaintenanceController::restoreBackup() after its own server-side keyword
 * confirmation. Two sources (module spec):
 *
 * - `server`: an existing `backups` row (payload['backup_id']) — its
 *   dbDumpFileId is always an unencrypted dump (every backup-producing path
 *   in this codebase registers one), its fileId is only password-protected
 *   for the user-triggered 'full_config'/'full_no_gallery'/
 *   'full_with_gallery' types (Task\CreateBackupHandler), never for the
 *   automatic 'auto_update'/'auto_reset'/'database' types.
 * - `upload`: payload['uploaded_temp_path'] — a zip built the same way as
 *   Core\Maintenance\BackupService::createFullBackup() (database.sql
 *   bundled inside alongside core/modules/public/storage), previously
 *   downloaded from this same site. Validated (openable, contains
 *   database.sql) before anything else runs.
 *
 * Like Task\InstallUpdateHandler, a safety backup of the CURRENT state is
 * taken first and used to roll back automatically if the restore itself
 * fails.
 *
 * The restored database may be older than the current code, so a schema
 * migration (Core\Database\MigrationRunner) runs after restoreDatabase().
 * That migration can span more than one invocation of this handler — see
 * resumeMigration(): payload['resume_migration'] === true re-enters this
 * handler for a follow-up attempt that ONLY retries the migration (the
 * restore itself, and the safety backup taken before it, are never
 * repeated — resumeMigration() reconstructs the safety backup's file paths
 * from payload['safety_backup_id'] for its own rollback-on-failure path).
 */
class RestoreBackupHandler implements TaskHandlerInterface
{
    private const KEEP_BACKUPS = 5;

    private const TYPE_COMPLETED = 'core.restore_completed';
    private const TYPE_FAILED = 'core.restore_failed';

    /** @var string[] */
    private const ENCRYPTED_BACKUP_TYPES = ['full_config', 'full_no_gallery', 'full_with_gallery'];

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = isset($payload['requested_by_user_account_id'])
            && $payload['requested_by_user_account_id'] !== null
            ? (int) $payload['requested_by_user_account_id']
            : null;

        $pdo = $context->connection->getPdo();
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        // A migration left incomplete by a previous attempt's time budget —
        // the restore itself (and its safety backup) already happened and
        // must never be repeated; only the migration is retried.
        if (($payload['resume_migration'] ?? false) === true) {
            $this->resumeMigration($payload, $context, $requestedBy, $backupRepository, $fileRepository);
            return;
        }

        $uploadedTempPath = isset($payload['uploaded_temp_path']) ? (string) $payload['uploaded_temp_path'] : null;
        $extractedUploadDbDump = null;

        $basePath = dirname($context->storagePath);
        $backupService = new BackupService($context->connection, $context->storagePath, $basePath);

        $safetyDbDump = null;
        $safetyZip = null;

        try {
            // Step 1: safety backup of the CURRENT state.
            $safetyDbDump = $backupService->createDatabaseDump();
            $safetyZip = $backupService->createFileBackup(true);

            $safetyBackupId = $backupRepository->create('auto_reset', $requestedBy);
            $safetyZipFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $safetyZip),
                'sauvegarde.zip',
                'application/zip',
                (int) filesize($safetyZip),
                'admin',
                null,
                $requestedBy
            );
            $safetyDbFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $safetyDbDump),
                'database.sql',
                'application/sql',
                (int) filesize($safetyDbDump),
                'admin',
                null,
                $requestedBy
            );
            $backupRepository->markCompleted($safetyBackupId, $safetyZipFileId, $safetyDbFileId);

            try {
                // Steps 2-5: resolve source (validating an uploaded file's
                // integrity as part of resolution), restore DB, restore
                // files, migrate schema (the restored DB may be older than
                // the current code).
                [$restoreDbDumpPath, $restoreZipPath, $password, $extractedUploadDbDump] =
                    $this->resolveSource($payload, $pdo, $context->storagePath, $context->encryption);

                $backupService->restoreDatabase($restoreDbDumpPath);
                if ($restoreZipPath !== null) {
                    $backupService->restoreFiles($restoreZipPath, $password);
                }

                // Not migrated here, for the same reason
                // Task\InstallUpdateHandler does not: restoreFiles() has
                // just replaced the file tree under a running process, so
                // its loaded classes are the ones that were on disk a
                // moment ago while anything it loads next comes from the
                // restored files. Migrating from here would run one
                // version's MigrationRunner against another version's
                // MigrationResult and MigrationProgress — the mixture
                // that cost six consecutive rollbacks in production.
                //
                // The resume path below already does exactly the right
                // thing, on a later scheduler pass where nothing is mixed;
                // it is now the only path that migrates. That pass is the
                // next crontab tick — at most a minute — rather than a
                // self-directed HTTP hop, for the reason spelled out in
                // Task\InstallUpdateHandler at the same point.
                $source = (string) ($payload['source'] ?? 'server');
                $this->scheduleMigrationResume($context, $safetyBackupId, $source, $requestedBy);

                return;
            } catch (\Throwable $restoreError) {
                $this->rollbackToSafetyBackup($context, $backupService, (string) $safetyDbDump, (string) $safetyZip,
                    $requestedBy, $restoreError);
            }
        } catch (\Throwable $e) {
            $context->journal->log(
                'core',
                'backup_restore_failed',
                'info',
                'Échec de la sauvegarde de sécurité préalable à la restauration',
                ['error' => $e->getMessage()],
                $requestedBy
            );

            RequesterNotice::send(
                $context,
                $requestedBy,
                self::TYPE_FAILED,
                'Échec de la restauration',
                'La sauvegarde de sécurité préalable a échoué — aucune modification n\'a été effectuée.'
            );
        } finally {
            if ($uploadedTempPath !== null) {
                @unlink($uploadedTempPath);
            }
            if ($extractedUploadDbDump !== null) {
                @unlink($extractedUploadDbDump);
            }
        }
    }

    /**
     * Re-entry point for a restore whose migration step didn't finish
     * within MigrationRunner's time budget on a previous attempt. The
     * restore itself (and the safety backup taken before it) already
     * happened and is never repeated — only the migration is retried,
     * which resumes automatically from where it left off.
     *
     * @param array<string, mixed> $payload
     */
    private function resumeMigration(
        array $payload,
        TaskContext $context,
        ?int $requestedBy,
        BackupRepository $backupRepository,
        FileRepository $fileRepository
    ): void {
        $pdo = $context->connection->getPdo();
        $basePath = dirname($context->storagePath);
        $safetyBackupId = (int) ($payload['safety_backup_id'] ?? 0);
        $source = (string) ($payload['source'] ?? 'server');

        try {
            $migrationRunner = new MigrationRunner(
                $context->connection,
                new SchemaIntrospector($pdo),
                new SchemaComparator(),
                new SqlParser()
            );
            $migrationResult = $migrationRunner->migrate(SchemaFiles::all($basePath));

            if (!$migrationResult->complete) {
                $this->scheduleMigrationResume($context, $safetyBackupId, $source, $requestedBy);
                return;
            }

            $this->finishRestore($context, $backupRepository, $fileRepository, $source, $requestedBy);
        } catch (\Throwable $migrationError) {
            $safetyBackup = $safetyBackupId > 0 ? $backupRepository->findById($safetyBackupId) : null;
            $safetyDbDumpFile = $safetyBackup !== null && $safetyBackup->dbDumpFileId !== null
                ? $fileRepository->findById($safetyBackup->dbDumpFileId)
                : null;
            $safetyZipFile = $safetyBackup !== null && $safetyBackup->fileId !== null
                ? $fileRepository->findById($safetyBackup->fileId)
                : null;

            if ($safetyDbDumpFile === null || $safetyZipFile === null) {
                $context->journal->log(
                    'core',
                    'backup_restore_failed',
                    'info',
                    'Échec de la migration lors de la reprise d\'une restauration, sans sauvegarde de sécurité '
                        . 'disponible pour restauration',
                    ['error' => $migrationError->getMessage()],
                    $requestedBy
                );
                RequesterNotice::send(
                    $context,
                    $requestedBy,
                    self::TYPE_FAILED,
                    'Échec critique de la restauration',
                    'La migration a échoué et aucune sauvegarde de sécurité n\'a pu être '
                    . 'restaurée automatiquement. Une intervention manuelle est nécessaire.'
                );
                return;
            }

            $backupService = new BackupService($context->connection, $context->storagePath, $basePath);
            $this->rollbackToSafetyBackup(
                $context,
                $backupService,
                $context->storagePath . '/' . $safetyDbDumpFile->relativePath,
                $context->storagePath . '/' . $safetyZipFile->relativePath,
                $requestedBy,
                $migrationError
            );
        }
    }

    /**
     * Reschedules this same task, with resume_migration=true, so a
     * migration left incomplete by the time budget gets another turn
     * shortly — routed back into resumeMigration() next time.
     */
    private function scheduleMigrationResume(
        TaskContext $context,
        int $safetyBackupId,
        string $source,
        ?int $requestedBy
    ): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('core', 'restore_backup', 0, [
            'resume_migration' => true,
            'safety_backup_id' => $safetyBackupId,
            'source' => $source,
        ], null, $requestedBy);
    }

    /**
     * The tail shared by a fully-completed initial attempt and a
     * fully-completed resumed attempt: journal entry, backup purge,
     * notification.
     */
    private function finishRestore(
        TaskContext $context,
        BackupRepository $backupRepository,
        FileRepository $fileRepository,
        string $source,
        ?int $requestedBy
    ): void {
        $context->journal->log(
            'core',
            'backup_restored',
            'info',
            'Sauvegarde restaurée',
            ['source' => $source],
            $requestedBy
        );

        $this->purgeBeyondLimit($backupRepository, $fileRepository, $context->storagePath);

        RequesterNotice::send(
            $context,
            $requestedBy,
            self::TYPE_COMPLETED,
            'Restauration terminée',
            'La restauration de la sauvegarde est terminée.'
        );
    }

    /**
     * Restores the safety backup taken before this restore attempt and
     * records the outcome — shared by the initial attempt's catch block
     * (which already holds $safetyDbDump/$safetyZip locally) and
     * resumeMigration()'s catch block (which reconstructs them from
     * payload['safety_backup_id'] first).
     */
    private function rollbackToSafetyBackup(
        TaskContext $context,
        BackupService $backupService,
        string $safetyDbDump,
        string $safetyZip,
        ?int $requestedBy,
        \Throwable $error
    ): void {
        $context->journal->log(
            'core',
            'backup_restore_failed',
            'info',
            'Échec de la restauration',
            ['error' => $error->getMessage()],
            $requestedBy
        );

        try {
            $backupService->restoreDatabase($safetyDbDump);
            $backupService->restoreFiles($safetyZip);
            $context->journal->log(
                'core',
                'backup_restore_rolled_back',
                'info',
                'Restauration automatique de l\'état précédent effectuée après échec de restauration',
                [],
                $requestedBy
            );
            $notifyTitle = 'Échec de la restauration';
            $notifyBody = 'La restauration a échoué — l\'état précédent a été restauré automatiquement.';
        } catch (\Throwable $rollbackError) {
            $context->journal->log(
                'core',
                'backup_restore_rollback_failed',
                'info',
                'La restauration automatique après échec a elle-même échoué',
                ['error' => $rollbackError->getMessage()],
                $requestedBy
            );
            $notifyTitle = 'Échec critique de la restauration';
            $notifyBody = 'La restauration a échoué et la restauration automatique de l\'état précédent a également '
                . 'échoué. Une intervention manuelle est nécessaire.';
        }

        RequesterNotice::send($context, $requestedBy, self::TYPE_FAILED, $notifyTitle, $notifyBody);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     0: string,
     *     1: ?string,
     *     2: ?string,
     *     3: ?string
     * } dbDumpPath, filesZipPath, password, extractedUploadDbDump (for finally cleanup)
     */
    private function resolveSource(array $payload, \PDO $pdo, string $storagePath, EncryptionService $encryption): array
    {
        $encryptedPassword = isset($payload['encrypted_password']) ? (string) $payload['encrypted_password'] : '';
        $password = null;
        if ($encryptedPassword !== '') {
            $raw = base64_decode($encryptedPassword, true);
            if ($raw === false) {
                throw new BackupException('Mot de passe de restauration illisible.');
            }
            $password = $encryption->decrypt($raw, 'backup_password');
        }

        $source = (string) ($payload['source'] ?? 'server');

        if ($source === 'upload') {
            $zipPath = (string) ($payload['uploaded_temp_path'] ?? '');
            if ($zipPath === '' || !is_file($zipPath)) {
                throw new BackupException('Fichier de sauvegarde introuvable.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new BackupException('Le fichier uploadé n\'est pas un backup valide.');
            }
            if ($password !== null) {
                $zip->setPassword($password);
            }
            $dbSql = $zip->getFromName('database.sql');
            if ($dbSql === false) {
                $zip->close();
                throw new BackupException('Le fichier uploadé n\'est pas un backup valide (database.sql introuvable — '
                    . 'mot de passe incorrect ?).');
            }
            $zip->close();

            $extractedDbDump = sys_get_temp_dir() . '/scoutmagic_restore_upload_' . bin2hex(random_bytes(8)) . '.sql';
            file_put_contents($extractedDbDump, $dbSql);

            return [$extractedDbDump, $zipPath, $password, $extractedDbDump];
        }

        $backupId = (int) ($payload['backup_id'] ?? 0);
        $backup = (new BackupRepository($pdo))->findById($backupId);
        if ($backup === null || $backup->status !== 'completed' || $backup->dbDumpFileId === null) {
            throw new BackupException('Sauvegarde introuvable ou incomplète.');
        }

        $fileRepository = new FileRepository($pdo);
        $dbDumpFile = $fileRepository->findById($backup->dbDumpFileId);
        if ($dbDumpFile === null) {
            throw new BackupException('Fichier de sauvegarde de la base de données introuvable.');
        }
        $dbDumpPath = $storagePath . '/' . $dbDumpFile->relativePath;

        $filesZipPath = null;
        if ($backup->fileId !== null) {
            $filesFile = $fileRepository->findById($backup->fileId);
            if ($filesFile !== null) {
                $filesZipPath = $storagePath . '/' . $filesFile->relativePath;
            }
        }

        $needsPassword = in_array($backup->type, self::ENCRYPTED_BACKUP_TYPES, true);

        return [$dbDumpPath, $filesZipPath, $needsPassword ? $password : null, null];
    }

    /**
     * Deletes (file + row) every backup beyond the KEEP_BACKUPS most recent
     * — same purge as the other background Maintenance tasks.
     */
    private function purgeBeyondLimit(
        BackupRepository $backupRepository,
        FileRepository $fileRepository,
        string $storagePath
    ): void
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
