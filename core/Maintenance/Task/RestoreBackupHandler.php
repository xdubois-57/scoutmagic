<?php

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\File\FileRepository;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
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
 */
class RestoreBackupHandler implements TaskHandlerInterface
{
    private const KEEP_BACKUPS = 5;

    /** @var string[] */
    private const ENCRYPTED_BACKUP_TYPES = ['full_config', 'full_no_gallery', 'full_with_gallery'];

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = isset($payload['requested_by_user_account_id']) && $payload['requested_by_user_account_id'] !== null
            ? (int) $payload['requested_by_user_account_id']
            : null;
        $uploadedTempPath = isset($payload['uploaded_temp_path']) ? (string) $payload['uploaded_temp_path'] : null;
        $extractedUploadDbDump = null;

        $pdo = $context->connection->getPdo();
        $basePath = dirname($context->storagePath);
        $backupService = new BackupService($context->connection, $context->storagePath, $basePath);
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

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

                $migrationRunner = new MigrationRunner(
                    $context->connection,
                    new SchemaIntrospector($pdo),
                    new SchemaComparator(),
                    new SqlParser()
                );
                $migrationRunner->migrate([$basePath . '/schema/core.sql']);

                $context->journal->log(
                    'core',
                    'backup_restored',
                    'info',
                    'Sauvegarde restaurée',
                    ['source' => (string) ($payload['source'] ?? 'server')],
                    $requestedBy
                );

                $this->purgeBeyondLimit($backupRepository, $fileRepository, $context->storagePath);

                if ($requestedBy !== null) {
                    $context->notifications?->notify(
                        $requestedBy,
                        'Restauration terminée',
                        'La restauration de la sauvegarde est terminée.',
                        '/config/maintenance'
                    );
                }
            } catch (\Throwable $restoreError) {
                $context->journal->log(
                    'core',
                    'backup_restore_failed',
                    'info',
                    'Échec de la restauration',
                    ['error' => $restoreError->getMessage()],
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
                    $notifyBody = 'La restauration a échoué et la restauration automatique de l\'état précédent a également échoué. Une intervention manuelle est nécessaire.';
                }

                if ($requestedBy !== null) {
                    $context->notifications?->notify($requestedBy, $notifyTitle, $notifyBody, '/config/maintenance');
                }
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

            if ($requestedBy !== null) {
                $context->notifications?->notify(
                    $requestedBy,
                    'Échec de la restauration',
                    'La sauvegarde de sécurité préalable a échoué — aucune modification n\'a été effectuée.',
                    '/config/maintenance'
                );
            }
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
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?string} dbDumpPath, filesZipPath, password, extractedUploadDbDump (for finally cleanup)
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
            $password = $encryption->decrypt($raw);
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
                throw new BackupException('Le fichier uploadé n\'est pas un backup valide (database.sql introuvable — mot de passe incorrect ?).');
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
