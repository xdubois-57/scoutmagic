<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

/**
 * `scoutmagic-backup.json`, at the root of every portable archive: what
 * this archive is, where it came from, and how its secrets were sealed.
 *
 * **It is what a restore reads before it writes anything** (IT-07). An
 * archive dropped on a new installation is otherwise a zip of directories
 * that look exactly like the ones already there, and the questions worth
 * answering — which version wrote this, which installation, was the
 * gallery in it, how was the key derived — are none of them answerable
 * from the file tree.
 *
 * **The derivation parameters are deliberately NOT here.** They live in
 * the zip's archive comment, in clear, because the archive password is
 * derived from them — anything the password protects could not carry them
 * without being circular. {@see PortableKeys} owns that header and says
 * why a salt in clear costs nothing.
 *
 * **This document is encrypted like every other member.** Nothing needs to
 * read it without the passphrase: whoever restores has just typed one, and
 * the comment already carries everything needed to get that far. What it
 * holds — which installation this came from, what was in it — is not
 * something to hand to whoever picks up the file.
 */
final class PortableManifest
{
    /** Where it sits in the archive. At the root, so a human finds it. */
    public const MEMBER = 'scoutmagic-backup.json';

    /**
     * A self-identifying marker, so that "is this one of ours?" never has
     * to be answered by looking at what directories happen to be inside.
     */
    public const FORMAT = 'scoutmagic-portable-backup';

    /**
     * Bumped when a later version changes the SHAPE of this document in a
     * way an older reader could misread. A reader that does not know a
     * version must refuse rather than guess, which is why the number is
     * here from the first archive ever written.
     *
     * 2 (#719): the archive comment carries mandatory hints
     * ({@see PortableArchiveHints}) — the version that wrote it, the site,
     * the date, the kind and the passphrase generation — so a bootstrap can
     * choose the release to install before anything is uploaded. Archives
     * of format 1 are not supported: the site was in test, with no data to
     * carry over.
     *
     * 3 (#1103): every file of the restored tree (`storage/…`) is a member,
     * with its SHA-256 and size, so an entry added without the passphrase
     * is one the manifest does not name. Archives of format 2 are refused
     * with a sentence of their own ({@see FORMAT_VERSION_WITHOUT_FILE_DIGESTS}).
     */
    public const FORMAT_VERSION = 3;

    /**
     * The last format whose `storage/` entries were not named: refused by
     * a restore, which can tell them neither from entries a stranger
     * appended nor from the original files (issue #1103).
     */
    public const FORMAT_VERSION_WITHOUT_FILE_DIGESTS = 2;

    /** What a restore says to an archive of that format — the header check and the manifest's alike. */
    public const REFUSED_WITHOUT_FILE_DIGESTS = 'Cette sauvegarde portable a été faite par une version de ScoutMagic '
        . 'antérieure à l\'authentification de chacun de ses fichiers : elle ne peut plus être restaurée, car rien '
        . 'ne distingue ses fichiers de fichiers ajoutés par un tiers. Rien n\'a été modifié. Faites une nouvelle '
        . 'sauvegarde portable depuis le site d\'origine, s\'il est encore en service.';

    /**
     * The two files this archive carries and no other one does: where they
     * live now, and what they are called inside the archive.
     *
     * Keys are relative to `storage/`, values are archive member names.
     * **One map, read by everything**: the walk that seals them, the
     * manifest that describes them, and eventually the restore that puts
     * them back. The doubled `.enc` on the second is not a slip —
     * the live file really is called `secrets.enc`, and the suffix marks
     * the envelope around it.
     *
     * The member names sit under `secrets/`, deliberately NOT under
     * `storage/`. An entry named `storage/keys/master.key` would be
     * extracted straight over the live key by any ordinary restore, which
     * for these files means writing the SEALED bytes over the real ones —
     * an installation locked out of its own database by a successful
     * restore. `Core\Maintenance\BackupService::assertArchiveEntriesAreSafe()`
     * refuses a top-level name it does not know, so today that refusal is
     * what stands between the two paths.
     *
     * @var array<string, string>
     */
    public const SECRET_MEMBERS = [
        'keys/master.key' => 'secrets/master.key.enc',
        'config/secrets.enc' => 'secrets/secrets.enc.enc',
    ];

    /** @var array<string, array<string, mixed>> member name => facts */
    private array $members = [];

    /**
     * @param string      $scoutmagicVersion what wrote it, from the VERSION file
     * @param string|null $installationId    the origin installation, or null
     *        when this one never had an identifier — an installation that
     *        never enabled usage statistics has none, and generating one
     *        here to fill a field would give a site an identity as a side
     *        effect of taking a backup. A restore assigns a NEW identifier
     *        anyway (D6), so the honest value is "unknown".
     */
    public function __construct(
        private readonly string $scoutmagicVersion,
        private readonly ?string $installationId,
        private readonly \DateTimeImmutable $createdAt
    ) {
    }

    /**
     * Records one member of the archive.
     *
     * @param string|null $restoreTarget where this member belongs on a
     *        restored installation, relative to the install root. Set for
     *        the sealed secrets, whose archive names deliberately do not
     *        say where they go; null for everything whose own name is
     *        already its destination.
     */
    public function addMember(string $name, string $sha256, int $bytes, ?string $restoreTarget = null): void
    {
        $facts = ['sha256' => $sha256, 'bytes' => $bytes];
        if ($restoreTarget !== null) {
            $facts['restore_target'] = $restoreTarget;
        }

        $this->members[$name] = $facts;
    }

    /**
     * The whole document.
     *
     * **Every file a portable restore puts back is a member** (issue
     * #1103, format 3): each `storage/…` entry with its SHA-256 and size,
     * besides the dump and the sealed secrets. This used to be described
     * but not enumerated, on the grounds that the zip's CRC-32 catches
     * corruption and that a digest sealed with the same passphrase as the
     * entry is no barrier to somebody who has it. The case that argument
     * missed is the one that matters: somebody WITHOUT the passphrase —
     * anybody able to write to the remote destination — can append an
     * entry in clear, with a perfectly good CRC, and it was extracted. A
     * manifest only the passphrase opens is what tells that entry apart.
     * The price is the one stated then: a second read of each file, once
     * when the archive is written and once before a restore writes
     * anything — paid on the rare restore rather than risked on it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'scoutmagic_version' => $this->scoutmagicVersion,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'installation_id' => $this->installationId,
            // **Always false, and the field stays.** Since D10 no archive
            // carries a directory declared as a storage location, so
            // there is no longer anything for a caller to decide. The
            // member is not dropped from the format: an archive written
            // before that change can legitimately say `true`, and
            // {@see PortableArchive::includesGallery()} still reads it —
            // a reader that met an absent field would have to guess, and
            // the guess it would make is the wrong one.
            'includes_gallery' => false,
            'includes_secrets' => array_values(self::SECRET_MEMBERS),
            'members' => $this->members,
        ];
    }

    /**
     * @throws \Core\Maintenance\BackupException when the document cannot be
     *         encoded — which would mean a member name that is not valid
     *         UTF-8, and an archive whose manifest is "false" is an archive
     *         nothing can ever read.
     */
    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \Core\Maintenance\BackupException(
                'Le manifeste de la sauvegarde portable n\'a pas pu être écrit.'
            );
        }

        return $json;
    }
}
