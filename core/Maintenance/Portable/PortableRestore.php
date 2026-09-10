<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Maintenance\BackupService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;

/**
 * What a portable restore does that an ordinary one does not.
 *
 * An ordinary restore puts an installation back the way it was. A portable
 * restore puts a *different* installation's contents onto *this* machine,
 * and the difference is the whole of this class: the keys have to be
 * installed rather than extracted, the credentials of the machine being
 * restored onto have to survive the contents arriving on top of them, and
 * the result has to be a new installation rather than a second copy of the
 * old one pretending to be it.
 *
 * The database dump and the schema migration are deliberately NOT here.
 * They are the same operations an ordinary restore performs, done by the
 * same code (`BackupService::restoreDatabase()`, `MigrationRunner`), and
 * duplicating them for this path is how the two would drift.
 */
final class PortableRestore
{
    /**
     * Secrets that belong to the MACHINE, never to the backup (D5).
     *
     * These name *where this installation lives* rather than what it
     * contains, and they are the most likely way to brick a fresh
     * installation with a successful restore: writing the origin's
     * `secrets.enc` wholesale hands the new site the old site's database
     * host, name and password — credentials that, on a different host, are
     * either wrong (the site cannot start) or, far worse, right for a
     * database that still belongs to the old installation.
     *
     * `base_url` is here for the same reason at a different layer: the
     * bootstrap copy in `secrets.enc` is inert once a site is initialised,
     * but leaving the origin's URL in it means the one place a diagnostic
     * looks disagrees with the running site.
     *
     * @var string[]
     */
    public const TARGET_OWNED_SECRETS = [
        'db_host',
        'db_port',
        'db_name',
        'db_user',
        'db_password',
        'base_url',
    ];

    /**
     * The setting recording where a restored installation came from.
     *
     * Named by the statistics module, which owns every `statistics_`
     * setting; this class is its only writer. The write below is an UPDATE
     * that tolerates finding nothing, because immediately after a restore
     * the database is the ORIGIN's, and an origin on an older ScoutMagic
     * has no such row. It is created on the next boot — which is why this
     * runs on the resume pass rather than inline, see
     * `Task\RestoreBackupHandler`.
     */
    public const RESTORED_FROM_SETTING = InstallationIdentityService::RESTORED_FROM_SETTING;

    public function __construct(
        private readonly string $basePath,
        private readonly string $storagePath
    ) {
    }

