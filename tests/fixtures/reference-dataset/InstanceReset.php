<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Database\Connection;
use Core\Maintenance\BackupService;

/**
 * Emptying an instance that has already been built into, so the builder can
 * run again.
 *
 * `build.php` is destructive by design and refuses an installation that has
 * served (README §8). That refusal was a dead end: the only ways out were a
 * fresh install or restoring a backup taken at exactly the right moment. This
 * class is the third way — `--reset` — and it is the *same* refusal, just with
 * an answer attached: instead of merging into existing data, it removes the
 * existing data first.
 *
 * **It is not the application's "Réinitialisation complète".**
 * Core\Maintenance\Task\FullResetHandler deletes `secrets.enc` and wipes
 * `storage/` down to `keys/master.key`, which sends the site back to the setup
 * wizard — a state the builder cannot run against at all, since it opens the
 * instance through SecretManager (InstanceContext). What is wanted here is
 * narrower and is deliberately named differently: **the data goes, the
 * installation stays.**
 *
 * What it does, in order:
 *
 * 1. A safety database dump through BackupService::createDatabaseDump() — the
 *    application's own API, no `mysqldump` binary involved, and the same first
 *    step both FullResetHandler and SetupController::backupAndEmptyDatabase()
 *    take before destroying anything. The file lands in
 *    `storage/maintenance/` and its path is reported to the caller. A
 *    Core\Maintenance\BackupException here aborts the whole reset before a
 *    single row is touched — an unwritable `storage/` is exactly when you want
 *    the net. Skipping the dump takes a second explicit flag, precisely as the
 *    setup wizard demands a `force_without_backup` before emptying with none.
 * 2. `TRUNCATE` on every table — data only, and every table but two. The
 *    schema is left standing, which is what separates this from the setup
 *    wizard's `DROP TABLE`: nothing has to re-run the migration before the
 *    builder can write, and the target stays a configured, loggable-into
 *    installation throughout. AUTO_INCREMENT counters go back to 1, which is
 *    a feature here: two consecutive `--reset` builds produce the same
 *    identifiers.
 *
 *    The two survivors are `settings` and `module_registry`, and the list is
 *    not this class's invention: it is BackupService::CONFIG_ONLY_TABLES, the
 *    application's own reviewed answer to "which tables are pure site
 *    configuration, never member or business data". Emptying them would take
 *    the unit's name, its SMTP settings and — decisively — which modules are
 *    enabled, and the dataset needs the finance and calendar modules on to
 *    build anything. A reset that silently disables half the site is not a
 *    reset, it is a reinstall, and the application already has one of those.
 * 3. Uploaded files under `storage/` are removed, `keys/`, `config/` and
 *    `maintenance/` excepted. A truncated `files` table with the photo blobs
 *    still on disk is not a clean slate, it is an orphan pile — and the
 *    freshly written dump lives under `maintenance/`, so that directory is not
 *    negotiable.
 *
 * Writing SQL by hand is the one thing the builder never does — but a reset
 * has no service to go through: the application performs its own resets with
 * the very same two statements in FullResetHandler::truncateAllTables() and in
 * SetupController::backupAndEmptyDatabase(). This is the place where the
 * "always through the real services" rule has no service to name.
 */
final class InstanceReset
{
    /** Never removed by the storage wipe: without these the instance is no longer installed. */
    private const PRESERVED_STORAGE_DIRS = ['keys', 'config', 'maintenance'];

    /**
     * Never emptied: site configuration, not data.
     *
     * Deliberately the same two tables as BackupService::CONFIG_ONLY_TABLES —
     * if that whitelist ever grows a third, this one grows with it, and the
     * test that pins them together says so.
     */
    private const PRESERVED_TABLES = ['settings', 'module_registry'];

    /**
     * Settings that are execution STATE rather than configuration, and that a
     * preserved `settings` table would otherwise carry across the wipe.
     *
     * `settings` survives because it holds the unit's name, its SMTP settings
     * and — decisively — nothing this class could rebuild. But the same table
     * is also where the application parks the flags a real run leaves behind:
     * "the thumbnails have been backfilled", "the annual close has been
     * applied", "this is installation X", "the last cron pass was at T". Kept
     * across a wipe, every one of them makes a claim about data that no longer
     * exists — and the ones that gate a one-shot backfill make it *false*, so
     * the backfill an emptied instance needs never runs again.
     *
     * `current_scout_year_id` is the one that shows: the provisioning script
     * pins it on the single year it creates (id 1), the wipe restarts the
     * AUTO_INCREMENT counters, and the setting then designates whichever year
     * the builder happens to write first — 2024-2025. The site served the
     * oldest of its three years as the public one, group photos and all, and
     * the reference chief could not reach /config/banner.
     *
     * The suffixes are the rule; the explicit keys are what has no suffix.
     * A new one-shot flag named in the house style is covered on the day it is
     * written, which is the point — this list is not meant to be maintained by
     * remembering it.
     */
    private const STATE_SETTING_KEYS = ['current_scout_year_id'];

