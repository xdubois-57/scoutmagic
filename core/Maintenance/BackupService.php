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
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Portable\PortableManifest;
use Core\Maintenance\Portable\SecretEnvelope;
use Core\Storage\DirectorySize;
use Core\Storage\DirectoryWalk;
use Core\Storage\DiskBudget;

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

    /**
     * @param DiskBudget|null $diskBudget the disk budget every write here
     *        is checked against BEFORE it starts (Core\Storage\DiskBudget).
     *        Null is legitimate in exactly one place — `SetupController`,
     *        which backs up a database while the site is still being
     *        installed and has no settings to read a declared quota from —
     *        and means "write without checking", which is what this class
     *        did before the check existed. Everywhere else, pass one: a
     *        backup truncated by a quota reached mid-write is worse than no
     *        backup, because nothing reveals it until it is restored.
     */
    public function __construct(
        private Connection $connection,
        private string $storagePath,
        private string $basePath,
        private ?DiskBudget $diskBudget = null
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
     * Every top-level tree the safety backup archives — the one an
     * automatic rollback restores from (Task\InstallUpdateHandler,
     * Task\RestoreBackupHandler, Task\FullResetHandler,
     * Task\ResetSettingsHandler, Task\AutoBackupHandler).
     *
     * `vendor` and `schema` are in it because of what an update now does.
     * A development-channel install used to unpack GitHub's zipball of the
     * commit, which contains no `vendor/` at all — so the live one was
     * never replaced and a rollback that ignored it was accidentally
     * correct. Both channels now install a CI-built artifact that DOES
     * carry `vendor/` and replaces it wholesale (scripts/build-artifact.sh),
     * and `schema/` — the declared schema the migration step runs
     * against — has always been replaced by an install. Restoring only
     * core/modules/public/storage after that leaves the previous version's
     * code running against the next version's dependencies and the next
     * version's declared schema: exactly the mixed-state class this
     * codebase has already paid for six times over (ARCHITECTURE.md
     * §8.17, "Nothing migrates in the process that replaced the files").
     * A rollback has to put back everything the install replaced.
     *
     * @var string[]
     */
    private const BACKED_UP_TOP_LEVEL = ['core', 'modules', 'public', 'schema', 'storage', 'vendor'];

    /**
     * The trees the OPERATOR'S OWN downloadable backup archives — four,
     * not the six above.
     *
     * `vendor` and `schema` are deliberately absent: every entry in this
     * archive is separately AES-256 encrypted, and paying that per-file
     * cost over a `vendor/` tree of thousands of files, on the shared
     * hosting this feature exists for, buys back a tree the operator can
     * reinstall from any release artifact.
     *
     * It is a named constant rather than an inline list because the size
     * ESTIMATE has to read the same one. It did not, once, and the
     * estimate summed all six for an archive that writes four — inflating
     * the pre-write check by the whole of `vendor/` and able to refuse a
     * backup that would have fitted. Same lesson as
     * `excludedArchivePrefixes()` below, arrived at from the other
     * direction: what the archive writes and what the estimate measures
     * are one list or they eventually disagree.
     *
     * @var string[]
     */
    private const FULL_BACKUP_TOP_LEVEL = ['core', 'modules', 'public', 'storage'];

    /**
     * Zips BACKED_UP_TOP_LEVEL (excluding storage/keys/ and
     * storage/config/ — secrets never leave the server in a backup
     * archive, encrypted or not) into a single archive. $includeGallery
     * controls whether storage/gallery/ (the gallery module's uploaded
     * photos/videos, potentially very large) is included.
     *
     * @throws BackupException
     */
    public function createFileBackup(bool $includeGallery = false): string
    {
        $this->diskBudget?->ensureRoom($this->estimateFileBackupBytes($includeGallery));

        $path = $this->stagingPath('files', 'zip');

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Impossible de créer l\'archive de fichiers.');
        }

        foreach (self::BACKED_UP_TOP_LEVEL as $topDir) {
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

        return $this->writeArchive($scope, $password, null);
    }

    /**
     * The one archive that carries the site's own keys (`portable`, IT-06).
     *
     * Everything `full_no_gallery` writes, plus `storage/keys/master.key`
     * and `storage/config/secrets.enc` — each under its own AES-256-GCM
     * envelope inside the archive — plus the manifest that says where they
     * came from and how they were sealed.
     *
     * **Why the secrets are in it, when every other archive excludes them
     * on purpose.** A backup without them restores onto THIS installation
     * and nowhere else: on a new host the master key is missing, so every
     * encrypted column is unreadable and the restore produces a site that
     * starts and holds nothing. An off-site backup you cannot restore
     * off-site is not a backup. The price is stated in `SECURITY.md` §5
     * and paid by {@see \Core\Maintenance\Portable\SecretEnvelope}: the
     * zip layer alone would not be enough for this one archive.
     *
     * **No gallery, and no scope to choose.** One button, one archive:
     * this is the copy that leaves the server, and an operator deciding
     * between four flavours of it under a warning about master keys is an
     * operator who picks wrong once. The gallery is excluded because this
     * archive is meant to be carried away and, from IT-08, uploaded on a
     * schedule — the photos are what makes an archive too big for both.
     *
     * @param string      $passphrase    already length-checked by
     *        {@see \Core\Maintenance\Portable\PortablePassphrase}; this
     *        class only refuses an empty one, as its sibling does.
     * @param string      $version       what to write in the manifest
     * @param string|null $installationId the origin, or null when unknown
     * @return array{zipPath: string, dbDumpPath: string}
     * @throws BackupException
     */
    public function createPortableBackup(string $passphrase, string $version, ?string $installationId): array
    {
        return $this->writeArchive(
            Backup::PORTABLE_TYPE,
            $passphrase,
            new PortableManifest($version, $installationId, false, new \DateTimeImmutable())
        );
    }

    /**
     * The single archive-writing path, for all four scopes.
     *
     * One body rather than two, because the four steps that matter — check
     * the room for BOTH writes at once, dump, encrypt every entry, clean up
     * everything on any failure — are the ones that took the longest to get
     * right, and a portable copy of them would be a copy that drifts.
     *
     * @param PortableManifest|null $manifest present exactly when this is a
     *        portable archive; its presence is what adds the sealed secrets
     *        and the manifest member, so the two can never be separated.
     * @return array{zipPath: string, dbDumpPath: string}
     * @throws BackupException
     */
    private function writeArchive(string $scope, string $password, ?PortableManifest $manifest): array
    {
        if ($password === '') {
            throw new BackupException('Un mot de passe est requis.');
        }
        if (!$this->supportsZipEncryption()) {
            throw new BackupException('Le serveur ne supporte pas le chiffrement des archives — contactez votre '
                . 'hébergeur.');
        }

        // **The portable archive does not hand the passphrase to the zip.**
        // The zip format derives its key with PBKDF2-HMAC-SHA1 at 1000
        // iterations AND stores a verification value beside it, so cracking
        // that layer recovers the PASSPHRASE — after which any slower
        // derivation keyed by the same phrase is computed once and for
        // free. One slow pass therefore produces a high-entropy archive
        // password and a separate envelope key; the parameters travel in
        // the archive comment, since the password is derived from them and
        // nothing password-protected could carry them.
        //
        // The ordinary full backup keeps the operator's own password, and
        // that is not an oversight: nothing in it is readable without a
        // master key that stays on this server, so the 1000 iterations
        // guard ciphertext rather than a plaintext dossier.
        $derivation = $manifest !== null ? PortableKeys::newDerivation() : null;
        $keys = $derivation !== null ? PortableKeys::derive($password, $derivation) : null;
        $archivePassword = $keys?->archivePassword() ?? $password;

        // Both halves at once, before either exists. Checking them one at a
        // time would let the dump succeed and the archive run out of room
        // half-written — the exact mid-write truncation this guard exists
        // to prevent, arrived at through the guard itself.
        $this->diskBudget?->ensureRoom(
            $this->estimateDatabaseDumpBytes()
            + ($scope === 'full_config'
                ? 0
                : $this->estimateFileBackupBytes($scope === 'full_with_gallery', self::FULL_BACKUP_TOP_LEVEL))
            + ($manifest !== null ? $this->estimateSecretMemberBytes() : 0)
        );

        $dbDumpPath = $scope === 'full_config' ? $this->createConfigOnlyDump() : $this->createDatabaseDump();

        $zipPath = $this->stagingPath('backup', 'zip');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($dbDumpPath);
            throw new BackupException('Impossible de créer l\'archive complète.');
        }

        try {
            $this->addEncryptedFile($zip, $dbDumpPath, 'database.sql', $archivePassword);
            $manifest?->addMember('database.sql', $this->digestOf($dbDumpPath), (int) filesize($dbDumpPath));

            if ($scope !== 'full_config') {
                $includeGallery = $scope === 'full_with_gallery';
                // Deliberately NOT BACKED_UP_TOP_LEVEL: this archive is
                // the operator's own downloadable backup, and every entry
                // in it is separately AES-256 encrypted
                // (addEncryptedFile()). Adding vendor/ — thousands of
                // small files — would multiply that cost on exactly the
                // shared hosting this feature exists for, for a tree the
                // operator can always reinstall from a release artifact.
                // The safety backup an automatic rollback restores from
                // (createFileBackup() above) is the one that must be
                // complete, and it is unencrypted. RESTORABLE_TOP_LEVEL is
                // a superset check, so both archives stay restorable.
                foreach (self::FULL_BACKUP_TOP_LEVEL as $topDir) {
                    $this->addDirectoryToZip(
                        $zip,
                        $this->basePath . '/' . $topDir,
                        $topDir,
                        $includeGallery,
                        $archivePassword
                    );
                }
            }

            if ($manifest !== null && $keys !== null) {
                $this->addSealedSecrets($zip, $manifest, $keys->envelopeKey(), $archivePassword);
                $this->addEncryptedString($zip, PortableManifest::MEMBER, $manifest->toJson(), $archivePassword);

                // In clear, and it has to be: the archive password is
                // derived FROM this, so anything the password protects
                // could not carry it. A salt is not a secret.
                if (!$zip->setArchiveComment(PortableKeys::comment($derivation))) {
                    throw new BackupException(
                        'L\'en-tête de la sauvegarde portable n\'a pas pu être écrit dans l\'archive.'
                    );
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
     * Seals the two secret files into the archive, and tells the manifest.
     *
     * **Read straight from disk and sealed in memory** — never routed
     * through the directory walk that writes every other entry. That walk
     * excludes `storage/keys/` and `storage/config/` wholesale
     * ({@see excludedArchivePrefixes()}), and teaching it a "portable mode"
     * that stopped excluding them was the obvious shape and the wrong one:
     * it would have added the master key to the archive as an ORDINARY
     * entry, protected by the zip's own 1000-iteration derivation and
     * nothing else. That is the exact failure this whole class of archive
     * is built to avoid, and it would have looked like a feature working.
     * The exclusion therefore stays absolute, in every mode, and these two
     * files travel by a path that cannot forget to seal them.
     *
     * Both files are small — 32 bytes and a few hundred — so reading them
     * whole is not the sin it would be for an archive member.
     *
     * @throws BackupException
     */
    private function addSealedSecrets(
        \ZipArchive $zip,
        PortableManifest $manifest,
        string $envelopeKey,
        string $archivePassword
    ): void {
        foreach (PortableManifest::SECRET_MEMBERS as $relativePath => $member) {
            $absolutePath = $this->storagePath . '/' . $relativePath;
            $plaintext = is_file($absolutePath) ? @file_get_contents($absolutePath) : false;
            // `''` as well as `false`: an empty master key decrypts
            // nothing, so an archive carrying one is restorable nowhere —
            // and an empty file is exactly what a quota reached mid-write
            // leaves behind, which is the failure IT-02 exists for.
            // `file_get_contents()` returns '' rather than false for it,
            // so the two have to be refused together.
            if ($plaintext === false || $plaintext === '') {
                // Not survivable, and refusing is the whole point: an
                // archive that is missing one of these is one that cannot
                // be restored anywhere else, and the operator would only
                // find out on the day they tried.
                //
                // WHICH file it was travels as $previous, never in the
                // message: BackupException is marked UserFacingException,
                // and that marker is a claim about every message it is
                // ever built with — French, and naming nothing internal.
                // `keys/master.key` is the site's own directory layout.
                // The detail still reaches the stack trace and the
                // journal entry that logs it, which is where somebody
                // diagnosing this actually looks.
                throw new BackupException(
                    'Un fichier de secrets du site est illisible — la sauvegarde portable ne serait '
                    . 'restaurable nulle part. Vérifiez les droits sur storage/.',
                    0,
                    new \RuntimeException('Unreadable portable secret member: ' . $relativePath)
                );
            }

            $sealed = SecretEnvelope::seal($plaintext, $envelopeKey);

            $this->addEncryptedString($zip, $member, $sealed, $archivePassword);
            $manifest->addMember($member, hash('sha256', $sealed), strlen($sealed), 'storage/' . $relativePath);
        }
    }

    /** What the sealed secrets add to the archive, for the room check. */
    private function estimateSecretMemberBytes(): int
    {
        $total = 0;
        foreach (array_keys(PortableManifest::SECRET_MEMBERS) as $relativePath) {
            $size = @filesize($this->storagePath . '/' . $relativePath);
            $total += is_int($size) ? $size : 0;
        }

        // Plus the manifest and the per-envelope overhead. A round number
        // rather than a computed one: it is kilobytes against an estimate
        // already measured in hundreds of megabytes, and erring high is
        // the safe direction for "will this fit?".
        return $total + 64 * 1024;
    }

    /**
     * The digest of a file, without ever holding it in memory.
     *
     * `hash_file()` and never `hash(file_get_contents())`, for the reason
     * {@see \Core\Maintenance\BackupIntegrity} spells out: the dump of a
     * real site is measured in hundreds of megabytes, and reading one into
     * a string would exhaust the memory of the shared host this feature
     * exists for.
     *
     * @throws BackupException
     */
    private function digestOf(string $absolutePath): string
    {
        $digest = @hash_file('sha256', $absolutePath);
        if ($digest === false) {
            // Same rule as addSealedSecrets() below, and the same reason:
            // this class's exception is marked UserFacingException, so the
            // message is rendered verbatim on Configuration > Maintenance
            // and stored in `backups.error_message`. A staging file name
            // like `database_2026-05-01_101010_9f3c1a20.sql` is internal.
            throw new BackupException(
                'L\'empreinte d\'un fichier de la sauvegarde n\'a pas pu être calculée.',
                0,
                new \RuntimeException('Unreadable backup member: ' . $absolutePath)
            );
        }

        return $digest;
    }

    /**
     * Adds an in-memory string as an encrypted entry.
     *
     * Its own method rather than a flag on {@see addEncryptedFile()}: the
     * two things that must never be forgotten are the same in both cases
     * (add, then encrypt, then check BOTH answers), and a caller that gets
     * the second half wrong writes an archive with one entry silently in
     * clear — which for a sealed secret would still be sealed, and for the
     * manifest would leak the salt.
     *
     * @throws BackupException
     */
    private function addEncryptedString(\ZipArchive $zip, string $entryName, string $contents, string $password): void
    {
        if (!$zip->addFromString($entryName, $contents)) {
            throw new BackupException("Impossible d'ajouter {$entryName} à l'archive.");
        }
        if (!$zip->setEncryptionName($entryName, \ZipArchive::EM_AES_256, $password)) {
            throw new BackupException("Impossible de chiffrer {$entryName} dans l'archive.");
        }
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
        // createFullBackup() contains only the known top-level trees plus
        // database.sql — anything else means "this is not our backup".
        $this->assertArchiveEntriesAreSafe($zip);

        $extracted = $zip->extractTo($this->basePath);
        $zip->close();

        if (!$extracted) {
            throw new BackupException('L\'extraction de l\'archive a échoué (mot de passe incorrect ?).');
        }

        // core/, modules/ and public/ have just been rewritten underneath a
        // running PHP process, which caches compiled code for up to
        // `opcache.revalidate_freq` seconds and would otherwise keep serving
        // the version this restore was meant to replace. Unlike an update
        // (Task\InstallUpdateHandler, which knows every file it copied),
        // ZipArchive never says which entries it wrote — so the whole cache
        // goes, which is the honest cost of not having the list.
        clearstatcache(true);
        OpcodeCache::reset();
    }

    /**
     * Top-level trees a legitimate ScoutMagic backup archive may contain.
     *
     * A SUPERSET check, never a required set: nothing here has to be
     * present. That is what keeps an archive taken before `vendor` and
     * `schema` joined the list (see BACKED_UP_TOP_LEVEL, and the reason
     * they joined it) restorable exactly as it always was — an old backup
     * must never become unrestorable because a newer version learned to
     * back up more.
     *
     * It stays in lockstep with BACKED_UP_TOP_LEVEL in the other
     * direction, though: a tree the backup writes but this list does not
     * accept would make every new backup unrestorable, which is a far
     * quieter failure — nothing notices until the day a rollback is
     * actually needed.
     */
    private const RESTORABLE_TOP_LEVEL = ['core', 'modules', 'public', 'schema', 'storage', 'vendor'];

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
        $this->diskBudget?->ensureRoom($this->estimateDatabaseDumpBytes());

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

    /**
     * Floor for a database dump when the server will not report its own
     * size. Not a guess at how big the dump is — a guess at how much room
     * is worth insisting on before starting one at all, on a site whose
     * database is small enough that nothing else would have refused.
     */
    private const MINIMUM_DUMP_ESTIMATE_BYTES = 8 * 1024 * 1024;

    /**
     * Roughly how many bytes a full dump will occupy, read from
     * `information_schema` — the only cheap source for it, and one both
     * supported engines answer (AGENTS.md § Database).
     *
     * Deliberately the stored size rather than a multiple of it. Textual
     * SQL is larger than the pages it came from, but this figure feeds
     * `DiskBudget::ensureRoom()`, which adds its own margin on top; a
     * second fudge factor here would refuse writes that fit. The test
     * database (SQLite, `Tests\DatabaseTestHelper`) has no
     * `information_schema` at all, which the catch below turns into the
     * floor rather than into a failure.
     */
    public function estimateDatabaseDumpBytes(): int
    {
        try {
            $stmt = $this->connection->getPdo()->query(
                'SELECT SUM(data_length + index_length) FROM information_schema.TABLES '
                . 'WHERE table_schema = DATABASE()'
            );
            $bytes = $stmt !== false ? $stmt->fetchColumn() : false;
            if (is_numeric($bytes)) {
                return max(self::MINIMUM_DUMP_ESTIMATE_BYTES, (int) $bytes);
            }
        } catch (\Throwable) {
            // No information_schema, or no permission on it. The floor is
            // the honest answer: "we could not size this, insist on a
            // little room anyway".
        }

        return self::MINIMUM_DUMP_ESTIMATE_BYTES;
    }

    /**
     * Reserves the dump AND the archive together, before either exists.
     *
     * The reason is written out in `createFullBackup()` and is the single
     * most repeated mistake this whole guard invites: checking them one at
     * a time lets the dump succeed and the archive run out of room
     * half-written — the exact mid-write truncation the guard exists to
     * prevent, arrived at through the guard itself. Both writes are sized
     * against the same reading, so both must be charged to it at once.
     *
     * It lives here rather than in each handler because five call sites do
     * this pair — `Task\AutoBackupHandler`, `Task\FullResetHandler`,
     * `Task\ResetSettingsHandler`, `Task\RestoreBackupHandler` and
     * `Task\InstallUpdateHandler` — and four of them had it wrong for the
     * same reason: the per-write checks inside `createDatabaseDump()` and
     * `createFileBackup()` each look complete on their own. The per-write
     * checks stay, since they still guard callers that reach them without
     * passing here; once this one has passed they cost nothing.
     *
     * @param int $extraBytes anything else the caller is about to write in
     *        the same run — `InstallUpdateHandler` adds its artifact
     *        workspace, which has to be charged with the backup rather
     *        than after it
     * @throws \Core\Storage\InsufficientDiskSpaceException
     */
    public function ensureRoomForDumpAndArchive(bool $includeGallery, int $extraBytes = 0): void
    {
        $this->diskBudget?->ensureRoom(
            $extraBytes
            + $this->estimateDatabaseDumpBytes()
            + $this->estimateFileBackupBytes($includeGallery)
        );
    }

    /**
     * Roughly how many bytes the file archive will occupy: the summed size
     * of everything it is about to read, with the same exclusions
     * `addDirectoryToZip()` applies.
     *
     * A deliberate OVER-estimate — the archive is compressed and will come
     * out smaller. For a "will this fit?" question, erring high is the
     * safe direction, and the alternative (guessing a compression ratio)
     * would be a number nobody could defend.
     *
     * @param string[]|null $topLevel which trees the caller is about to
     *        archive. Null means the safety backup's six
     *        (BACKED_UP_TOP_LEVEL); `createFullBackup()` passes its own
     *        four, because erring high is only safe up to a point —
     *        summing `vendor/` for an archive that does not contain it
     *        inflates the check by hundreds of megabytes and can refuse a
     *        backup that would have fitted.
     */
    public function estimateFileBackupBytes(bool $includeGallery, ?array $topLevel = null): int
    {
        $excluded = $this->excludedArchivePrefixes($includeGallery);

        $total = 0;
        foreach ($topLevel ?? self::BACKED_UP_TOP_LEVEL as $topDir) {
            $total += DirectorySize::measure($this->basePath . '/' . $topDir, $excluded, DirectoryWalk::Archive);
        }

        return $total;
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

    /**
     * What an archive never contains: the secrets (`storage/keys/`,
     * `storage/config/` — they never leave the server in a backup,
     * encrypted or not), the scratch directories, and the gallery unless
     * asked for.
     *
     * One definition, read by both the archive walk and the size estimate
     * that decides whether the archive will fit. Two copies of this list
     * would eventually disagree, and the direction that hurts is the
     * quiet one: an estimate that leaves out what the archive puts in
     * reports "it fits" about a write that does not.
     *
     * @return string[] absolute path prefixes
     */
    private function excludedArchivePrefixes(bool $includeGallery): array
    {
        $excluded = [
            $this->storagePath . '/keys',
            $this->storagePath . '/config',
            $this->storagePath . '/temp',
            $this->storagePath . '/' . self::STAGING_SUBDIR,
        ];
        if (!$includeGallery) {
            $excluded[] = $this->storagePath . '/gallery';
        }

        return $excluded;
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

        // followLinks: true — the behaviour this archive has always had.
        // A quota measurement must not follow a symlink; a backup must,
        // or a host that symlinks storage/gallery elsewhere gets an
        // archive that silently contains none of it.
        $files = DirectorySize::files(
            $sourceDir,
            $this->excludedArchivePrefixes($includeGallery),
            DirectoryWalk::Archive
        );

        foreach ($files as $file) {
            $absolutePath = $file->getPathname();
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