    /**
     * The target's own encryption files, held aside before a restore
     * replaces them.
     *
     * **The safety backup cannot stand in for this**, and that is the
     * whole reason this exists: `BackupService::createFileBackup()`
     * excludes `storage/keys/` and `storage/config/` unconditionally —
     * secrets never travel in an ordinary archive — so the automatic
     * rollback restores the database and the file tree and silently leaves
     * whatever keys are on disk. On this path those are the ARCHIVE's, and
     * the two failure modes are both bad: the recovered installation ends
     * up unable to read its own restored data, or pointed at the origin's
     * database while the journal reports that the previous state came
     * back.
     *
     * A null value means the file was not there, which is the ordinary
     * case in the installation wizard and is itself worth restoring
     * faithfully — see {@see restoreSecretsSnapshot()}.
     *
     * @return array<string, string|null>
     */
    public function secretsSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->writableSecretTargets() as $relativeTarget) {
            $absolute = $this->basePath . '/' . $relativeTarget;
            $contents = is_file($absolute) ? @file_get_contents($absolute) : false;
            $snapshot[$relativeTarget] = $contents === false ? null : $contents;
        }

        return $snapshot;
    }

    /**
     * Puts the snapshot back, and **deletes what was not there before**.
     *
     * The deletion half is not tidiness. On a fresh installation there are
     * no secrets to restore, so a failed restore that left the archive's
     * behind would leave `SecretManager::isInitialized()` answering true —
     * the wizard would then refuse the next attempt as "already
     * configured", on a site that has no database, no account and no way
     * forward. Removing them puts the operator back where they were: able
     * to try again.
     *
     * Best-effort by construction: this runs while something else has
     * already failed, and throwing here would replace a diagnosable
     * failure with a different one.
     *
     * @param array<string, string|null> $snapshot
     */
    public function restoreSecretsSnapshot(array $snapshot): void
    {
        foreach ($snapshot as $relativeTarget => $contents) {
            if (!in_array($relativeTarget, $this->writableSecretTargets(), true)) {
                continue;
            }

            $absolute = $this->basePath . '/' . $relativeTarget;
            if ($contents === null) {
                @unlink($absolute);
                continue;
            }

            @file_put_contents($absolute, $contents);
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod($absolute, 0600);
            }
        }
    }

    /**
     * The whole restore, in the order that matters, shared by both entry
     * points.
     *
     * The wizard and the scheduled task differ in what surrounds this —
     * one has a safety backup to take and a resume pass to queue, the
     * other migrates on the spot — but the middle is identical: put the
     * database back, put the data back, put the keys back. A copy of these
     * four steps in each caller would be a copy to keep in step, and the
     * order is not arbitrary: the secrets go in LAST, so that an
     * installation whose restore died half way still holds the keys to the
     * database it had before rather than the keys to one it never
     * received.
     *
     * The dump is written under `storage/` rather than the system temp
     * directory: it is the size of the whole database, and a shared host's
     * `/tmp` is routinely the smallest filesystem on the machine — the one
     * place a restore must not run out of room. It is removed in a
     * `finally`, whatever happens.
     *
     * @param array<string, mixed> $targetOwnedSecrets {@see installSecrets()}
     * @throws BackupException
     */
    public function apply(PortableArchive $archive, BackupService $backupService, array $targetOwnedSecrets): void
    {
        $dumpPath = $this->writeDump($archive);

        try {
            $backupService->restoreDatabase($dumpPath);
        } finally {
            @unlink($dumpPath);
        }

        $this->extractFiles($archive);
        $this->installSecrets($archive, $targetOwnedSecrets);
    }

    /**
     * The archive's database dump, written where the restore can read it.
     *
     * @throws BackupException
     */
    private function writeDump(PortableArchive $archive): string
    {
        $sql = $archive->handle()->getFromName('database.sql');
        if ($sql === false) {
            throw new BackupException('Cette sauvegarde portable ne contient pas de base de données.');
        }

        $directory = $this->storagePath . '/temp';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new BackupException('Le dossier temporaire du site n\'a pas pu être créé.');
        }

        $path = $directory . '/portable_restore_' . bin2hex(random_bytes(8)) . '.sql';
        // The BYTE COUNT, not just `false`. A quota reached mid-write
        // returns a short count and no error, and a dump truncated at a
        // statement boundary restores without complaint — leaving a site
        // with some of its tables and a message saying it succeeded.
        $written = @file_put_contents($path, $sql);
        if ($written === false || $written !== strlen($sql)) {
            @unlink($path);

            throw new BackupException(
                'La base de données de l\'archive n\'a pas pu être écrite en entier sur le disque — vérifiez '
                . 'l\'espace disponible.'
            );
        }

        return $path;
    }

    /**
     * Extracts the file trees, and only those.
     *
     * `secrets/` and the manifest are excluded by
     * {@see PortableArchive::restorableEntries()} rather than deleted
     * afterwards: `ZipArchive::extractTo()` takes the list of entries to
     * write, and there is no un-writing one it has already written.
     *
     * @throws BackupException
     */
    public function extractFiles(PortableArchive $archive): void
    {
        $entries = $archive->restorableEntries();
        if ($entries === []) {
            throw new BackupException('Cette sauvegarde portable ne contient aucun fichier à restaurer.');
        }

        if (!$archive->handle()->extractTo($this->basePath, $entries)) {
            throw new BackupException(
                'L\'extraction de la sauvegarde portable a échoué. Vérifiez l\'espace disque et les droits '
                . 'sur le dossier du site.'
            );
        }
    }

    /**
     * Installs the origin's encryption keys, keeping this machine's
     * database credentials (D5).
     *
     * The sequence is the delicate part:
     *
     * 1. the origin's `master.key` is written — without it, nothing else
     *    here can be read;
     * 2. the origin's `secrets.enc` is written — which at this instant
     *    still carries the ORIGIN's database credentials;
     * 3. it is immediately re-opened with the key from step 1, the
     *    machine's own credentials are put back, and it is written again.
     *
     * Step 3 is not a tidy-up: between steps 2 and 3 the installation is
     * pointing at somebody else's database, and that window is exactly why
     * this is one method rather than three calls a caller could interleave
     * or forget the end of.
     *
     * @param array<string, mixed> $targetOwnedSecrets this machine's values
     *        for {@see TARGET_OWNED_SECRETS}; on a fresh install these come
     *        from the wizard's own form, on an existing one from the
     *        secrets being replaced. A key absent here is simply not
     *        overridden — the wizard has no `base_url` to give at the step
     *        where the restore happens.
     * @throws BackupException
     */
    public function installSecrets(PortableArchive $archive, array $targetOwnedSecrets): void
    {
        $secrets = $archive->unsealSecrets();

        foreach ($secrets as $relativeTarget => $plaintext) {
            // Belt and braces over {@see PortableArchive::unsealSecrets()},
            // which already derives these paths locally rather than reading
            // them out of the archive. This method is public and writes
            // files: the day a second caller hands it a map built some
            // other way, the difference between a fixed vocabulary and a
            // string joined onto the install root is arbitrary file write.
            // Two known names, compared whole — no traversal to reason
            // about, because nothing is parsed.
            if (!in_array($relativeTarget, $this->writableSecretTargets(), true)) {
                throw new BackupException(
                    'Cette sauvegarde portable désigne un emplacement de clé que ce site refuse d\'écrire.',
                    0,
                    new \RuntimeException('Refused portable secret destination: ' . $relativeTarget)
                );
            }

            $absolute = $this->basePath . '/' . $relativeTarget;
            $directory = dirname($absolute);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new BackupException(
                    'Le dossier des clés du site n\'a pas pu être créé. Vérifiez les droits sur storage/.',
                    0,
                    new \RuntimeException('Cannot create secret directory: ' . $directory)
                );
            }
            if (@file_put_contents($absolute, $plaintext) === false) {
                throw new BackupException(
                    'Les clés de chiffrement n\'ont pas pu être écrites. Vérifiez les droits sur storage/.',
                    0,
                    new \RuntimeException('Cannot write restored secret: ' . $absolute)
                );
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod($absolute, 0600);
            }
        }

        $this->putBackTargetCredentials($targetOwnedSecrets);
    }

    /**
     * The only paths a portable restore may write a secret to.
     *
     * Computed from the writer's own map rather than copied beside it: a
     * second hand-written list is a second list to keep right, and the day
     * it fell behind this guard would start refusing honest archives —
     * a fixed vocabulary that has quietly become the wrong one. Compared
     * as whole strings, so there is no path to parse and therefore nothing
     * for a traversal to hide in.
     *
     * @return string[]
     */
    private function writableSecretTargets(): array
    {
        $targets = [];
        foreach (array_keys(PortableManifest::SECRET_MEMBERS) as $liveRelativePath) {
            $targets[] = 'storage/' . $liveRelativePath;
        }

        return $targets;
    }

    /**
     * Re-opens the freshly installed `secrets.enc` and restores this
     * machine's own entries in it.
     *
     * @param array<string, mixed> $targetOwnedSecrets
     * @throws BackupException
     */
    private function putBackTargetCredentials(array $targetOwnedSecrets): void
    {
        $manager = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );

        try {
            $secrets = $manager->readSecrets();
        } catch (\Throwable $e) {
            // The key and the blob have just been written together, from
            // one archive, so this means the archive's own two halves do
            // not match each other — not a wrong passphrase, which was
            // settled long before anything was written.
            throw new BackupException(
                'Les clés de cette sauvegarde ne permettent pas de lire ses propres secrets — l\'archive est '
                . 'inutilisable.',
                0,
                $e
            );
        }

        foreach (self::TARGET_OWNED_SECRETS as $key) {
            if (array_key_exists($key, $targetOwnedSecrets)) {
                $secrets[$key] = $targetOwnedSecrets[$key];
            }
        }

        $manager->writeSecrets($secrets);
    }

    /**
     * Makes the restored installation a NEW one rather than a second copy
     * of the old.
     *
     * Three things, and each is a different kind of "the old site is still
     * in here":
     *
     * - **A new identity (D6).** The installation identifier is blanked
     *   rather than replaced, so `InstallationIdentityService` mints and
     *   claims the next one through the path that already handles
     *   concurrency. Two installations reporting the same identifier would
     *   not read as two sites; they would read as one site with impossible
     *   statistics.
     * - **Where it came from.** The origin's identifier is kept, once, so
     *   supervision can relate the two installations without merging them,
     *   and so the old one going quiet reads as a move rather than an
     *   abandonment.
     * - **The push subscriptions go.** A service worker is bound to its
     *   origin; carried to another domain, every endpoint is dead and each
     *   send would collect a 410 before pruning it. Emptying the table
     *   follows the precedent of the notification migration's `TRUNCATE`
     *   (ARCHITECTURE.md §8.24): this is ephemeral operational state, never
     *   a register.
     *
     * Runs on the resume pass, after the migration — by then the schema
     * matches the code and every setting row this touches exists.
     *
     * @param string|null $baseUrl this machine's own URL, when the caller
     *        knows it; null leaves whatever the restore brought.
     */
    public function adoptNewIdentity(\PDO $pdo, ?string $originInstallationId, ?string $baseUrl): void
    {
        $this->writeSetting($pdo, InstallationIdentityService::INSTALLATION_ID_SETTING, '');

        if ($originInstallationId !== null) {
            $this->writeSetting($pdo, self::RESTORED_FROM_SETTING, $originInstallationId);
        }
        if ($baseUrl !== null && $baseUrl !== '') {
            $this->writeSetting($pdo, 'base_url', $baseUrl);
        }

        $pdo->exec('DELETE FROM push_subscriptions');
    }

    /**
     * Updates a setting row if it is there.
     *
     * Deliberately an UPDATE and not an upsert: `settings` rows carry a
     * label, a type and a default that only the module registering them
     * knows, and inventing those here would produce a row that looks
     * registered and behaves like nothing. A row that does not exist yet
     * is created on the next boot with its proper declaration — and for the
     * identifier, absent and blank mean the same thing to
     * `InstallationIdentityService`, which is exactly the outcome wanted.
     */
    private function writeSetting(\PDO $pdo, string $key, string $value): void
    {
        $statement = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $statement->execute([$value, $key]);
    }
}
