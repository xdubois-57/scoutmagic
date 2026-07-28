<?php

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Database\Connection;

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
class BackupService
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
     * Full `mysqldump` of the database (every table, structure + data).
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
     * Structure of every table (mysqldump --no-data) plus the actual rows
     * of only the tables that are pure site configuration, never member or
     * business data (module spec "full_config" scope: "settings, tables de
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
     * the "Réinitialisation"/"Mise à jour" iterations.
     *
     * @throws BackupException
     */
    public function restoreDatabase(string $dumpPath): void
    {
        if (!is_file($dumpPath)) {
            throw new BackupException('Fichier de sauvegarde introuvable.');
        }

        [$host, $port, $dbName, $user, $password] = $this->connectionCredentials();

        $command = sprintf(
            'mysql -h %s -P %s -u %s %s %s < %s 2>/dev/null',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($user),
            $password !== '' ? '-p' . escapeshellarg($password) : '',
            escapeshellarg($dbName),
            escapeshellarg($dumpPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new BackupException('La restauration de la base de données a échoué.');
        }
    }

    /**
     * Restores files from an unencrypted archive produced by
     * createFileBackup(), extracting over $basePath. Used by the
     * "Réinitialisation"/"Mise à jour" iterations.
     *
     * @throws BackupException
     */
    public function restoreFiles(string $archivePath): void
    {
        if (!is_file($archivePath)) {
            throw new BackupException('Archive de sauvegarde introuvable.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new BackupException('Impossible d\'ouvrir l\'archive de sauvegarde.');
        }

        $extracted = $zip->extractTo($this->basePath);
        $zip->close();

        if (!$extracted) {
            throw new BackupException('L\'extraction de l\'archive a échoué.');
        }
    }

    /**
     * @param string[]|null $onlyTables null dumps every table fully;
     *                                  a list dumps every table's
     *                                  structure but data for only these
     */
    private function dump(?array $onlyTables): string
    {
        $output = [];
        $returnCode = 0;
        @exec('which mysqldump 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            throw new BackupException('mysqldump n\'est pas disponible sur ce serveur.');
        }

        [$host, $port, $dbName, $user, $password] = $this->connectionCredentials();
        $path = $this->stagingPath($onlyTables === null ? 'database' : 'config', 'sql');
        $authArgs = sprintf(
            '-h %s -P %s -u %s %s %s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($user),
            $password !== '' ? '-p' . escapeshellarg($password) : '',
            escapeshellarg($dbName)
        );

        if ($onlyTables === null) {
            $command = sprintf('mysqldump %s > %s 2>/dev/null', $authArgs, escapeshellarg($path));
            exec($command, $unused, $returnCode);
        } else {
            $tableArgs = implode(' ', array_map('escapeshellarg', $onlyTables));
            $structureCommand = sprintf('mysqldump --no-data %s > %s 2>/dev/null', $authArgs, escapeshellarg($path));
            $dataCommand = sprintf('mysqldump --no-create-info %s %s >> %s 2>/dev/null', $authArgs, $tableArgs, escapeshellarg($path));
            exec($structureCommand, $unused, $returnCode);
            if ($returnCode === 0) {
                exec($dataCommand, $unused, $returnCode);
            }
        }

        if ($returnCode !== 0 || !is_file($path) || filesize($path) === 0) {
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
        $reflection = new \ReflectionClass($this->connection);
        $get = fn(string $property) => $reflection->getProperty($property)->getValue($this->connection);

        return [$get('host'), $get('port'), $get('dbName'), $get('user'), $get('password')];
    }
}
