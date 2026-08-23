<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Database\Connection;
use Core\Database\DatabaseDumper;
use Core\Database\DatabaseRestorer;
use Core\Database\SchemaIntrospector;

/**
 * Mechanical backup/restore operations (Configuration > Maintenance,
 * "Sauvegardes"), reused as-is by later iterations: "Mise à jour" backs up
 * automatically before applying an update, "Réinitialisation" backs up
 * before wiping the site. This class only does the file/database work —
 * registering the result as a `backups` row (Core\Maintenance\
 * BackupRepository) and a downloadable `files` row (Core\File\
 * FileRepository, served via FileAccessGuard) is the caller's job
 * (Core\Http\Controller\MaintenanceController for the synchronous
 * database-only case, Core\Maintenance\Task\CreateBackupHandler for the
 * background full-zip case), keeping this service reusable without
 * dragging in HTTP/scheduler concerns.
 */
class BackupService implements BackupServiceInterface
{
    private const STAGING_SUBDIR = 'maintenance';

    /** @var string[] */
    private const CONFIG_ONLY_TABLES = ['settings', 'module_registry'];

    public function __construct(
        private Connection $connection,
        private string $storagePath,
        private string $basePath
    ) {
    }

    /**
     * Full dump of the database (every table, structure + data) via
     * Core\Database\DatabaseDumper — no `mysqldump` binary involved.
     * Personal data columns are already encrypted BLOBs at the database
     * level, so the dump never contains plaintext personal data — but the
     * file is still sensitive (it's a complete copy of the site's data)
     * and must only ever be handed out via FileAccessGuard, role_min admin.
     *
     * @return string absolute path to the generated .sql file
     * @throws BackupException
     */
    public function createDatabaseDump(): string
    {
        return $this->dump(null);
    }

    /**
     * Structure of every table plus the actual rows of only the tables
     * that are pure site configuration, never member or business data
     * (module spec "full_config" scope: "settings, tables de
     * configuration, structure — pas de données membres/métier"). Kept
     * deliberately to a hardcoded, reviewed whitelist rather than trying to
     * infer "which tables are config" from any module — module-declared
     * settings already all live in the generic `settings` table via
     * SettingService, so this whitelist covers the concept completely
     * without needing to know anything about any specific module's schema.
     *
     * @return string absolute path to the generated .sql file
     * @throws BackupException
     */
    public function createConfigOnlyDump(): string
    {
        return $this->dump(self::CONFIG_ONLY_TABLES);
    }

