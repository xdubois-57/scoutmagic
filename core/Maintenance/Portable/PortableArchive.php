<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Maintenance\VersionFile;

/**
 * The reading half of {@see \Core\Maintenance\BackupService::createPortableBackup()}:
 * everything a restore needs to know about an archive **before it writes
 * anything**.
 *
 * The order of operations here is the whole design, and it is the reverse
 * of the order a careless restore would use:
 *
 * 1. the archive comment, in clear, says how the key was derived;
 * 2. the passphrase plus those parameters give the archive password and the
 *    envelope key ({@see PortableKeys});
 * 3. the manifest — itself encrypted — says what this archive is, which
 *    version wrote it, and what the two sealed secrets should hash to;
 * 4. only then does anything land on disk.
 *
 * A wrong passphrase, a foreign zip, an archive from a newer ScoutMagic or
 * a secret whose digest does not match are all refused at step 1, 2 or 3 —
 * that is, while the target installation is still untouched. This matters
 * more here than on the ordinary restore path: the installation being
 * written to is typically a brand-new one whose only content is what the
 * operator just configured, and the operator is typically in the middle of
 * a bad day.
 *
 * **This class never writes.** It opens, verifies and hands back bytes; who
 * puts them where is the restore's business, and keeping that out of here
 * is what lets the whole verification sequence be tested without a
 * filesystem to restore onto.
 */
final class PortableArchive
{
    /**
     * The only tree a portable restore puts back on disk.
     *
     * **The archive also carries `core/`, `modules/` and `public/`, and a
     * restore deliberately ignores them.** That looks like throwing away
     * most of the file, so it is worth being exact about why: a portable
     * archive moves a unit's DATA to a new home, and the new home already
     * has ScoutMagic on it — the operator has just installed it. Consider
     * the three cases the version rule allows, and code is never wanted in
     * any of them. Onto the same version, extracting it is a no-op that
     * rewrites thousands of files. Onto a NEWER installation it is a
     * silent downgrade, and one that contradicts the very next step: the
     * schema migration exists to bring an older dump forward, which needs
     * the newer code to bring it forward TO. Onto an older installation
     * nothing is extracted at all, because that archive was refused before
     * this method was ever reached.
     *
     * It also removes a hazard rather than managing one. Extracting
     * `core/` replaces the running process's own code mid-request — the
     * mixture that cost this project six consecutive rollbacks in
     * production, and the reason the ordinary restore defers its migration
     * to a later scheduler pass. A restore that never touches code has
     * nothing to defer.
     *
     * The entries stay IN the archive: it is one zip, and a human who
     * wants the tree it came from can open it.
     */
    private const RESTORED_TREE = 'storage/';


