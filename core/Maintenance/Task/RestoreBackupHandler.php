<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Database\MigrationRunner;
use Core\Exception\UserFacingMessage;
use Core\Database\SchemaFiles;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\File\FileRepository;
use Core\Maintenance\Backup;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableArchive;
use Core\Maintenance\Portable\PortableRestore;
use Core\Maintenance\RequesterNotice;
use Core\Maintenance\VersionFile;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Storage\DiskBudget;

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

        // **Before the safety backup, which is the expensive half.**
        // A portable archive satisfies every test the ordinary restore
        // path applies — completed, with a database dump — so refusing it
        // late is not refusing it at all: the safety copy (a full dump
        // plus a gallery-inclusive archive, minutes of work on a real
        // installation) is taken FIRST, and any exception thrown after
        // that point is caught below and answered with a real rollback,
        // which restores the database and the files all over again. The
        // site survives, having been replaced and un-replaced for an
        // operation that could never finish.
        //
        // An earlier version of this guard sat in resolveSource(), inside
        // that try, and its comment claimed to avoid exactly the cycle it
        // was inside of. A review caught it. Here, nothing has been
        // written yet, so returning costs nothing.
        //
        // **What is refused is a portable row of THIS site's own list**,
        // and that is not the same thing as refusing portable restores.
        // A portable archive is made to be carried to another
        // installation and uploaded there with its passphrase; the copy
        // sitting in this site's own backup list is the one the operator
        // is told to download and delete. Restoring it here would be a
        // worse full backup — the same site, minus the gallery. The
        // upload path below is where a portable archive is genuinely
        // restored.
        if ($this->isPortable($payload, $backupRepository)) {
            $context->journal->log(
                'core',
                'backup_restore_refused',
                'warning',
                'Restauration refusée : une sauvegarde portable ne se restaure pas sur cette installation',
                ['backup_id' => (int) ($payload['backup_id'] ?? 0)],
                $requestedBy
            );
            RequesterNotice::send(
                $context,
                $requestedBy,
                self::TYPE_FAILED,
                'Restauration impossible',
                'Une sauvegarde portable sert à repartir sur une installation neuve, à qui vous la téléversez '
                . 'avec sa phrase de passe. Rien n\'a été modifié.'
            );

            return;
        }

        $uploadedTempPath = isset($payload['uploaded_temp_path']) ? (string) $payload['uploaded_temp_path'] : null;
        $extractedUploadDbDump = null;

        // **A portable archive announces itself**, so the restore page can
        // keep one upload field for every kind of backup and still send
        // this one down a path that shares almost nothing with the other:
        // its dump lives inside the archive, its trees are extracted
        // selectively, and its keys are installed rather than restored.
        // The header this reads is in clear by necessity
        // ({@see PortableArchive::looksPortable()}), so being wrong here
        // is a routing mistake and never a security one — everything that
        // protects the archive is checked afterwards.
        if ($uploadedTempPath !== null && PortableArchive::looksPortable($uploadedTempPath)) {
            try {
                $this->restorePortable($payload, $context, $requestedBy, $uploadedTempPath);
            } finally {
                @unlink($uploadedTempPath);
            }

            return;
        }

        $basePath = dirname($context->storagePath);
        $backupService = new BackupService(
            $context->connection,
            $context->storagePath,
            $basePath,
            new DiskBudget($context->storagePath, $context->settings)
        );

        $safetyDbDump = null;
        $safetyZip = null;

        try {
            $safety = $this->createSafetyBackup(
                $context,
                $backupService,
                $backupRepository,
                $fileRepository,
                $pdo,
                $payload,
                $requestedBy
            );
            $safetyBackupId = $safety['id'];
            $safetyDbDump = $safety['dbDump'];
            $safetyZip = $safety['zip'];

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
                $this->scheduleMigrationResume(
                    $context,
                    $safetyBackupId,
                    $source,
                    $requestedBy,
                    $safetyDbDump,
                    $safetyZip
                );

                return;
            } catch (\Throwable $restoreError) {
                $this->rollbackToSafetyBackup($context, $backupService, (string) $safetyDbDump, (string) $safetyZip,
                    $requestedBy, $restoreError);
            }
        } catch (\Throwable $e) {
            $context->journal->log(
                'core',
                'backup_restore_failed',
                'warning',
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
     * The portable half of a resume payload, carried from one pass to the
     * next.
     *
     * A migration that does not finish inside its time budget schedules
     * another pass, and that pass is handed a payload built here rather
     * than the original one — so anything a portable restore still owes
     * has to survive the copy. Forgetting it would not fail: the restore
     * would complete, and the new installation would go on reporting
     * itself as the old one.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function portablePayload(array $payload): array
    {
        if (($payload['portable'] ?? false) !== true) {
            return [];
        }

        return [
            'portable' => true,
            'portable_origin_installation_id' => $payload['portable_origin_installation_id'] ?? null,
            'portable_base_url' => $payload['portable_base_url'] ?? null,
        ];
    }

    /**
     * Restores a portable archive uploaded onto THIS installation.
     *
     * The order below is the requirement, not a preference. Everything
     * that can refuse — the passphrase, the format, the version, the
     * digests of the sealed secrets — is settled while the target is
     * still untouched, because the target is typically a fresh
     * installation whose operator has just lost the other one. Only then
     * is the expensive safety copy taken, and only then does anything get
     * replaced.
     *
     * What is NOT here: the schema migration, and the new identity that
     * goes with it. Both wait for the resume pass, for the reason spelled
     * out where the ordinary restore schedules it — the file trees have
     * just been replaced under a running process, and migrating from here
     * would run one version's code against another version's.
     *
     * @param array<string, mixed> $payload
     */
    private function restorePortable(
        array $payload,
        TaskContext $context,
        ?int $requestedBy,
        string $archivePath
    ): void {
        $pdo = $context->connection->getPdo();
        $basePath = dirname($context->storagePath);

        try {
            $archive = PortableArchive::open($archivePath, $this->passphraseOf($payload, $context->encryption));
            $archive->assertRestorableOnto(VersionFile::read($basePath));
            $archive->verifyDeclaredMembers();

            // Read BEFORE anything is replaced: after the database and the
            // secrets have been overwritten these are the origin's values,
            // and there is nothing left to put back (D5). Here rather than
            // further down so that a site whose own secrets cannot be read
            // is refused where every other refusal happens — before the
            // safety backup, and with the operator told why.
            $targetOwnedSecrets = $this->targetOwnedSecrets($context);
        } catch (\Throwable $refusal) {
            // Nothing has been written, and saying so is half the message:
            // an operator who has just been refused needs to know whether
            // to go and repair something first.
            $context->journal->log(
                'core',
                'portable_restore_refused',
                'warning',
                'Restauration portable refusée avant toute écriture',
                ['error' => $refusal->getMessage()],
                $requestedBy
            );
            RequesterNotice::send(
                $context,
                $requestedBy,
                self::TYPE_FAILED,
                'Restauration impossible',
                UserFacingMessage::from($refusal, 'Cette archive n\'a pas pu être ouverte.')
                . ' Rien n\'a été modifié.'
            );
            // Opened if the refusal came from anything after the first
            // line, and an open zip handle held past this method is a
            // descriptor nobody closes.
            if (isset($archive)) {
                $archive->close();
            }

            return;
        }

        $backupService = new BackupService(
            $context->connection,
            $context->storagePath,
            $basePath,
            new DiskBudget($context->storagePath, $context->settings)
        );
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        $targetBaseUrl = $context->settings->get('base_url');

        try {
            $safety = $this->createSafetyBackup(
                $context,
                $backupService,
                $backupRepository,
                $fileRepository,
                $pdo,
                $payload,
                $requestedBy
            );
        } catch (\Throwable $e) {
            $archive->close();
            $context->journal->log(
                'core',
                'backup_restore_failed',
                'warning',
                'Échec de la sauvegarde de sécurité préalable à la restauration portable',
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

            return;
        }

        $restore = new PortableRestore($basePath, $context->storagePath);
        // Held aside BEFORE anything replaces them: the safety backup
        // cannot carry these — createFileBackup() excludes storage/keys/
        // and storage/config/ in every mode — so without this the
        // automatic rollback below would restore the database and the file
        // tree and leave the ARCHIVE's keys in place.
        $secretsBefore = $restore->secretsSnapshot();

        try {
            $restore->apply($archive, $backupService, $targetOwnedSecrets);

            $this->scheduleMigrationResume(
                $context,
                $safety['id'],
                'portable',
                $requestedBy,
                $safety['dbDump'],
                $safety['zip'],
                [
                    'portable' => true,
                    'portable_origin_installation_id' => $archive->originInstallationId(),
                    'portable_base_url' => $targetBaseUrl,
                ]
            );
        } catch (\Throwable $restoreError) {
            // Before the rollback, not after: rollbackToSafetyBackup()
            // extracts the safety archive over the file tree, and the
            // installation it hands back must be the one that was here —
            // keys included, or it cannot read what it just recovered.
            $restore->restoreSecretsSnapshot($secretsBefore);

            $this->rollbackToSafetyBackup(
                $context,
                $backupService,
                $safety['dbDump'],
                $safety['zip'],
                $requestedBy,
                $restoreError
            );
        } finally {
            $archive->close();
        }
    }

    /**
     * This machine's own secrets, the ones a restore must not import (D5).
     *
     * An installation that has none is not an error: nothing has to be
     * kept, and there is nothing an archive could displace. The wizard
     * never comes through here at all — it passes its values straight into
     * `PortableRestore::apply()`.
     *
     * @return array<string, mixed>
     * @throws BackupException
     */
    private function targetOwnedSecrets(TaskContext $context): array
    {
        $manager = new SecretManager(
            $context->storagePath . '/keys/master.key',
            $context->storagePath . '/config/secrets.enc'
        );
        if (!$manager->isInitialized()) {
            return [];
        }

        try {
            $secrets = $manager->readSecrets();
        } catch (\Throwable $e) {
            // **Emphatically not an empty array**, which is what this used
            // to return. Empty means "this machine owns no credentials",
            // and `installSecrets()` only overrides the keys it is handed
            // — so an unreadable `secrets.enc` here would end with the
            // ORIGIN's db_host, db_name and db_password live in the file
            // just written. That is precisely the D5 outcome, arrived at
            // by a failure nobody would see.
            //
            // Refusing costs nothing: this runs before the safety backup,
            // before the dump, before anything is written.
            throw new BackupException(
                'Les secrets de ce site n\'ont pas pu être lus. La restauration a été refusée plutôt que d\'y '
                . 'laisser ceux de l\'archive.',
                0,
                $e
            );
        }

        $owned = [];
        foreach (PortableRestore::TARGET_OWNED_SECRETS as $key) {
            if (array_key_exists($key, $secrets)) {
                $owned[$key] = $secrets[$key];
            }
        }

        return $owned;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws BackupException
     */
    private function passphraseOf(array $payload, EncryptionService $encryption): string
    {
        $encrypted = isset($payload['encrypted_password']) ? (string) $payload['encrypted_password'] : '';
        if ($encrypted === '') {
            throw new BackupException('Une sauvegarde portable ne se restaure qu\'avec sa phrase de passe.');
        }

        $raw = base64_decode($encrypted, true);
        if ($raw === false) {
            throw new BackupException('La phrase de passe transmise est illisible.');
        }

        return $encryption->decrypt($raw, 'backup_password');
    }

    /**
     * The safety copy of the CURRENT state, taken before any restore
     * writes anything, and the only thing the automatic rollback can
     * restore from.
     *
     * Shared by the ordinary restore and the portable one rather than
     * copied into each: it reserves disk for both writes at once against
     * the reading they are sized on, registers the two files, completes
     * the row through BackupIntegrity, and declares the copy in the
     * running task's payload — five steps whose ORDER is the whole
     * correctness, and a second copy of them is a second copy to keep
     * right.
     *
     * @param array<string, mixed> $payload
     * @return array{id: int, dbDump: string, zip: string}
     */
    private function createSafetyBackup(
        TaskContext $context,
        BackupService $backupService,
        BackupRepository $backupRepository,
        FileRepository $fileRepository,
        \PDO $pdo,
        array $payload,
        ?int $requestedBy
    ): array {
        // Step 1: safety backup of the CURRENT state.
        // Both writes reserved at once, against the reading they are
        // both sized on. This safety backup is the only thing the
        // automatic rollback below can restore from, so a truncated
        // one is unrecoverable.
        $backupService->ensureRoomForDumpAndArchive(true);

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
        (new \Core\Maintenance\BackupIntegrity(
            $backupRepository,
            $fileRepository,
            $context->storagePath
        ))->complete($safetyBackupId, $safetyZipFileId, $safetyZip, $safetyDbFileId, $safetyDbDump);

        // Declared in THIS task's payload, not only in the resume one
        // scheduled much later. Between the line above and the
        // database being replaced below, the safety copy is a
        // `completed` row like any other: listed on Configuration >
        // Maintenance with a working « Supprimer » button, and
        // invisible to Core\Maintenance\BackupSafetyNet, which reads
        // live payloads. Building it takes minutes on an installation
        // with a gallery, and it is the only thing the rollback below
        // can restore from — so the window in which it could be
        // deleted is both real and the worst possible one.
        //
        // Silent on failure, like every other write on this path: a
        // declaration that cannot be made leaves the copy as exposed
        // as it was before, and must not abort a restore that is
        // otherwise fine.
        $runningTaskId = (int) ($payload['scheduled_action_id'] ?? 0);
        if ($runningTaskId > 0) {
            (new SchedulerRepository($pdo))->rememberInPayload(
                $runningTaskId,
                ['safety_backup_id' => $safetyBackupId]
            );
        }
        return ['id' => $safetyBackupId, 'dbDump' => $safetyDbDump, 'zip' => $safetyZip];
    }

    /**
     * Whether this payload names a portable archive.
     *
     * Only a `server` source can be one: an uploaded file has no
     * `backups` row to have a type, and IT-07 is what will teach the
     * upload path to recognise a portable archive by its own header.
     *
     * @param array<string, mixed> $payload
     */
    private function isPortable(array $payload, BackupRepository $backupRepository): bool
    {
        $backupId = (int) ($payload['backup_id'] ?? 0);
        if ($backupId <= 0) {
            return false;
        }

        return $backupRepository->findById($backupId)?->type === Backup::PORTABLE_TYPE;
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

        // Carried across the restore that replaced the database — see
        // scheduleMigrationResume(). Read before anything else so that a
        // failure at any point below still has them.
        $carriedDbDump = is_string($payload['safety_db_dump_path'] ?? null)
            ? (string) $payload['safety_db_dump_path']
            : null;
        $carriedZip = is_string($payload['safety_zip_path'] ?? null)
            ? (string) $payload['safety_zip_path']
            : null;

        try {
            $migrationRunner = new MigrationRunner(
                $context->connection,
                new SchemaIntrospector($pdo),
                new SchemaComparator(),
                new SqlParser()
            );
            $migrationResult = $migrationRunner->migrate(SchemaFiles::all($basePath));

            if (!$migrationResult->complete) {
                $this->scheduleMigrationResume(
                    $context,
                    $safetyBackupId,
                    $source,
                    $requestedBy,
                    $carriedDbDump,
                    $carriedZip,
                    // Carried forward, or a restore that needed three
                    // passes would arrive at the end having forgotten it
                    // was portable — and would leave the new site running
                    // under the old one's identity.
                    $this->portablePayload($payload)
                );
                return;
            }

            // **Here, and not a step earlier.** The schema now matches the
            // code, so every settings row this touches exists — including
            // the one recording where the site came from, which an origin
            // running an older ScoutMagic would not have had at all.
            if (($payload['portable'] ?? false) === true) {
                // isset() already excludes null here — a payload key that
                // was written as null reads as absent, which is the same
                // fact: this restore has no origin identifier to record.
                $originId = isset($payload['portable_origin_installation_id'])
                    ? (string) $payload['portable_origin_installation_id']
                    : null;
                $baseUrl = isset($payload['portable_base_url'])
                    ? (string) $payload['portable_base_url']
                    : null;

                (new PortableRestore($basePath, $context->storagePath))
                    ->adoptNewIdentity($context->connection->getPdo(), $originId, $baseUrl);

                $context->journal->log(
                    'core',
                    'portable_restore_completed',
                    'security',
                    'Restauration portable terminée : nouvelle identité d\'installation',
                    ['restored_from' => $originId],
                    $requestedBy
                );
            }

            $this->finishRestore($context, $backupRepository, $fileRepository, $source, $requestedBy);
        } catch (\Throwable $migrationError) {
            [$safetyDbDumpPath, $safetyZipPath] = self::resolveSafetyCopy(
                $carriedDbDump,
                $carriedZip,
                $safetyBackupId,
                $backupRepository,
                $fileRepository,
                $context->storagePath
            );

            if ($safetyDbDumpPath === null || $safetyZipPath === null) {
                $context->journal->log(
                    'core',
                    'backup_restore_failed',
                    'warning',
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

            $backupService = new BackupService(
                $context->connection,
                $context->storagePath,
                $basePath,
                new DiskBudget($context->storagePath, $context->settings)
            );
            $this->rollbackToSafetyBackup(
                $context,
                $backupService,
                $safetyDbDumpPath,
                $safetyZipPath,
                $requestedBy,
                $migrationError
            );
        }
    }

    /**
     * Where the safety copy's two files are — from the payload if the
     * pass that queued this one carried them, from the `backups` row
     * otherwise.
     *
     * The order matters and is the whole point. A path travels in the
     * payload and outlives the database; a row id is resolved against
     * whatever database the restore has just put in place, which is not
     * the one the id was minted in. The id path is kept only for a pass
     * queued before this file carried paths at all — an installation
     * upgraded between a restore and its own resume — and it is checked
     * against the disk like any other.
     *
     * Public and static because it is pure resolution — no state, and
     * the one decision in this file that a test can put under a
     * magnifying glass without a database to restore first. The same
     * seam Task\SyncMailboxesHandler::intervalSeconds() and
     * Modules\Registration\Task\ReenrollmentCampaignHandler::handOver()
     * already are.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function resolveSafetyCopy(
        ?string $carriedDbDump,
        ?string $carriedZip,
        int $safetyBackupId,
        BackupRepository $backupRepository,
        FileRepository $fileRepository,
        string $storagePath
    ): array {
        if ($carriedDbDump !== null && $carriedZip !== null
            && is_file($carriedDbDump) && is_file($carriedZip)
        ) {
            return [$carriedDbDump, $carriedZip];
        }

        $backup = $safetyBackupId > 0 ? $backupRepository->findById($safetyBackupId) : null;
        if ($backup === null || $backup->dbDumpFileId === null || $backup->fileId === null) {
            return [null, null];
        }

        $dumpFile = $fileRepository->findById($backup->dbDumpFileId);
        $zipFile = $fileRepository->findById($backup->fileId);
        if ($dumpFile === null || $zipFile === null) {
            return [null, null];
        }

        $dumpPath = $storagePath . '/' . $dumpFile->relativePath;
        $zipPath = $storagePath . '/' . $zipFile->relativePath;

        return is_file($dumpPath) && is_file($zipPath) ? [$dumpPath, $zipPath] : [null, null];
    }

    /**
     * Reschedules this same task, with resume_migration=true, so a
     * migration left incomplete by the time budget gets another turn
     * shortly — routed back into resumeMigration() next time.
     */
    /**
     * @param array<string, mixed> $extraPayload what the resumed pass needs
     *        and the ordinary one does not — today, everything a portable
     *        restore has to finish once the schema matches the code.
     */
    private function scheduleMigrationResume(
        TaskContext $context,
        int $safetyBackupId,
        string $source,
        ?int $requestedBy,
        ?string $safetyDbDump = null,
        ?string $safetyZip = null,
        array $extraPayload = []
    ): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('core', 'restore_backup', 0, $extraPayload + [
            'resume_migration' => true,
            // **The paths, not only the id.** The restore that just ran
            // replaced the database — `backups` and `files` included — so
            // by the time this payload is read, the row recording the
            // safety copy is whatever the RESTORED database has under
            // that id, which is another backup or nothing at all. The
            // rollback then reports « aucune sauvegarde de sécurité n'a pu
            // être restaurée automatiquement, une intervention manuelle
            // est nécessaire » with a perfectly usable copy sitting on
            // disk beside it.
            //
            // The two files outlive any database, so they are what the
            // resume pass is given. The id stays for the pass queued by an
            // installation upgraded mid-restore, whose payload predates
            // this and has nothing else to go on.
            'safety_backup_id' => $safetyBackupId,
            'safety_db_dump_path' => $safetyDbDump,
            'safety_zip_path' => $safetyZip,
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

        (new \Core\Maintenance\BackupRetention(
            $backupRepository,
            $fileRepository,
            $context->storagePath,
            $context->settings,
            \Core\Maintenance\BackupSafetyNet::forPdo($context->connection->getPdo())
        ))->purgeAfterCreating('auto_reset');

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
            'warning',
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
                'warning',
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
                'error',
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

    private function relativePath(string $storagePath, string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($storagePath)), '/');
    }
}