    /** @see self::STATE_SETTING_KEYS */
    private const STATE_SETTING_SUFFIXES = [
        '_backfilled',
        '_seeded',
        '_last_run',
        '_applied_on',
        '_installation_id',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $storagePath,
        private readonly string $basePath,
    ) {
    }

    /**
     * @param bool $withBackup false only on an explicit second confirmation
     * @return array{backupPath: ?string, backupError: ?string, tables: int, settings: int, files: int}
     * @throws \RuntimeException when the dump fails and no backup was waived
     */
    public function run(bool $withBackup = true): array
    {
        $backupPath = null;
        $backupError = null;

        if ($withBackup) {
            try {
                $backupPath = (new BackupService($this->connection, $this->storagePath, $this->basePath))
                    ->createDatabaseDump();
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    'la sauvegarde préalable a échoué (' . $exception->getMessage() . ') — '
                    . 'relancez avec --reset --no-backup pour vider quand même, en connaissance de cause.',
                    0,
                    $exception,
                );
            }
        } else {
            $backupError = 'sauvegarde explicitement désactivée (--no-backup)';
        }

        // Les deux dans cet ordre, et sur deux lignes plutôt qu'en valeurs du
        // tableau : la base d'abord, les fichiers ensuite. Un vidage
        // interrompu entre les deux laisse des fichiers orphelins, qui ne
        // cassent rien ; l'inverse laisserait des lignes `files` pointant vers
        // des fichiers disparus, ce que l'application, elle, sait mal vivre.
        $tables = $this->truncateAllTables();
        $settings = $this->purgeStateSettings();
        $files = $this->wipeUploadedFiles();

        return [
            'backupPath' => $backupPath,
            'backupError' => $backupError,
            'tables' => $tables,
            'settings' => $settings,
            'files' => $files,
        ];
    }

    /**
     * @return int the number of tables emptied
     */
    private function truncateAllTables(): int
    {
        $pdo = $this->connection->getPdo();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $statement = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            );
            $tables = $this->emptiable($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($tables as $table) {
                // SQLite has no TRUNCATE, and no AUTO_INCREMENT counter to
                // reset unless the table declares AUTOINCREMENT; DELETE is
                // what FullResetHandler uses here for the same reason.
                $pdo->exec('DELETE FROM "' . $table . '"');
            }

            return count($tables);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = [];
        try {
            $statement = $pdo->query('SHOW TABLES');
            $tables = $this->emptiable($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($tables as $table) {
                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            // A failure mid-sweep must not leave the connection with foreign
            // keys disabled: the builder runs on this same PDO right after.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return count($tables);
    }

    /**
     * Remove the state flags from the one data table this reset preserves.
     *
     * @return int the number of settings removed
     */
    private function purgeStateSettings(): int
    {
        $pdo = $this->connection->getPdo();
        $statement = $pdo->query('SELECT setting_key FROM settings');
        $keys = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);

        $removed = 0;
        $delete = $pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
        foreach ($keys as $key) {
            $key = (string) $key;
            if (!$this->isStateSetting($key)) {
                continue;
            }
            $delete->execute([$key]);
            $removed += $delete->rowCount();
        }

        return $removed;
    }

    /** Execution state parked in `settings`, as against site configuration. */
    private function isStateSetting(string $key): bool
    {
        if (in_array($key, self::STATE_SETTING_KEYS, true)) {
            return true;
        }

        foreach (self::STATE_SETTING_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $tables every table the driver reported
     * @return list<string> those this reset is allowed to empty
     */
    private function emptiable(array $tables): array
    {
        $names = array_map(static fn (mixed $table): string => (string) $table, $tables);

        return array_values(array_filter(
            $names,
            static fn (string $table): bool => !in_array($table, self::PRESERVED_TABLES, true),
        ));
    }

    /**
     * @return int the number of files removed
     */
    private function wipeUploadedFiles(): int
    {
        if (!is_dir($this->storagePath)) {
            return 0;
        }

        $removed = 0;
        foreach (scandir($this->storagePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::PRESERVED_STORAGE_DIRS, true)) {
                continue;
            }

            $path = $this->storagePath . '/' . $entry;
            if (is_dir($path)) {
                $removed += $this->removeTree($path);
                @rmdir($path);
                continue;
            }

            if (@unlink($path)) {
                $removed++;
            }
        }

        // `temp/` is expected to exist by the chunked-upload store and by the
        // upload pipeline the builder is about to drive; recreate it rather
        // than making every caller wonder why the first photo fails.
        if (!is_dir($this->storagePath . '/temp')) {
            mkdir($this->storagePath . '/temp', 0755, true);
        }

        return $removed;
    }

    private function removeTree(string $directory): int
    {
        $removed = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $removed += $this->removeTree($path);
                @rmdir($path);
                continue;
            }

            if (@unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