    /** @param array<string, mixed> $manifest */
    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly PortableKeys $keys,
        private readonly array $manifest
    ) {
    }

    /**
     * Opens and authenticates an archive.
     *
     * Every failure below is a refusal rather than a guess, and each one
     * says something different to the operator: this is not one of our
     * archives, this is not the right passphrase, this archive is damaged.
     * A single "impossible de restaurer" would leave them re-typing a
     * passphrase that was never the problem.
     *
     * @throws BackupException
     */
    public static function open(string $path, string $passphrase): self
    {
        if (!is_file($path)) {
            throw new BackupException('Le fichier de sauvegarde portable est introuvable.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new BackupException('Ce fichier n\'est pas une archive lisible.');
        }

        try {
            // In clear, and necessarily so: the archive password is derived
            // from these parameters, so nothing the password protects could
            // carry them. PortableKeys::parseComment() is also what refuses
            // a zip that is simply not ours, before a passphrase is ever
            // stretched — which is why the "wrong file" and "wrong phrase"
            // answers below can stay distinct.
            $derivation = PortableKeys::parseComment($zip->getArchiveComment());

            $keys = PortableKeys::derive($passphrase, $derivation);
            $zip->setPassword($keys->archivePassword());

            $json = $zip->getFromName(PortableManifest::MEMBER);
            if ($json === false) {
                // The manifest is encrypted like every other member, so a
                // wrong passphrase fails exactly here — on the first member
                // read, before anything else has been attempted.
                throw new BackupException(
                    'La phrase de passe ne correspond pas à cette archive, ou l\'archive est endommagée.'
                );
            }

            $manifest = json_decode($json, true);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== PortableManifest::FORMAT) {
                throw new BackupException('Le manifeste de cette sauvegarde portable est illisible.');
            }
            if (($manifest['format_version'] ?? null) !== PortableManifest::FORMAT_VERSION) {
                throw new BackupException(
                    'Cette sauvegarde portable a été écrite dans un format que cette version ne sait pas lire. '
                    . 'Mettez le site à jour, puis réessayez.'
                );
            }
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }

        return new self($zip, $keys, $manifest);
    }

    /**
     * Whether a file is a portable archive, answered without a passphrase.
     *
     * The restore page takes one upload field for every kind of archive,
     * so something has to decide which path a file goes down before the
     * operator is asked for anything. The archive comment is the honest
     * place to ask: it is in clear by necessity, it is written by nothing
     * else, and a file that lacks it is simply an ordinary backup — which
     * is why a false answer here is a routing decision and never a
     * security one. Everything that protects the archive is still checked
     * afterwards by {@see open()}.
     */
    public static function looksPortable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        $comment = $zip->getArchiveComment();
        $zip->close();

        try {
            PortableKeys::parseComment($comment);

            return true;
        } catch (BackupException) {
            return false;
        }
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /** The ScoutMagic version that wrote the archive. */
    public function version(): string
    {
        return (string) ($this->manifest['scoutmagic_version'] ?? VersionFile::UNKNOWN);
    }

    /**
     * The identifier of the installation that produced the archive, or null
     * when it never had one.
     *
     * The restored installation does NOT keep it (D6) — it is carried so
     * the new identity can name where it came from.
     */
    public function originInstallationId(): ?string
    {
        $value = $this->manifest['installation_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function includesGallery(): bool
    {
        return (bool) ($this->manifest['includes_gallery'] ?? false);
    }

    /**
     * Refuses an archive written by a ScoutMagic newer than this one.
     *
     * The asymmetry is deliberate and is the requirement's. Restoring onto
     * a NEWER installation is ordinary business: the dump is older than the
     * code, `Core\Database\MigrationRunner` brings the schema forward, and
     * that is the same thing that happens after any update. Restoring onto
     * an OLDER one has no such mechanism — there is no down-migration, and
     * the failure would not be a clean refusal but a database full of
     * columns this code does not know about, discovered one page at a time.
     *
     * **A development build is not ordered against anything**, in either
     * direction: `dev-a1b2c3d` sorts below every release under PHP's
     * comparison rules, which would let a dev archive through onto a
     * release and refuse the reverse — both answers arrived at by accident.
     * Where either side is a dev build, or either version is unknown, the
     * comparison abstains rather than inventing an order. That is a
     * deliberate hole, and it is the right size: a dev build is a checkout
     * someone is working in, not an installation a unit depends on.
     *
     * @throws BackupException
     */
    public function assertRestorableOnto(string $installedVersion): void
    {
        $archiveVersion = $this->version();

        if ($archiveVersion === VersionFile::UNKNOWN || $installedVersion === VersionFile::UNKNOWN) {
            return;
        }
        if (VersionFile::isDevBuild($archiveVersion) || VersionFile::isDevBuild($installedVersion)) {
            return;
        }

        if (version_compare($archiveVersion, $installedVersion, '>')) {
            throw new BackupException(
                'Cette sauvegarde a été faite avec une version plus récente de ScoutMagic (' . $archiveVersion
                . ') que celle installée ici (' . $installedVersion . '). Mettez ce site à jour, puis '
                . 'recommencez la restauration.'
            );
        }
    }

    /**
     * Checks the digest of every member the manifest names, before a byte
     * is extracted.
     *
     * Only the sealed secrets are named — the file trees are described and
     * not enumerated, for the reasons {@see PortableManifest::toArray()}
     * sets out, and the zip's own per-entry CRC is what guards those. These
     * two are worth the separate check because they are the members whose
     * corruption is silent: a damaged master key restores without complaint
     * and produces an installation that starts, serves pages, and cannot
     * read a single encrypted column.
     *
     * @throws BackupException
     */
    public function verifyDeclaredMembers(): void
    {
        $members = $this->manifest['members'] ?? null;
        if (!is_array($members) || $members === []) {
            throw new BackupException('Cette sauvegarde portable ne déclare aucun de ses fichiers.');
        }

        foreach ($members as $name => $facts) {
            if (!is_string($name) || !is_array($facts)) {
                throw new BackupException('Le manifeste de cette sauvegarde portable est illisible.');
            }

            $bytes = $this->zip->getFromName($name);
            if ($bytes === false) {
                throw new BackupException(
                    'Un fichier annoncé par cette sauvegarde portable est absent de l\'archive.',
                    0,
                    new \RuntimeException('Missing declared portable member: ' . $name)
                );
            }

            $expected = (string) ($facts['sha256'] ?? '');
            if ($expected === '' || !hash_equals($expected, hash('sha256', $bytes))) {
                throw new BackupException(
                    'Cette sauvegarde portable est endommagée : un de ses fichiers ne correspond pas à ce que '
                    . 'l\'archive annonce. Rien n\'a été modifié.',
                    0,
                    new \RuntimeException('Digest mismatch for portable member: ' . $name)
                );
            }
        }
    }

    /**
     * The archive's database dump, refused if it does not fit the ceiling.
     *
     * **The size is read from the entry's header before a byte is
     * decompressed**, which is the only order that helps: `getFromName()`
     * expands the whole member into a PHP string, so checking afterwards
     * checks a machine that has already run out of memory. A dump is
     * extremely compressible by nature — it is repetitive SQL — so this is
     * the member where a small file expanding to gigabytes is not even
     * adversarial, merely a large site.
     *
     * It sits here rather than in `restorableEntries()` because that walk
     * only sees `storage/`: the dump is a root-level member, so nothing
     * counted it against the ceiling this class declares.
     *
     * @throws BackupException
     */
    public function databaseDump(): string
    {
        $stat = $this->zip->statName('database.sql');
        if ($stat === false) {
            throw new BackupException('Cette sauvegarde portable ne contient pas de base de données.');
        }
        if ((int) $stat['size'] > self::MAX_RESTORE_UNCOMPRESSED_BYTES) {
            throw new BackupException('Archive de sauvegarde trop volumineuse une fois décompressée.');
        }

        $sql = $this->zip->getFromName('database.sql');
        if ($sql === false) {
            throw new BackupException('La base de données de cette sauvegarde portable est illisible.');
        }

        return $sql;
    }

    /**
     * The two secrets, unsealed, keyed by where they belong on the restored
     * installation (relative to the install root).
     *
     * The destination comes from the manifest's `restore_target` rather
     * than from the member's own name, which deliberately says nothing
     * about where it goes — see {@see PortableManifest::SECRET_MEMBERS}.
     *
     * @return array<string, string> restore target => plaintext bytes
     * @throws BackupException
     */
    public function unsealSecrets(): array
    {
        $secrets = [];

        foreach (PortableManifest::SECRET_MEMBERS as $liveRelativePath => $member) {
            $sealed = $this->zip->getFromName($member);
            if ($sealed === false) {
                throw new BackupException(
                    'Cette sauvegarde portable ne contient pas les clés de chiffrement du site — elle ne peut pas '
                    . 'servir à repartir ailleurs.',
                    0,
                    new \RuntimeException('Missing sealed secret member: ' . $member)
                );
            }

            // **The destination comes from THIS side, never from the
            // archive.** The manifest records a `restore_target` for each
            // secret, and reading it back would be reading a filesystem
            // path out of a document the archive's author wrote. The
            // manifest is encrypted, but with a key derived from the
            // passphrase that same author chose — encryption proves who
            // sealed it, and here that is precisely the untrusted party.
            // A hostile archive handed over with its passphrase (the
            // ordinary way this feature is used: "here is your backup from
            // the old host") could then name `../../public/index.php` and
            // have plaintext it also chose written there.
            //
            // Nothing is lost by ignoring it: `SECRET_MEMBERS` is the same
            // map the writer used, and it lives here.
            $secrets['storage/' . $liveRelativePath] = SecretEnvelope::open($sealed, $this->keys->envelopeKey());
        }

        return $secrets;
    }

    /**
     * Refuse an archive whose decompressed data exceeds this.
     *
     * The same ceiling `BackupService` applies to an ordinary restore, and
     * for the same reason: every upload limit on the way in bounds the
     * COMPRESSED file, which says nothing about what it expands to. A
     * portable archive arrives by the same operator-facing upload route
     * the ordinary restore considers dangerous enough to vet.
     */
    private const MAX_RESTORE_UNCOMPRESSED_BYTES = 4 * 1024 * 1024 * 1024;

    /**
     * The entries a restore actually writes: the site's data, and nothing
     * else.
     *
     * Returned as an explicit list rather than filtered afterwards, because
     * `ZipArchive::extractTo()` takes one and there is no undoing an entry
     * it has already written. See {@see RESTORED_TREE} for why the code
     * trees in the archive are not among them.
     *
     * @return string[]
     * @throws BackupException
     */
    public function restorableEntries(): array
    {
        $entries = [];
        $uncompressed = 0;

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) {
                throw new BackupException('Archive de sauvegarde illisible.');
            }

            // **An allow-list, and it is the only exclusion there is.**
            // `secrets/`, the manifest and `database.sql` are outside
            // `storage/`, so this one line already refuses them — and it
            // refuses them the way an allow-list does, by never having
            // said yes, rather than by naming each of them. The sealed
            // secrets in particular must never land as ordinary entries:
            // that would write the SEALED bytes where the live key
            // belongs. They are unsealed and written deliberately, by
            // {@see PortableRestore::installSecrets()}.
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (!str_starts_with($name, self::RESTORED_TREE)) {
                continue;
            }

            // A name that begins with `storage/` still has to BE inside
            // it. `extractTo()` normalises `..` itself, but an archive
            // containing one is not an archive we wrote — refuse it rather
            // than trust the library's normalisation, exactly as the
            // ordinary restore does.
            if ($name === '..'
                || str_contains($name, '/../')
                || str_ends_with($name, '/..')
                || preg_match('#^[A-Za-z]:#', $name) === 1
            ) {
                throw new BackupException('Archive de sauvegarde invalide (chemin non autorisé).');
            }

            // Symlinks are never restored: a later write would follow the
            // link out of the install root.
            $opsys = 0;
            $attr = 0;
            if ($this->zip->getExternalAttributesIndex($i, $opsys, $attr)
                && $opsys === \ZipArchive::OPSYS_UNIX
                && ((($attr >> 16) & 0xA000) === 0xA000)
            ) {
                throw new BackupException('Archive de sauvegarde invalide (lien symbolique).');
            }

            $uncompressed += (int) $stat['size'];
            if ($uncompressed > self::MAX_RESTORE_UNCOMPRESSED_BYTES) {
                throw new BackupException('Archive de sauvegarde trop volumineuse une fois décompressée.');
            }

            $entries[] = (string) $stat['name'];
        }

        return $entries;
    }

    /** The open handle, for the caller that extracts. */
    public function handle(): \ZipArchive
    {
        return $this->zip;
    }
}