    /**
     * Zips core/, modules/, public/, and storage/ (excluding storage/keys/
     * and storage/config/ — secrets never leave the server in a backup
     * archive, encrypted or not) into a single archive. $includeGallery
     * controls whether storage/gallery/ (the gallery module's uploaded
     * photos/videos, potentially very large) is included.
     *
     * @throws BackupException
     */
    public function createFileBackup(bool $includeGallery = false): string
    {
        $path = $this->stagingPath('files', 'zip');

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Impossible de créer l\'archive de fichiers.');
        }

        foreach (['core', 'modules', 'public', 'storage'] as $topDir) {
            $this->addDirectoryToZip($zip, $this->basePath . '/' . $topDir, $topDir, $includeGallery);
        }

        $zip->close();

        return $path;
    }

    /**
     * The full password-protected backup (module spec: DB dump + files per
     * $scope, all inside one AES-256-encrypted zip). $scope is one of
     * Backup::TYPES minus 'database'/'auto_*' — 'full_config' skips the
     * file archive entirely (config doesn't need it), the other two
     * scopes decide only whether storage/gallery/ is included.
     *
     * @return array{zipPath: string, dbDumpPath: string} zipPath is the
     *         password-protected archive; dbDumpPath is a separate,
     *         unencrypted copy of just the database dump (schema:
     *         backups.db_dump_file_id — lets an admin restore only the
     *         database later without extracting/decrypting the whole zip).
     *         Both are real files on disk that the caller is responsible
     *         for registering (Core\File\FileRepository) — this service
     *         never deletes either.
     * @throws BackupException when $scope is invalid, the server's
     *                          ZipArchive build doesn't support encryption
     *                          (checked via supportsZipEncryption() before
     *                          any file is touched — this must never
     *                          silently fall back to an unencrypted zip),
     *                          or dump/archive generation fails
     */
    public function createFullBackup(string $scope, string $password): array
    {
        if (!in_array($scope, ['full_config', 'full_no_gallery', 'full_with_gallery'], true)) {
            throw new BackupException('Portée de sauvegarde invalide.');
        }
        if ($password === '') {
            throw new BackupException('Un mot de passe est requis.');
        }
        if (!$this->supportsZipEncryption()) {
            throw new BackupException('Le serveur ne supporte pas le chiffrement des archives — contactez votre hébergeur.');
        }

        $dbDumpPath = $scope === 'full_config' ? $this->createConfigOnlyDump() : $this->createDatabaseDump();

        $zipPath = $this->stagingPath('backup', 'zip');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($dbDumpPath);
            throw new BackupException('Impossible de créer l\'archive complète.');
        }

        try {
            $this->addEncryptedFile($zip, $dbDumpPath, 'database.sql', $password);

            if ($scope !== 'full_config') {
                $includeGallery = $scope === 'full_with_gallery';
                foreach (['core', 'modules', 'public', 'storage'] as $topDir) {
                    $this->addDirectoryToZip($zip, $this->basePath . '/' . $topDir, $topDir, $includeGallery, $password);
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            @unlink($dbDumpPath);
            throw $e;
        }

        $zip->close();

        return ['zipPath' => $zipPath, 'dbDumpPath' => $dbDumpPath];
    }

    /**
     * Runtime check for AES-256 zip encryption support (`ZipArchive::
     * setEncryptionName()` needs libzip built with crypto support — not
     * guaranteed on shared hosting, and there is no reliable way to know
     * without actually trying it). Cheap: a throwaway single-entry zip in
     * the system temp directory, deleted immediately after.
     */
    public function supportsZipEncryption(): bool
    {
        if (!class_exists(\ZipArchive::class) || !defined(\ZipArchive::class . '::EM_AES_256')) {
            return false;
        }

        $testPath = sys_get_temp_dir() . '/scoutmagic_zip_encryption_test_' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($testPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $zip->addFromString('test.txt', 'test');
        $supported = $zip->setEncryptionName('test.txt', \ZipArchive::EM_AES_256, 'test-password');
        $zip->close();
        @unlink($testPath);

        return $supported;
    }

    /**
     * Restores the database from a plain (unencrypted) .sql dump, such as
     * one produced by createDatabaseDump()/createConfigOnlyDump(). Used by
     * the "Réinitialisation"/"Mise à jour" iterations. Goes through
     * Core\Database\DatabaseRestorer (pure PHP, over the same PDO
     * connection the rest of the app already uses) rather than shelling
     * out to a `mysql` binary — the exact same reasoning as
     * DatabaseDumper's own docblock, and the gap that made an automatic
     * rollback fail outright ("mysql n'est pas disponible sur ce serveur")
     * on a host where the CLI simply isn't installed.
     *
     * @throws BackupException
     */
    public function restoreDatabase(string $dumpPath): void
    {
        if (!is_file($dumpPath)) {
            throw new BackupException('Fichier de sauvegarde introuvable.');
        }

        [$host, $port, $dbName, $user, $password] = $this->connectionCredentials();

        try {
            DatabaseRestorer::restore($host, $port, $dbName, $user, $password, $dumpPath);
        } catch (\Throwable $e) {
            // The cause travels as $previous, never folded into the message:
            // BackupException is marked UserFacingException, so appending
            // whatever DatabaseRestorer/PDO said would launder a SQL error
            // into a sentence the visitor is shown verbatim. The detail is
            // still on the stack trace and in every journal entry that logs
            // it (Core\Maintenance\Task\RestoreBackupHandler and friends).
            throw new BackupException(
                'La restauration de la base de données a échoué — la sauvegarde est peut-être incomplète ou '
                . 'issue d\'une autre version. Consultez le journal des événements pour le détail.',
                0,
                $e
            );
        }
    }

    /**
     * Restores files from an archive produced by createFileBackup()
     * (unencrypted) or createFullBackup() (AES-256, needs $password),
     * extracting over $basePath. Used by the "Réinitialisation"/"Mise à
     * jour" iterations.
     *
     * @throws BackupException
     */
    public function restoreFiles(string $archivePath, ?string $password = null): void
    {
        if (!is_file($archivePath)) {
            throw new BackupException('Archive de sauvegarde introuvable.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new BackupException('Impossible d\'ouvrir l\'archive de sauvegarde.');
        }

        if ($password !== null) {
            $zip->setPassword($password);
        }

        // Vet every entry BEFORE extracting over $basePath (the live install
        // root, containing executable PHP under public/). The restore route
        // takes an operator-uploaded ZIP, so without this an archive could
        // scatter files anywhere the entry names point, or ship a symlink.
        // A legitimate backup produced by createFileBackup()/
        // createFullBackup() contains only these four top-level trees plus
        // database.sql — anything else means "this is not our backup".
        $this->assertArchiveEntriesAreSafe($zip);

        $extracted = $zip->extractTo($this->basePath);
        $zip->close();

        if (!$extracted) {
            throw new BackupException('L\'extraction de l\'archive a échoué (mot de passe incorrect ?).');
        }
    }

    /** Top-level trees a legitimate ScoutMagic backup archive may contain. */
    private const RESTORABLE_TOP_LEVEL = ['core', 'modules', 'public', 'storage'];

    /** Refuse an archive whose decompressed contents exceed this (zip bomb). */
    private const MAX_RESTORE_UNCOMPRESSED_BYTES = 4 * 1024 * 1024 * 1024;

    /**
     * @throws BackupException on any entry that escapes the install root,
     *         is a symlink, or falls outside the known backup structure.
     */
    private function assertArchiveEntriesAreSafe(\ZipArchive $zip): void
    {
        $totalUncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new BackupException('Archive de sauvegarde illisible.');
            }
            $name = (string) $stat['name'];

            // Absolute paths, Windows drive prefixes, and any parent-dir
            // segment. extractTo() normalizes '..' itself, but an archive
            // that contains one is not one we produced — reject outright
            // rather than trusting the library's normalization.
            $normalized = str_replace('\\', '/', $name);
            if ($normalized === ''
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:#', $normalized) === 1
                || $normalized === '..'
                || str_starts_with($normalized, '../')
                || str_contains($normalized, '/../')
                || str_ends_with($normalized, '/..')
            ) {
                throw new BackupException('Archive de sauvegarde invalide (chemin non autorisé).');
            }

            // Symlink entries (Unix mode S_IFLNK in the high 16 bits of the
            // external attributes) must never be restored — they would let
            // a later write follow the link outside $basePath. statIndex()
            // omits the external attributes, so read them explicitly.
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)
                && $opsys === \ZipArchive::OPSYS_UNIX
                && ((($attr >> 16) & 0xA000) === 0xA000)
            ) {
                throw new BackupException('Archive de sauvegarde invalide (lien symbolique).');
            }

            // Directory entries end in '/'. Every file must sit under one of
            // the known top-level trees, or be the database dump itself.
            if (!str_ends_with($normalized, '/') && $normalized !== 'database.sql') {
                $top = explode('/', $normalized, 2)[0];
                if (!in_array($top, self::RESTORABLE_TOP_LEVEL, true)) {
                    throw new BackupException('Archive de sauvegarde invalide (contenu inattendu : ' . $top . ').');
                }
            }

            $totalUncompressed += (int) $stat['size'];
            if ($totalUncompressed > self::MAX_RESTORE_UNCOMPRESSED_BYTES) {
                throw new BackupException('Archive de sauvegarde trop volumineuse une fois décompressée.');
            }
        }
    }

    /**
     * @param string[]|null $onlyTables null dumps every table fully;
     *                                  a list dumps every table's
     *                                  structure but data for only these
     */
    private function dump(?array $onlyTables): string
    {
        [$host, $port, $dbName, $user, $password] = $this->connectionCredentials();
        $path = $this->stagingPath($onlyTables === null ? 'database' : 'config', 'sql');

        // DatabaseDumper's 'no-data' setting is "skip data for these
        // tables", the inverse of $onlyTables ("keep data for only
        // these") — every other table still needs its structure dumped
        // (module spec: "full_config" scope keeps every table's schema,
        // just not member/business rows), so it's every table except the
        // whitelist, not the whitelist itself.
        $skipDataForTables = null;
        if ($onlyTables !== null) {
            $allTables = (new SchemaIntrospector($this->connection->getPdo()))->getTables();
            $skipDataForTables = array_values(array_diff($allTables, $onlyTables));
        }

        try {
            DatabaseDumper::dump($host, $port, $dbName, $user, $password, $path, $skipDataForTables);
        } catch (\Throwable $e) {
            @unlink($path);
            // Same rule as restoreDatabase() above: the cause is carried by
            // $previous, not appended to a message this class promises is
            // fit for a visitor.
            throw new BackupException(
                'La génération du dump de la base de données a échoué. Consultez le journal des événements '
                . 'pour le détail, puis réessayez.',
                0,
                $e
            );
        }

        if (!is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new BackupException('La génération du dump de la base de données a échoué.');
        }

        return $path;
    }

    private function addEncryptedFile(\ZipArchive $zip, string $sourcePath, string $entryName, string $password): void
    {
        if (!$zip->addFile($sourcePath, $entryName)) {
            throw new BackupException("Impossible d'ajouter {$entryName} à l'archive.");
        }
        if (!$zip->setEncryptionName($entryName, \ZipArchive::EM_AES_256, $password)) {
            throw new BackupException("Impossible de chiffrer {$entryName} dans l'archive.");
        }
    }

    private function addDirectoryToZip(
        \ZipArchive $zip,
        string $sourceDir,
        string $zipPrefix,
        bool $includeGallery,
        ?string $encryptWithPassword = null
    ): void {
        if (!is_dir($sourceDir)) {
            return;
        }

        $excludedAbsolutePrefixes = [
            $this->storagePath . '/keys',
            $this->storagePath . '/config',
            $this->storagePath . '/temp',
            $this->storagePath . '/' . self::STAGING_SUBDIR,
        ];
        if (!$includeGallery) {
            $excludedAbsolutePrefixes[] = $this->storagePath . '/gallery';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $absolutePath = $file->getPathname();

            $excluded = false;
            foreach ($excludedAbsolutePrefixes as $prefix) {
                if (str_starts_with($absolutePath, $prefix)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }

            $relativePath = $zipPrefix . substr($absolutePath, strlen($sourceDir));

            if ($encryptWithPassword !== null) {
                $this->addEncryptedFile($zip, $absolutePath, $relativePath, $encryptWithPassword);
            } else {
                $zip->addFile($absolutePath, $relativePath);
            }
        }
    }

    private function stagingPath(string $prefix, string $extension): string
    {
        $dir = $this->storagePath . '/' . self::STAGING_SUBDIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . $prefix . '_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: string, 4: string} host, port, dbName, user, password
     */
    private function connectionCredentials(): array
    {
        $credentials = $this->connection->dumpCredentials();

        return [
            $credentials['host'],
            $credentials['port'],
            $credentials['dbName'],
            $credentials['user'],
            $credentials['password'],
        ];
    }
}
