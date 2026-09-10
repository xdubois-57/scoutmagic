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
 * **The derivation parameters live here and nowhere else.** They could
 * have been a header on each sealed file, and that would have made each
 * envelope self-describing; it would also have allowed two envelopes in
 * one archive to claim different derivations, which is a state nothing
 * could act on sensibly. One archive, one derivation, written once.
 *
 * **It is encrypted like every other member**, not left in clear for
 * convenience. Nothing needs to read it without the passphrase — whoever
 * restores has just typed one — and in clear it would hand an attacker who
 * has the file but not the password the salt and the cost parameters,
 * which is precisely the head start the second lock exists to deny. The
 * zip's own central directory already reveals the shape of the archive;
 * that is not a reason to add its contents.
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
     */
    public const FORMAT_VERSION = 1;

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

    /** @var array<string, mixed> */
    private array $derivation = [];

    /**
     * @param string      $scoutmagicVersion what wrote it, from the VERSION file
     * @param string|null $installationId    the origin installation, or null
     *        when this one never had an identifier — an installation that
     *        never enabled usage statistics has none, and generating one
     *        here to fill a field would give a site an identity as a side
     *        effect of taking a backup. A restore assigns a NEW identifier
     *        anyway (D6), so the honest value is "unknown".
     * @param bool        $includesGallery   whether `storage/gallery/` is in it
     */
    public function __construct(
        private readonly string $scoutmagicVersion,
        private readonly ?string $installationId,
        private readonly bool $includesGallery,
        private readonly \DateTimeImmutable $createdAt
    ) {
    }

    /** @param array<string, mixed> $params as {@see SecretEnvelope::seal()} returned them */
    public function describeDerivation(array $params): void
    {
        $this->derivation = $params;
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
     * **The file trees are described but not enumerated**, and that is a
     * departure from "a digest of every member" worth stating plainly. A
     * portable archive holds tens of thousands of files; a SHA-256 line
     * for each would add a second full read of the site to a job that
     * already reads all of it once, and produce a manifest larger than
     * some of the files it describes. What it would buy is nearly nothing:
     * the zip format already stores a CRC-32 per entry and verifies it on
     * extraction, which is what catches the corruption that actually
     * happens to an archive carried on a USB stick — and against an
     * attacker who can rewrite an entry, a digest list sealed with the
     * same passphrase as the entry is no barrier at all. The members that
     * ARE listed are the ones a restore handles by name.
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
            'includes_gallery' => $this->includesGallery,
            'includes_secrets' => array_values(self::SECRET_MEMBERS),
            'secret_derivation' => $this->derivation,
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
