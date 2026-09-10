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
     * Members that must never be extracted over the install root.
     *
     * The sealed secrets are unsealed and written deliberately, to the
     * paths the manifest names; the manifest itself describes the archive
     * and belongs to no installation. Extracting either as an ordinary
     * entry would drop a `secrets/` directory and a stray JSON file into
     * the site root — and, for the secrets, would write the SEALED bytes
     * where the live key belongs.
     */
    private const NON_RESTORABLE_PREFIXES = ['secrets/'];

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
        $members = is_array($this->manifest['members'] ?? null) ? $this->manifest['members'] : [];
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

            $target = $members[$member]['restore_target'] ?? ('storage/' . $liveRelativePath);
            $secrets[(string) $target] = SecretEnvelope::open($sealed, $this->keys->envelopeKey());
        }

        return $secrets;
    }

    /**
     * The entries to extract over the install root: everything except the
     * archive's own bookkeeping.
     *
     * Returned as an explicit list rather than filtered afterwards, because
     * `ZipArchive::extractTo()` takes one and there is no undoing an entry
     * it has already written.
     *
     * @return string[]
     */
    public function restorableEntries(): array
    {
        $entries = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) {
                throw new BackupException('Archive de sauvegarde illisible.');
            }

            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === PortableManifest::MEMBER) {
                continue;
            }
            foreach (self::NON_RESTORABLE_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
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
