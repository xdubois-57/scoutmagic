<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Maintenance\BackupService;
use Core\Maintenance\BackupServiceInterface;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Storage\DiskBudget;
use Core\Storage\Location\DeclaredStorageDirectories;

/**
 * Background "Réinitialisation complète" — scheduled by
 * Core\Http\Controller\MaintenanceController::fullReset() after its own
 * server-side double confirmation (keyword + checkbox). Wipes every table's
 * data, deletes secrets.enc, and empties storage/ except
 * storage/keys/master.key — after this runs, the next request hits the
 * setup wizard (ARCHITECTURE.md §9) exactly like a fresh install.
 *
 * **Except the storage locations, which survive it.** Since D10 no archive
 * carries a declared location, so the safety copy this handler takes in
 * step 1 no longer holds one either — and wiping them here would have
 * turned "reset the site" into "destroy the photographs, irrecoverably",
 * as a side effect nobody asked for. A location has a lifecycle of its own;
 * a reset of the site is not an event in it.
 *
 * No rollback: unlike Task\RestoreBackupHandler, a mid-wipe failure here is
 * not recoverable by design — the module spec accepts that the site may be
 * left in a broken, setup-wizard-bound state either way, which is the
 * intended end state of a successful run too.
 *
 * No push notification (module spec §3.7): push_subscriptions is wiped by
 * this same operation, so there is nothing left to notify by the time it
 * would matter.
 */
class FullResetHandler implements TaskHandlerInterface
{
    public function __construct(private ?BackupServiceInterface $backupService = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $basePath = dirname($context->storagePath);

        // **Read BEFORE anything is destroyed, and that is the whole
        // subtlety.** Step 2 truncates every table, `storage_locations`
        // among them, so asking afterwards which directories were
        // declared would answer "none" — and step 4 would then delete
        // every one of them. Resolved once, here, and used twice: the
        // archive leaves them out (D10), and the wipe leaves them alone.
        $declaredLocations = DeclaredStorageDirectories::fromDatabase(
            $pdo,
            $context->encryption,
            $context->storagePath
        );

        $backupService = $this->backupService ?? new BackupService(
            $context->connection,
            $context->storagePath,
            $basePath,
            new DiskBudget($context->storagePath, $context->settings),
            $declaredLocations
        );

        $preserveDir = null;

        try {
            // Step 1: safety backup. Its two files are moved outside
            // storage/ first so the storage wipe (step 4) never touches
            // them — there is no `backups`/`files` row for them once the
            // DB is wiped (step 2), so they end up as orphaned files under
            // storage/maintenance/, retrievable only via FTP. That is the
            // literal, accepted trade-off of "keep the file, not the
            // bookkeeping" for a reset whose whole point is an empty DB.
            // Both writes reserved at once, against the reading they are
            // both sized on. This is the sharpest of the five sites that
            // take this pair: step 4 wipes `storage/` and step 2 empties
            // every table, so a safety backup truncated half-way through
            // is the only copy of a site that no longer exists.
            $backupService->ensureRoomForDumpAndArchive();

            $dbDumpPath = $backupService->createDatabaseDump();
            $filesZipPath = $backupService->createFileBackup();

            $preserveDir = sys_get_temp_dir() . '/scoutmagic_reset_preserve_' . bin2hex(random_bytes(8));
            mkdir($preserveDir, 0755, true);
            $preservedDbDump = $preserveDir . '/' . basename($dbDumpPath);
            $preservedZip = $preserveDir . '/' . basename($filesZipPath);
            rename($dbDumpPath, $preservedDbDump);
            rename($filesZipPath, $preservedZip);

            // Step 2: wipe every table's data.
            $this->truncateAllTables($pdo);

            // Step 3: delete secrets.enc (forces DB/SMTP reconfiguration at setup).
            @unlink($context->storagePath . '/config/secrets.enc');

            // Step 4: wipe storage/ except keys/master.key and every
            // directory declared as a storage location.
            //
            // **Those directories survive because nothing else would
            // bring them back.** Until D10 the safety copy taken in step 1
            // contained them, so wiping them was reversible; it no longer
            // does — a location has its own lifecycle and no archive
            // carries it — and deleting them here would have made this
            // operation quietly more destructive than it was, without
            // anybody asking for that. Between the two irreversible
            // readings, this one is the recoverable one: files left on a
            // disk can still be deleted by hand, and a reset is described
            // to the operator as putting the SITE back to a fresh
            // install (`maintenance.html.twig` says which folders stay).
            $this->removeTreeExcept(
                $context->storagePath,
                [$context->storagePath . '/keys/master.key', ...$declaredLocations->all()]
            );

            // Step 5: recreate the empty directory structure, and move the
            // preserved safety backup back under storage/maintenance/.
            foreach (['keys', 'config', 'temp', 'maintenance'] as $dir) {
                if (!is_dir($context->storagePath . '/' . $dir)) {
                    mkdir($context->storagePath . '/' . $dir, 0755, true);
                }
            }
            rename($preservedDbDump, $context->storagePath . '/maintenance/' . basename($preservedDbDump));
            rename($preservedZip, $context->storagePath . '/maintenance/' . basename($preservedZip));
            rmdir($preserveDir);
            $preserveDir = null;

            // Step 6: the first journal entry of the "new" installation —
            // user_accounts was just wiped, so user_account_id must be null
            // regardless of who originally requested this (the fk_el_user
            // constraint would otherwise reject a now-nonexistent id).
            $context->journal->log(
                'core',
                'full_reset_performed',
                'security',
                $this->resetSummary($declaredLocations)
            );
        } catch (\Throwable $e) {
            if ($preserveDir !== null) {
                @$this->removeTreeExcept($preserveDir, []);
                @rmdir($preserveDir);
            }
            // Best-effort: the journal table itself may already be wiped,
            // or the DB connection broken, depending on which step failed.
            try {
                $context->journal->log(
                    'core',
                    'full_reset_failed',
                    'security',
                    'Échec de la réinitialisation complète',
                    ['error' => $e->getMessage()]
                );
            } catch (\Throwable) {
                // Nothing more can be done from here.
            }
        }
    }

    private function truncateAllTables(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $pdo->exec('DELETE FROM "' . $table . '"');
            }
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * What the journal records, naming the folders that are still there.
     *
     * A reset whose entry said only « retour à l'état d'installation
     * neuve » would be read, by whoever comes to the journal after it, as
     * an empty disk — and the folders it left are exactly the ones with
     * something irreplaceable in them.
     */
    private function resetSummary(DeclaredStorageDirectories $declaredLocations): string
    {
        $kept = count($declaredLocations->all());
        if ($kept === 0) {
            return 'Réinitialisation complète effectuée — retour à l\'état d\'installation neuve';
        }

        return sprintf(
            'Réinitialisation complète effectuée — retour à l\'état d\'installation neuve. %s %s '
                . 'conservé%s : son contenu n\'est repris dans aucune archive.',
            $kept,
            $kept === 1 ? 'emplacement de stockage' : 'emplacements de stockage',
            $kept === 1 ? '' : 's'
        );
    }

    /**
     * @param string[] $preserve absolute paths never deleted
     */
    private function removeTreeExcept(string $dir, array $preserve): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (in_array($path, $preserve, true)) {
                continue;
            }

            if (is_dir($path)) {
                $this->removeTreeExcept($path, $preserve);
                $remaining = array_diff(scandir($path) ?: [], ['.', '..']);
                if ($remaining === []) {
                    @rmdir($path);
                }
            } else {
                unlink($path);
            }
        }
    }
}
