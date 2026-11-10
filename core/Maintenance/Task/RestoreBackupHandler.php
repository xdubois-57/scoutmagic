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
use Core\Maintenance\BackupPasswords;
use Core\Maintenance\BackupPresence;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\ConfigOnlyRestore;
use Core\Maintenance\Portable\PortableArchive;
use Core\Maintenance\Portable\PortableRestore;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\RequesterNotice;
use Core\Maintenance\Restore\RestoreStateSnapshot;
use Core\Maintenance\RestoreArchiveCheck;
use Core\Maintenance\VersionFile;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Storage\DiskBudget;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationRepository;
use Core\Statistics\InstallationIdentityService;
use Core\Storage\Location\DeclaredStorageDirectories;
use Core\Storage\Location\Restore\StorageBindingReconciler;
use Core\Storage\Location\Restore\StorageBindingRepository;
use Core\Storage\Location\Restore\StorageBindingSnapshot;

/**
 * Background "Restaurer une sauvegarde" — scheduled by Core\Http\Controller\
 * MaintenanceController::restoreBackup() after its own server-side keyword
 * confirmation. Two sources (module spec):
 *
 * - `server`: an existing `backups` row (payload['backup_id']) — its
 *   dbDumpFileId is always an unencrypted dump (every backup-producing path
 *   in this codebase registers one), its fileId is only password-protected
 *   for the user-triggered 'full_config'/'full_no_gallery'/
 *   'full_with_gallery' types (Task\CreateBackupHandler), never for the
 *   automatic 'auto_update'/'auto_reset'/'auto_restore'/'database' types.
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

    /**
     * The type of the copy taken just before a restore (#847, part 3) —
     * its own, so the list can say what it is and offer to undo the
     * restore from it.
     */
    public const SAFETY_TYPE = 'auto_restore';

    private const TYPE_COMPLETED = 'core.restore_completed';
    private const TYPE_FAILED = 'core.restore_failed';

    /**
     * The types written encrypted, read only when the archive itself
     * cannot be asked ({@see archiveIsEncrypted()}). `database` since
     * IT-04, when the dump-only scope became an encrypted archive too.
     *
     * @var string[]
     */
    private const ENCRYPTED_BACKUP_TYPES = ['full_config', 'full_no_gallery', 'full_with_gallery', 'database'];

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = isset($payload['requested_by_user_account_id'])
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
            RequesterNotice::sendAboutRestore(
                $context,
                $requestedBy,
                self::TYPE_FAILED,
                'Restauration impossible',
                'Une sauvegarde portable sert à repartir sur une installation neuve, à qui vous la téléversez '
                . 'avec sa phrase de passe. Rien n\'a été modifié.'
            );

            return;
        }

        // **Also before the safety backup** (#847): an export holds no
        // files, and a backup whose archive or dump left the disk since it
        // was asked for cannot finish. The form refused both already; this
        // is for what changed between the request and this run.
        $refusal = $this->serverBackupRefusal($payload, $backupRepository, $fileRepository, $context->storagePath);
        if ($refusal !== null) {
            [$reason, $sentence] = $refusal;
            $context->journal->log(
                'core',
                'backup_restore_refused',
                'warning',
                'Restauration refusée : la sauvegarde ne peut pas être restaurée sur ce serveur',
                ['backup_id' => (int) ($payload['backup_id'] ?? 0), 'reason' => $reason],
                $requestedBy
            );
            RequesterNotice::sendAboutRestore(
                $context,
                $requestedBy,
                self::TYPE_FAILED,
                'Restauration impossible',
                $sentence
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
            new DiskBudget($context->storagePath, $context->settings),
            DeclaredStorageDirectories::fromDatabase(
                $context->connection->getPdo(),
                $context->encryption,
                $context->storagePath
            )
        );

        $safetyDbDump = null;
        $safetyZip = null;
        $bindingsHold = null;
        $stateHold = null;
        $restoredJournalMaxId = 0;
        $restoredFrom = null;
        $safetyBackupId = 0;

        // Just before the safety copy (#847, part 3) — after every refusal,
        // so a refused restore really modified nothing, and before the dump,
        // so a rollback never brings back a send pointing at an archive
        // deleted here. An off-site send under way would otherwise carry on
        // against a database about to be replaced. Not for « Configuration
        // seule », which replaces no database.
        if (!$this->isConfigOnly($payload, $backupRepository)) {
            $this->interruptRemoteSend($context, $requestedBy);
        }

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

                if ($this->isConfigOnly($payload, $backupRepository)) {
                    // « Configuration seule » brings back the settings and
                    // the modules' enabled state, nothing else (#847, part
                    // 2): the database is not replaced, no file is
                    // extracted, and a task queued against the unit's data
                    // keeps its sense — except one of a module this switches
                    // off.
                    $applied = (new ConfigOnlyRestore($pdo))->apply($restoreDbDumpPath);
                    $this->cancelTasksOfModules($context, $applied['disabled'], $requestedBy);
                } else {
                    // Today's storage bindings, kept on disk before the
                    // database that holds them is replaced (#832) — put back
                    // on the pass that finishes the migration.
                    $bindingsHold = $this->holdStorageBindingsAside($context);
                    // And today's list of backups, journal and off-site
                    // settings, the same way (#847, part 3) — after the
                    // safety copy, so its row is part of them.
                    $stateHold = RestoreStateSnapshot::holdAside($pdo, $context->storagePath);
                    $todaysTasks = $this->oneShotTasks($pdo);
                    $restoredFrom = $this->restoredFrom($payload, $backupRepository);

                    $backupService->restoreDatabase($restoreDbDumpPath);
                    $restoredJournalMaxId = RestoreStateSnapshot::restoredJournalMaxId($pdo);
                    $this->startTasksClean(
                        $context,
                        $todaysTasks,
                        $requestedBy,
                        (int) ($payload['scheduled_action_id'] ?? 0)
                    );
                    \Core\Security\SessionGeneration::invalidateRestoredDatabase($context->connection->getPdo());
                    if ($restoreZipPath !== null) {
                        $backupService->restoreFiles($restoreZipPath, $password);
                    }
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
                    $safetyZip,
                    [
                        'storage_bindings_hold' => $bindingsHold,
                        'storage_bindings_same_installation' => true,
                        'restore_state_hold' => $stateHold,
                        'restored_journal_max_id' => $restoredJournalMaxId,
                        'restored_from' => $restoredFrom,
                    ]
                );

                return;
            } catch (\Throwable $restoreError) {
                // The rollback puts back the database the bindings were
                // read from: there is nothing left to reconcile.
                if ($bindingsHold !== null) {
                    @unlink($bindingsHold);
                }
                $this->rollbackToSafetyBackup(
                    $context,
                    $backupService,
                    (string) $safetyDbDump,
                    (string) $safetyZip,
                    $safetyBackupId,
                    $requestedBy,
                    $restoreError,
                    $stateHold
                );
                if ($stateHold !== null) {
                    @unlink($stateHold);
                }
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

            RequesterNotice::sendAboutRestore(
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
     * Today's storage bindings, written aside before the database holding
     * them is replaced (#832; {@see StorageBindingSnapshot}).
     *
     * Throws when they cannot be: the restore then rolls back rather than
     * go ahead and lose them.
     */
    private function holdStorageBindingsAside(TaskContext $context): string
    {
        return StorageBindingSnapshot::capture(new StorageBindingRepository($context->connection->getPdo()))
            ->holdAside($context->storagePath);
    }

    /**
     * The storage half of a resume payload, carried like the portable one.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function bindingsPayload(array $payload): array
    {
        if (!is_string($payload['storage_bindings_hold'] ?? null)) {
            return [];
        }

        return [
            'storage_bindings_hold' => $payload['storage_bindings_hold'],
            'storage_bindings_same_installation' => ($payload['storage_bindings_same_installation'] ?? false) === true,
        ];
    }

    /**
     * Puts today's storage bindings back over the backup's (#832), once
     * the restored schema is current — {@see StorageBindingReconciler}.
     *
     * **Never a reason to undo the restore.** The database and the files
     * have arrived; a reconciliation that fails leaves the backup's
     * bindings in place, which is where every restore stood before #832,
     * and the journal says so for the administrator to check the
     * locations by hand.
     *
     * @param array<string, mixed> $payload
     */
    private function reconcileStorageBindings(array $payload, TaskContext $context, ?int $requestedBy): void
    {
        $hold = $payload['storage_bindings_hold'] ?? null;
        if (!is_string($hold)) {
            // A restore queued before #832: nothing was kept.
            return;
        }

        try {
            $before = StorageBindingSnapshot::readHeldAside($hold);
            if ($before === null) {
                throw new \RuntimeException('Le relevé des emplacements d\'avant la restauration est illisible.');
            }

            $report = (new StorageBindingReconciler(
                new StorageBindingRepository($context->connection->getPdo(), $context->encryption)
            ))->reconcile($before, ($payload['storage_bindings_same_installation'] ?? false) === true);

            if ($report !== []) {
                $context->journal->log(
                    'storage',
                    'storage_bindings_reconciled',
                    'warning',
                    'Emplacements de stockage réconciliés après la restauration',
                    ['locations' => $report],
                    $requestedBy
                );
            }
        } catch (\Throwable $e) {
            $context->journal->log(
                'storage',
                'storage_bindings_reconcile_failed',
                'warning',
                'Les emplacements de stockage n\'ont pas pu être réconciliés après la restauration : ils sont '
                    . 'tels que la sauvegarde les contenait. Vérifiez-les sur Configuration › Stockage.',
                ['error' => $e->getMessage()],
                $requestedBy
            );
        } finally {
            @unlink($hold);
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
            // The PATH of the target's own keys, held aside on disk. A
            // restore needing three passes would otherwise arrive at the
            // last one with nothing to put back if it failed.
            'portable_secrets_hold' => $payload['portable_secrets_hold'] ?? null,
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
            RequesterNotice::sendAboutRestore(
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
            new DiskBudget($context->storagePath, $context->settings),
            DeclaredStorageDirectories::fromDatabase(
                $context->connection->getPdo(),
                $context->encryption,
                $context->storagePath
            )
        );
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        $targetBaseUrl = $context->settings->get('base_url');

        // As on the ordinary path: after the archive's own checks above,
        // just before the safety copy.
        $this->interruptRemoteSend($context, $requestedBy);

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
            RequesterNotice::sendAboutRestore(
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

        // And held on the DISK as well, because the migration is deferred:
        // a failure on the resume pass happens in another process, hours
        // later, with this snapshot long gone — and would roll the database
        // and the files back while leaving the ARCHIVE's keys in place. The
        // path travels in the payload; the key material does not.
        $secretsHold = $restore->holdSecretsAside($secretsBefore);
        $bindingsHold = null;
        $stateHold = null;

        try {
            // As on the ordinary path (#832) — and matched by id only when
            // the archive is this installation's own: another
            // installation's location 2 is not this one's.
            $bindingsHold = $this->holdStorageBindingsAside($context);
            $stateHold = RestoreStateSnapshot::holdAside($pdo, $context->storagePath);
            $todaysTasks = $this->oneShotTasks($pdo);
            $ownIdentity = $context->settings->get(InstallationIdentityService::INSTALLATION_ID_SETTING);
            $sameInstallation = is_string($ownIdentity) && $ownIdentity !== ''
                && $ownIdentity === $archive->originInstallationId();

            $restore->apply($archive, $backupService, $targetOwnedSecrets, $context->connection->getPdo());
            $restoredJournalMaxId = RestoreStateSnapshot::restoredJournalMaxId($pdo);
            $this->startTasksClean(
                        $context,
                        $todaysTasks,
                        $requestedBy,
                        (int) ($payload['scheduled_action_id'] ?? 0)
                    );

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
                    'portable_secrets_hold' => $secretsHold,
                    'storage_bindings_hold' => $bindingsHold,
                    'storage_bindings_same_installation' => $sameInstallation,
                    'restore_state_hold' => $stateHold,
                    'restored_journal_max_id' => $restoredJournalMaxId,
                ]
            );
        } catch (\Throwable $restoreError) {
            if ($bindingsHold !== null) {
                @unlink($bindingsHold);
            }
            // Before the rollback, not after: rollbackToSafetyBackup()
            // extracts the safety archive over the file tree, and the
            // installation it hands back must be the one that was here —
            // keys included, or it cannot read what it just recovered.
            $restore->restoreSecretsSnapshot($secretsBefore);
            @unlink($secretsHold);

            $this->rollbackToSafetyBackup(
                $context,
                $backupService,
                $safety['dbDump'],
                $safety['zip'],
                $safety['id'],
                $requestedBy,
                $restoreError,
                $stateHold
            );
            if ($stateHold !== null) {
                @unlink($stateHold);
            }
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
        $backupService->ensureRoomForDumpAndArchive();

        // Encrypted at its creation like every archive (issue #619,
        // IT-03b), its password kept under the row — which is also where
        // the rollback finds it, even after the database was replaced:
        // secrets.enc is never part of a restore.
        $safetyPassword = BackupPasswords::forStorage($context->storagePath)->forSafetyCopy($backupService);
        $safetyDbDump = $backupService->createDatabaseDump();
        $safetyZip = $backupService->createFileBackup($safetyPassword);

        $safetyBackupId = $backupRepository->create(self::SAFETY_TYPE, $requestedBy);
        BackupPasswords::forStorage($context->storagePath)->keepFor($safetyBackupId, $safetyPassword);
        $safetyZipFileId = $fileRepository->create(
            $this->relativePath($context->storagePath, $safetyZip),
            'sauvegarde.zip',
            'application/zip',
            (int) filesize($safetyZip),
            \Core\Maintenance\Backup::FILE_ROLE,
            null,
            $requestedBy
        );
        $safetyDbFileId = $fileRepository->create(
            $this->relativePath($context->storagePath, $safetyDbDump),
            'database.sql',
            'application/sql',
            (int) filesize($safetyDbDump),
            \Core\Maintenance\Backup::FILE_ROLE,
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
     * Why a backup of this server cannot be restored, as `[reason, sentence]`
     * — `export_only` or `files_missing` — or null. Upload restores are not
     * concerned: their archive is the file just received.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string}|null
     */
    private function serverBackupRefusal(
        array $payload,
        BackupRepository $backupRepository,
        FileRepository $fileRepository,
        string $storagePath
    ): ?array {
        if (($payload['source'] ?? 'server') !== 'server' || !isset($payload['backup_id'])) {
            return null;
        }
        $backup = $backupRepository->findById((int) $payload['backup_id']);
        if ($backup === null) {
            return null;
        }
        if ($backup->type === Backup::EXPORT_TYPE) {
            return ['export_only', BackupPresence::EXPORT_REFUSAL];
        }
        $sentence = (new BackupPresence($fileRepository, $storagePath))->refusal($backup);

        return $sentence === null ? null : ['files_missing', $sentence];
    }

    /**
     * Whether this payload names a « Configuration seule » backup of this
     * server — the one restore that touches only `settings` and
     * `module_registry` (ConfigOnlyRestore).
     *
     * @param array<string, mixed> $payload
     */
    private function isConfigOnly(array $payload, BackupRepository $backupRepository): bool
    {
        if (($payload['source'] ?? 'server') !== 'server' || !isset($payload['backup_id'])) {
            return false;
        }

        return $backupRepository->findById((int) $payload['backup_id'])?->type === 'full_config';
    }

    /**
     * The queued and running tasks of modules a configuration restore
     * switched off, canceled and counted per task in the journal: their
     * handler is no longer loaded, and they would only fail.
     *
     * @param list<string> $moduleIds
     */
    private function cancelTasksOfModules(TaskContext $context, array $moduleIds, ?int $requestedBy): void
    {
        if ($moduleIds === []) {
            return;
        }
        $pdo = $context->connection->getPdo();
        $placeholders = implode(', ', array_fill(0, count($moduleIds), '?'));
        $count = $pdo->prepare(
            "SELECT module_id, task_key, COUNT(*) AS n FROM scheduled_actions
             WHERE module_id IN ({$placeholders}) AND status IN ('pending', 'processing')
             GROUP BY module_id, task_key"
        );
        $count->execute($moduleIds);
        $counts = [];
        foreach ($count->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[$row['module_id'] . '/' . $row['task_key']] = (int) $row['n'];
        }
        if ($counts === []) {
            return;
        }

        $pdo->prepare(
            "UPDATE scheduled_actions SET status = 'canceled'
             WHERE module_id IN ({$placeholders}) AND status IN ('pending', 'processing')"
        )->execute($moduleIds);

        $context->journal->log(
            'core',
            'restore_tasks_canceled',
            'warning',
            'Tâches abandonnées : leur module a été désactivé par la restauration',
            ['tasks' => $counts],
            $requestedBy
        );
    }

    /**
     * Stops an off-site send that is under way (#847, part 3): its local
     * archive — which carries `master.key` — is deleted, the half-sent
     * copy at the destination is discarded, and the chain goes on with an
     * empty payload. Without this the send resumed against a database
     * about to be replaced, and the restored database no longer knew the
     * archive it would have been carrying.
     *
     * Best-effort on the destination, like the send's own abandonment: a
     * destination that cannot be reached now cannot be cleaned by anyone.
     */
    private function interruptRemoteSend(TaskContext $context, ?int $requestedBy): void
    {
        $pdo = $context->connection->getPdo();
        $live = $pdo->prepare(
            "SELECT id, payload FROM scheduled_actions
             WHERE module_id = 'core' AND task_key = ? AND status IN ('pending', 'processing')"
        );
        $live->execute([SendRemoteBackupHandler::TASK_KEY]);
        $clear = $pdo->prepare('UPDATE scheduled_actions SET payload = NULL WHERE id = ?');

        foreach ($live->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = is_string($row['payload'] ?? null) ? json_decode((string) $row['payload'], true) : null;
            $archivePath = is_array($payload) ? (string) ($payload['archive_path'] ?? '') : '';
            if ($archivePath === '') {
                continue;
            }
            $removed = !is_file($archivePath) || @unlink($archivePath);
            $discarded = false;
            $remoteName = (string) ($payload['remote_name'] ?? '');
            $backend = $remoteName !== '' ? $this->remoteDestination($context)
                ->backendFor((int) ($payload['location_id'] ?? 0)) : null;
            if ($backend !== null) {
                try {
                    $backend->discardPartial($remoteName);
                    $discarded = true;
                } catch (\Throwable) {
                    $discarded = false;
                }
            }
            $clear->execute([(int) $row['id']]);
            $context->journal->log(
                'core',
                'remote_backup_interrupted',
                $removed ? 'info' : 'warning',
                $removed
                    ? 'Envoi hors site interrompu par une restauration'
                    : 'Envoi hors site interrompu par une restauration : son archive locale n\'a pas pu être '
                        . 'supprimée',
                ['local_copy_removed' => $removed, 'partial_discarded' => $discarded],
                $requestedBy
            );
        }
    }

    private function remoteDestination(TaskContext $context): RemoteBackupDestination
    {
        $repository = new StorageLocationRepository($context->connection->getPdo(), $context->encryption);

        return new RemoteBackupDestination(
            $context->settings,
            $repository,
            new StorageBackendFactory($repository, $context->storagePath)
        );
    }

    /**
     * Today's queued one-shot tasks, counted per task before the database
     * that holds them is replaced: they are lost with it, and the journal
     * says which. The restore itself is not one of them.
     *
     * @return array<string, int> "module/task" => count
     */
    private function oneShotTasks(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT module_id, task_key, COUNT(*) AS n FROM scheduled_actions
             WHERE status IN ('pending', 'processing') AND reference IS NULL
               AND NOT (module_id = 'core' AND task_key = 'restore_backup')
             GROUP BY module_id, task_key"
        );
        $counts = [];
        foreach ($stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $counts[$row['module_id'] . '/' . $row['task_key']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The tasks of the restored database start from a clean state (#847,
     * part 3), right after it is put in place.
     *
     * - A task that was running at the restored date is not running now:
     *   left `processing`, it would be reclaimed and replayed against a
     *   database it was never meant for. Every live row is canceled.
     * - A recurring chain (a row with a reference) is re-armed at once with
     *   a fresh row: same task, same reference, same date, and an empty
     *   payload — what a run carried then (an off-site archive being sent,
     *   a cursor) describes files and rows that are not today's.
     * - A one-shot task of the restored date is abandoned, as are today's
     *   ({@see oneShotTasks()}); both are counted in the journal.
     *
     * - The row of THIS restore comes back under its own id, still
     *   running: the scheduler marks it done by that id once this pass
     *   returns, and in the restored table the id was free — a re-armed
     *   chain or the resume pass would have taken it and been marked done
     *   without ever running.
     *
     * @param array<string, int> $todaysOneShots
     */
    private function startTasksClean(
        TaskContext $context,
        array $todaysOneShots,
        ?int $requestedBy,
        int $runningTaskId
    ): void {
        $pdo = $context->connection->getPdo();
        $stmt = $pdo->query(
            "SELECT id, module_id, task_key, reference, run_at FROM scheduled_actions
             WHERE status IN ('pending', 'processing') ORDER BY id"
        );
        $live = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $chains = [];
        $restoredOneShots = [];
        foreach ($live as $row) {
            $key = $row['module_id'] . '/' . $row['task_key'];
            if ($row['reference'] !== null) {
                // One fresh row per chain, even where the restored date
                // had a duplicate.
                $chains[$key . '/' . $row['reference']] ??= $row;
            } else {
                $restoredOneShots[$key] = ($restoredOneShots[$key] ?? 0) + 1;
            }
        }

        $pdo->exec("UPDATE scheduled_actions SET status = 'canceled' WHERE status IN ('pending', 'processing')");
        if ($runningTaskId > 0) {
            $taken = $pdo->prepare('SELECT 1 FROM scheduled_actions WHERE id = ?');
            $taken->execute([$runningTaskId]);
            if ($taken->fetchColumn() === false) {
                $pdo->prepare(
                    "INSERT INTO scheduled_actions (id, module_id, task_key, run_at, status, claimed_at)
                     VALUES (?, 'core', 'restore_backup', ?, 'processing', ?)"
                )->execute([$runningTaskId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            }
        }
        $rearm = $pdo->prepare(
            "INSERT INTO scheduled_actions (module_id, task_key, reference, payload, run_at, status)
             VALUES (?, ?, ?, NULL, ?, 'pending')"
        );
        foreach ($chains as $row) {
            $rearm->execute([$row['module_id'], $row['task_key'], $row['reference'], $row['run_at']]);
        }

        $context->journal->log(
            'core',
            'restore_tasks_reset',
            $todaysOneShots === [] && $restoredOneShots === [] ? 'info' : 'warning',
            'Tâches planifiées remises à zéro par la restauration',
            [
                'chains_rearmed' => count($chains),
                'todays_tasks_lost' => $todaysOneShots,
                'restored_tasks_abandoned' => $restoredOneShots,
            ],
            $requestedBy
        );
    }

    /**
     * What a resume pass carries of the held state.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function statePayload(array $payload): array
    {
        return [
            'restore_state_hold' => $payload['restore_state_hold'] ?? null,
            'restored_journal_max_id' => $payload['restored_journal_max_id'] ?? 0,
            'restored_from' => $payload['restored_from'] ?? null,
        ];
    }

    /**
     * The date of the backup restored from this server, in French, for the
     * journal and the « Restauration terminée » message — read before the
     * database that knows it is replaced. Null for an uploaded archive.
     *
     * @param array<string, mixed> $payload
     */
    private function restoredFrom(array $payload, BackupRepository $backupRepository): ?string
    {
        if (($payload['source'] ?? 'server') !== 'server' || !isset($payload['backup_id'])) {
            return null;
        }
        $backup = $backupRepository->findById((int) $payload['backup_id']);

        return $backup === null ? null : \Core\View\DateFilterExtension::frenchDate($backup->createdAt)
            . ' à ' . \Core\View\DateFilterExtension::timeFr($backup->createdAt);
    }

    /**
     * Puts today's backups, files, journal and off-site settings back on
     * the restored database (#847, part 3) — {@see RestoreStateSnapshot}.
     *
     * A portable archive comes from another server or another date of this
     * one: this server's off-site set is kept when it had a destination,
     * and the archives' passwords become today's, since the list they
     * open is today's.
     *
     * @param array<string, mixed> $payload
     */
    private function putBackState(array $payload, TaskContext $context, ?int $requestedBy): void
    {
        $holdPath = $payload['restore_state_hold'] ?? null;
        if (!is_string($holdPath) || $holdPath === '') {
            // A pass queued before this existed: nothing was held.
            return;
        }
        if (!is_file($holdPath)) {
            throw new \RuntimeException('L\'état du site mis de côté avant la restauration est introuvable.');
        }

        $pdo = $context->connection->getPdo();
        $portable = ($payload['portable'] ?? false) === true;
        $keepRemote = true;
        if ($portable) {
            $held = RestoreStateSnapshot::heldSettings($holdPath);
            $keepRemote = (string) ($held[RemoteBackupDestination::LOCATION_SETTING] ?? '') !== '';
        }

        $kept = RestoreStateSnapshot::putBack(
            $pdo,
            $holdPath,
            (int) ($payload['restored_journal_max_id'] ?? 0),
            $keepRemote
        );
        if ($portable && is_string($payload['portable_secrets_hold'] ?? null)) {
            $this->keepTodaysBackupSecrets((string) $payload['portable_secrets_hold'], $context, $keepRemote);
        }

        $context->journal->log(
            'core',
            'restore_state_kept',
            'info',
            'Liste des sauvegardes, journal et réglages hors site d\'aujourd\'hui conservés',
            $kept + ['remote_settings_kept' => $keepRemote],
            $requestedBy
        );
    }

    /**
     * The passwords of today's archives, read from the secrets held aside
     * before a portable restore and written over the archive's: the rows
     * just put back are today's, and the archive's passwords are those of
     * another list. The off-site passphrase follows the off-site settings.
     */
    private function keepTodaysBackupSecrets(string $secretsHoldPath, TaskContext $context, bool $keepRemote): void
    {
        $raw = is_file($secretsHoldPath) ? @file_get_contents($secretsHoldPath) : false;
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('Les clés de ce site mises de côté avant la restauration sont illisibles.');
        }
        $member = static function (string $suffix) use ($decoded): ?string {
            foreach ($decoded as $target => $contents) {
                if (is_string($target) && str_ends_with($target, $suffix) && is_string($contents)) {
                    $bytes = base64_decode($contents, true);

                    return $bytes === false ? null : $bytes;
                }
            }

            return null;
        };
        $masterKey = $member('keys/master.key');
        $secrets = $member('config/secrets.enc');
        if ($masterKey === null || $secrets === null) {
            // A fresh installation had no secrets, so no archive of its own.
            return;
        }

        $directory = $context->storagePath . '/temp/held_secrets_' . bin2hex(random_bytes(8));
        if (!@mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Le dossier temporaire du site n\'a pas pu être créé.');
        }
        try {
            foreach (['master.key' => $masterKey, 'secrets.enc' => $secrets] as $file => $bytes) {
                // The site's own key in clear: 0600, as everywhere it is held.
                if (@file_put_contents($directory . '/' . $file, $bytes) === false) {
                    throw new \RuntimeException('Les clés de ce site n\'ont pas pu être relues.');
                }
                if (PHP_OS_FAMILY !== 'Windows') {
                    @chmod($directory . '/' . $file, 0600);
                }
            }
            $today = (new SecretManager($directory . '/master.key', $directory . '/secrets.enc'))->readSecrets();
        } finally {
            @unlink($directory . '/master.key');
            @unlink($directory . '/secrets.enc');
            @rmdir($directory);
        }

        $isPassword = static fn(string $key): bool => preg_match('/^backup_password_\d+$/', $key) === 1;
        (new SecretManager(
            $context->storagePath . '/keys/master.key',
            $context->storagePath . '/config/secrets.enc'
        ))->updateSecrets(static function (array $current) use ($today, $isPassword, $keepRemote): array {
            foreach (array_keys($current) as $key) {
                if ($isPassword((string) $key)) {
                    unset($current[$key]);
                }
            }
            foreach ($today as $key => $value) {
                if ($isPassword((string) $key)) {
                    $current[$key] = $value;
                }
            }
            if ($keepRemote && array_key_exists(RemotePassphrase::SECRET_KEY, $today)) {
                $current[RemotePassphrase::SECRET_KEY] = $today[RemotePassphrase::SECRET_KEY];
            }

            return $current;
        });
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
        $stateKeepFailed = false;

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
                    // under the old one's identity. The storage bindings
                    // likewise, or they would never be put back.
                    $this->portablePayload($payload) + $this->bindingsPayload($payload) + $this->statePayload($payload)
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

            $this->reconcileStorageBindings($payload, $context, $requestedBy);

            // After the bindings, before the purge (#847, part 3). A
            // failure here throws to the rollback below: a list from the
            // restored date would have lost the safety copy, the one way
            // to undo this restore.
            try {
                $this->putBackState($payload, $context, $requestedBy);
            } catch (\Throwable $stateError) {
                $stateKeepFailed = true;
                throw $stateError;
            }

            $this->finishRestore(
                $context,
                $backupRepository,
                $fileRepository,
                $source,
                $requestedBy,
                $safetyBackupId,
                is_string($payload['restored_from'] ?? null) ? (string) $payload['restored_from'] : null
            );
            if (is_string($payload['restore_state_hold'] ?? null)) {
                @unlink((string) $payload['restore_state_hold']);
            }

            // Keep the target's keys until every fallible completion step,
            // including the session-generation write, has succeeded.
            if (($payload['portable'] ?? false) === true && is_string($payload['portable_secrets_hold'] ?? null)) {
                @unlink((string) $payload['portable_secrets_hold']);
            }
        } catch (\Throwable $migrationError) {
            if (is_string($payload['storage_bindings_hold'] ?? null)) {
                @unlink((string) $payload['storage_bindings_hold']);
            }
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
                RequesterNotice::sendAboutRestore(
                    $context,
                    $requestedBy,
                    self::TYPE_FAILED,
                    'Échec critique de la restauration',
                    'La migration a échoué et aucune sauvegarde de sécurité n\'a pu être '
                    . 'restaurée automatiquement. Une intervention manuelle est nécessaire.'
                );
                return;
            }

            // Before the rollback, exactly as on the synchronous path and
            // for the same reason: the safety archive structurally cannot
            // carry storage/keys/ or storage/config/, so an installation
            // handed its own database back with the ARCHIVE's key would be
            // unable to read a single encrypted column — while the journal
            // reported a clean recovery.
            if (is_string($payload['portable_secrets_hold'] ?? null)) {
                (new PortableRestore($basePath, $context->storagePath))
                    ->restoreSecretsHeldAside((string) $payload['portable_secrets_hold']);
            }

            $backupService = new BackupService(
                $context->connection,
                $context->storagePath,
                $basePath,
                new DiskBudget($context->storagePath, $context->settings),
                DeclaredStorageDirectories::fromDatabase(
                    $context->connection->getPdo(),
                    $context->encryption,
                    $context->storagePath
                )
            );
            $stateHold = is_string($payload['restore_state_hold'] ?? null)
                ? (string) $payload['restore_state_hold']
                : null;
            $this->rollbackToSafetyBackup(
                $context,
                $backupService,
                $safetyDbDumpPath,
                $safetyZipPath,
                $safetyBackupId,
                $requestedBy,
                $migrationError,
                $stateHold
            );
            if ($stateKeepFailed) {
                // After the rollback, which puts back a journal older than
                // this line. No path and no name.
                $context->journal->log(
                    'core',
                    'restore_state_keep_failed',
                    'error',
                    'L\'état d\'aujourd\'hui n\'a pas pu être remis en place : la restauration a été annulée',
                    ['error' => $migrationError::class],
                    $requestedBy
                );
            }
            if ($stateHold !== null) {
                @unlink($stateHold);
            }
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
        $pdo = $context->connection->getPdo();
        // Queued in the RESTORED database, which may predate the account
        // that asked for the restore: its foreign key would refuse the row,
        // and the whole restore would roll back for it.
        if ($requestedBy !== null) {
            $known = $pdo->prepare('SELECT 1 FROM user_accounts WHERE id = ?');
            $known->execute([$requestedBy]);
            $requestedBy = $known->fetchColumn() !== false ? $requestedBy : null;
        }
        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->scheduleAfter(
            'core',
            'restore_backup',
            0,
            $extraPayload + [
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
            ],
            null,
            $requestedBy
        );
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
        ?int $requestedBy,
        int $safetyBackupId = 0,
        ?string $restoredFrom = null
    ): void {
        // The resumed pass has finished migrating the restored schema.
        // Use fresh settings, not the pre-restore request's cached values.
        (new \Core\Security\SessionGeneration(new \Core\Config\SettingService(
            new \Core\Config\SettingRepository($context->connection->getPdo())
        )))->rotate();

        $context->journal->log(
            'core',
            'backup_restored',
            'info',
            'Sauvegarde restaurée',
            ['source' => $source, 'restored_from' => $restoredFrom],
            $requestedBy
        );

        (new \Core\Maintenance\BackupRetention(
            $backupRepository,
            $fileRepository,
            $context->storagePath,
            $context->settings,
            \Core\Maintenance\BackupSafetyNet::forPdo($context->connection->getPdo())
        // The copy of THIS restore is never the one evicted: it is what
        // « Annuler cette restauration » restores (#847, part 3).
        ))->purgeAfterCreating(self::SAFETY_TYPE, $safetyBackupId > 0 ? $safetyBackupId : null);

        // A copy of the restored state leaves at once rather than at the
        // next daily slot, when a destination is chosen.
        $freshSettings = new \Core\Config\SettingService(
            new \Core\Config\SettingRepository($context->connection->getPdo())
        );
        if ((int) ($freshSettings->get(RemoteBackupDestination::LOCATION_SETTING) ?: 0) > 0) {
            $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
            $scheduler->cancelPending('core', SendRemoteBackupHandler::TASK_KEY, SendRemoteBackupHandler::REFERENCE);
            $scheduler->scheduleAfter(
                'core',
                SendRemoteBackupHandler::TASK_KEY,
                0,
                [],
                SendRemoteBackupHandler::REFERENCE
            );
        }

        RequesterNotice::sendAboutRestore(
            $context,
            $requestedBy,
            self::TYPE_COMPLETED,
            'Restauration terminée',
            $restoredFrom !== null
                ? 'Restauration terminée. Le site est revenu à la sauvegarde du ' . $restoredFrom . '.'
                : 'La restauration de la sauvegarde est terminée.'
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
        int $safetyBackupId,
        ?int $requestedBy,
        \Throwable $error,
        ?string $stateHold = null
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
            // By the safety copy's id, never its row: the row may be gone
            // with the database this restore replaced, while secrets.enc,
            // which no restore touches, still holds the password (IT-03b).
            // Read first, so that a secrets file that cannot be read stops
            // the rollback before it changes the database.
            $safetyPassword = $safetyBackupId > 0
                ? BackupPasswords::forStorage($context->storagePath)->passwordFor($safetyBackupId)
                : null;
            $backupService->restoreDatabase($safetyDbDump);
            $backupService->restoreFiles($safetyZip, $safetyPassword);
            // The dump the rollback put back was taken before the safety
            // copy's own row existed: without this, its files would stay on
            // the disk with no row, never purged and never offered (#847).
            RestoreStateSnapshot::reregisterSafetyCopy(
                $context->connection->getPdo(),
                $stateHold,
                $safetyBackupId,
                $safetyDbDump,
                $safetyZip,
                $context->storagePath,
                self::SAFETY_TYPE
            );
            $context->journal->log(
                'core',
                'backup_restore_rolled_back',
                'warning',
                'Restauration automatique de l\'état précédent effectuée après échec de restauration',
                [],
                $requestedBy
            );
            $notifyTitle = 'Échec de la restauration';
            $notifyBody = 'Échec de la restauration : le site est revenu à son état d\'avant, '
                . 'rien n\'a été perdu.';
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

        RequesterNotice::sendAboutRestore($context, $requestedBy, self::TYPE_FAILED, $notifyTitle, $notifyBody);
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

            // Checked again here even though the form already did (issue
            // #753): the worker is the last barrier before a destructive
            // operation, and says which of « not a backup », « password
            // missing » and « wrong password » it is.
            RestoreArchiveCheck::assertUploadOpens($zipPath, $password);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new BackupException('Le fichier uploadé n\'est pas un backup valide.');
            }
            if ($password !== null) {
                $zip->setPassword($password);
            }
            $dbSql = $zip->getFromName(RestoreArchiveCheck::DATABASE_MEMBER);
            $zip->close();
            if ($dbSql === false) {
                throw new BackupException('La base de données de cette sauvegarde est illisible.');
            }

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

        // An archive the backup declares and that cannot be found is
        // refused, never skipped (#847): restoring the database alone would
        // put back a date's database with today's files.
        $filesZipPath = null;
        if ($backup->fileId !== null) {
            $filesFile = $fileRepository->findById($backup->fileId);
            $filesZipPath = $filesFile !== null ? $storagePath . '/' . $filesFile->relativePath : null;
            if ($filesZipPath === null || !is_file($filesZipPath)) {
                throw new BackupException(
                    'L\'archive de cette sauvegarde n\'est plus sur ce serveur : elle ne peut plus être restaurée.'
                );
            }
        }

        // An archive of this server: the site kept the password it
        // generated for it (issue #619, IT-03) — safety copies included
        // since IT-03b — so nobody has to type it.
        $kept = BackupPasswords::forStorage($storagePath)->passwordFor($backup->id);
        if ($kept !== null) {
            return [$dbDumpPath, $filesZipPath, $kept, null];
        }

        // No kept password: an older archive whose operator typed one, or
        // one written in clear on a host that cannot encrypt (IT-04). The
        // archive itself says which — its type cannot, since the same
        // scope is encrypted on one host and clear on another. A row with
        // no archive at all (an old database export) has nothing to
        // decrypt; the type decides only when the archive cannot be read.
        $needsPassword = $filesZipPath !== null && (
            self::archiveIsEncrypted($filesZipPath)
            ?? in_array($backup->type, self::ENCRYPTED_BACKUP_TYPES, true)
        );
        // Said here, before the database is touched: found missing only at
        // extraction, it would cost a restore and a rollback.
        if ($needsPassword && $password === null) {
            throw new BackupException(
                'Le mot de passe de cette sauvegarde n\'est plus conservé sur ce serveur : '
                . 'téléversez l\'archive avec son mot de passe.'
            );
        }

        return [$dbDumpPath, $filesZipPath, $needsPassword ? $password : null, null];
    }

    /**
     * Whether any entry of the archive is encrypted, or null when it
     * cannot be read.
     */
    private static function archiveIsEncrypted(string $zipPath): ?bool
    {
        if (!is_file($zipPath)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat !== false && $stat['encryption_method'] !== \ZipArchive::EM_NONE) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    private function relativePath(string $storagePath, string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($storagePath)), '/');
    }
}
