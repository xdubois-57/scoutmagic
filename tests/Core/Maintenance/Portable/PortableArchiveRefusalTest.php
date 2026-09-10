<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Maintenance\Portable\PortableArchive;
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Portable\PortableManifest;
use PHPUnit\Framework\TestCase;

/**
 * The refusals that need no database, kept where no database can silence
 * them.
 *
 * **This class exists because of what its sibling cannot promise.**
 * `PortableArchiveTest` builds its archives through `BackupService`, which
 * needs a real server for the dump inside them, so every one of its cases
 * skips when none answers — including the ones about nothing but the
 * archive's own header. On a machine without MySQL the refusals this
 * feature exists for were green and untested, which is worse than absent:
 * a skipped test reads as a passing one at a glance.
 *
 * So the cases here are the ones whose subject is the FORMAT — the version
 * rule, the format version, a damaged member — and the archives are built
 * with `ZipArchive` and `PortableKeys` directly. That is a hand-built
 * fixture, which the sibling class deliberately refuses; the difference is
 * what each is for. Its subject is what the writer produces, so a
 * second-hand fixture would prove nothing about the writer. This one's
 * subject is what the READER refuses, and a reader that refuses a hostile
 * or malformed file must not need a well-formed one to be refusing it.
 */
final class PortableArchiveRefusalTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const ARCHIVE_VERSION = '2.4.1';

    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * **The version rule, and its deliberate asymmetry.**
     *
     * Onto the same version, or a newer one, a restore is ordinary: the
     * dump is older than the code and `MigrationRunner` brings it forward,
     * exactly as after an update. Onto an OLDER installation there is no
     * such mechanism, and the damage would not announce itself — it would
     * surface one page at a time.
     */
    public function testItRefusesToRestoreOntoAnOlderScoutMagic(): void
    {
        $archive = $this->open();

        try {
            $archive->assertRestorableOnto('2.4.0');
            $this->fail('An archive from a newer ScoutMagic was accepted onto an older one.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('plus récente', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    public function testTheSameOrANewerInstallationAcceptsIt(): void
    {
        $archive = $this->open();

        $archive->assertRestorableOnto(self::ARCHIVE_VERSION);
        $archive->assertRestorableOnto('2.5.0');
        $archive->assertRestorableOnto('10.0.0');

        // Reached at all, so none of the three threw.
        $this->assertSame(self::ARCHIVE_VERSION, $archive->version());
        $archive->close();
    }

    /**
     * A development build is not ordered against a release, in either
     * direction — see `assertRestorableOnto()` for why abstaining beats
     * inventing an order out of `version_compare()`'s ranking of "dev".
     */
    public function testADevelopmentBuildIsNotOrderedAgainstAnything(): void
    {
        $archive = $this->open();

        $archive->assertRestorableOnto('dev-a1b2c3d');
        $archive->assertRestorableOnto('0.0.0');

        // Reached at all, so neither threw.
        $this->assertSame(self::ARCHIVE_VERSION, $archive->version());
        $archive->close();
    }

    public function testAWrongPassphraseIsRefusedAsAWrongPassphrase(): void
    {
        $path = $this->buildArchive();

        try {
            PortableArchive::open($path, 'une phrase tout à fait différente');
            $this->fail('A wrong passphrase opened the archive.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('phrase de passe', $e->getMessage());
        }
    }

    public function testAZipWithoutOurHeaderIsRefusedBeforeThePassphraseMatters(): void
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE) === true);
        $zip->addFromString('readme.txt', 'just a zip');
        $zip->close();

        try {
            PortableArchive::open($path, self::PASSPHRASE);
            $this->fail('A foreign zip was accepted as a portable archive.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('en-tête', $e->getMessage());
            $this->assertStringNotContainsString('phrase de passe', $e->getMessage());
        }
    }

    /**
     * A format version this code does not know is refused rather than
     * guessed at — the reason the number is in the manifest from the very
     * first archive ever written.
     */
    public function testAFormatVersionFromTheFutureIsRefused(): void
    {
        $path = $this->buildArchive(['format_version' => PortableManifest::FORMAT_VERSION + 1]);

        try {
            PortableArchive::open($path, self::PASSPHRASE);
            $this->fail('An archive in an unknown format version was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('format', $e->getMessage());
        }
    }

    /**
     * A member whose bytes do not match the digest the manifest declares.
     *
     * This is the corruption that is otherwise silent: a master key off by
     * one byte restores without complaint and leaves an installation that
     * starts, serves pages, and cannot read a single encrypted column.
     */
    public function testAMemberThatDoesNotMatchItsDigestIsRefused(): void
    {
        $archive = $this->open(['corruptMasterKey' => true]);

        try {
            $archive->verifyDeclaredMembers();
            $this->fail('A member that does not match its declared digest was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('endommagée', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /**
     * A file that is not a zip at all — the very first thing this reader
     * touches, and the answer an operator gets when they pick the wrong
     * file in the wizard.
     */
    public function testAFileThatIsNotAZipIsRefusedAsUnreadable(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, 'ceci n\'est pas une archive');

        try {
            PortableArchive::open($path, self::PASSPHRASE);
            $this->fail('A file that is not a zip was opened as an archive.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('lisible', $e->getMessage());
        }
    }

    /**
     * `looksPortable()` is a ROUTING decision, so it answers rather than
     * throws — including about a path that is not there at all.
     */
    public function testLooksPortableAnswersAboutFilesRatherThanThrowing(): void
    {
        $this->assertFalse(PortableArchive::looksPortable($this->tempPath() . '_absent'));
        $this->assertTrue(PortableArchive::looksPortable($this->buildArchive()));
    }

    /**
     * A manifest that declares no members at all.
     *
     * The digest check has nothing to check, and "nothing to check"
     * must not read as "everything checked out" — that is the whole
     * distance between a verification and a formality.
     */
    public function testAManifestDeclaringNoMembersIsRefused(): void
    {
        $archive = $this->open(['members' => []]);

        try {
            $archive->verifyDeclaredMembers();
            $this->fail('An archive declaring none of its files was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('ne déclare aucun', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /** A member the manifest announces and the archive does not hold. */
    public function testADeclaredMemberMissingFromTheArchiveIsRefused(): void
    {
        $archive = $this->open(['members' => ['secrets/absent.enc' => ['sha256' => str_repeat('0', 64)]]]);

        try {
            $archive->verifyDeclaredMembers();
            $this->fail('An archive missing a file it announces was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('absent', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /**
     * An archive with no database in it is refused where the dump is read,
     * not where the files are.
     */
    public function testAnArchiveWithoutADatabaseIsRefused(): void
    {
        $archive = $this->open(['dropDatabase' => true]);

        try {
            $archive->databaseDump();
            $this->fail('An archive with no database was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('base de données', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /**
     * And an archive with no sealed keys is refused for what it is: an
     * ordinary full backup, which cannot be carried elsewhere.
     */
    public function testAnArchiveWithoutTheSealedKeysIsRefused(): void
    {
        $archive = $this->open(['dropSecrets' => true]);

        try {
            $archive->unsealSecrets();
            $this->fail('An archive carrying no keys was accepted as a portable one.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('clés de chiffrement', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /**
     * A symlink under `storage/` is refused rather than extracted: a later
     * write would follow the link out of the install root.
     */
    public function testASymbolicLinkUnderStorageIsRefused(): void
    {
        $archive = $this->open(['symlink' => true]);

        try {
            $archive->restorableEntries();
            $this->fail('An archive containing a symbolic link was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('lien symbolique', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /** And a path that leaves `storage/` on its way back in. */
    public function testAPathThatClimbsOutOfTheSiteIsRefused(): void
    {
        $archive = $this->open(['traversal' => true]);

        try {
            $archive->restorableEntries();
            $this->fail('An archive naming a path outside the install root was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('chemin non autorisé', $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    /** @param array<string, mixed> $options */
    private function open(array $options = []): PortableArchive
    {
        return PortableArchive::open($this->buildArchive($options), self::PASSPHRASE);
    }

    /**
     * A minimal but genuine portable archive.
     *
     * The header and the envelopes come from `PortableKeys` and
     * `SecretEnvelope` themselves — a hand-written comment or a
     * hand-rolled envelope would be a second opinion about the format,
     * able to agree with nothing.
     *
     * @param array<string, mixed> $options
     */
    private function buildArchive(array $options = []): string
    {
        $path = $this->tempPath();
        $derivation = PortableKeys::newDerivation();
        $keys = PortableKeys::derive(self::PASSPHRASE, $derivation);
        $password = $keys->archivePassword();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $members = [];
        foreach (PortableManifest::SECRET_MEMBERS as $liveRelativePath => $member) {
            $plaintext = 'secret pour ' . $liveRelativePath;
            $sealed = \Core\Maintenance\Portable\SecretEnvelope::seal($plaintext, $keys->envelopeKey());
            $stored = ($options['corruptMasterKey'] ?? false) === true && $liveRelativePath === 'keys/master.key'
                ? 'des octets substitués'
                : $sealed;

            if (($options['dropSecrets'] ?? false) !== true) {
                $this->addEncrypted($zip, $member, $stored, $password);
            }
            // The digest of what the archive CLAIMS to hold, so a corrupted
            // member disagrees with its own manifest.
            $members[$member] = ['sha256' => hash('sha256', $sealed), 'bytes' => strlen($sealed)];
        }

        if (($options['dropDatabase'] ?? false) !== true) {
            $this->addEncrypted($zip, 'database.sql', '-- un dump', $password);
        }
        $this->addEncrypted($zip, 'storage/uploads/doc.pdf', 'des octets', $password);

        if (($options['traversal'] ?? false) === true) {
            $this->assertTrue($zip->addFromString('storage/../../evil.txt', 'des octets choisis ailleurs'));
        }
        if (($options['symlink'] ?? false) === true) {
            // The attribute is what makes it a link, not the name — the
            // reader has to look at what the entry IS.
            $this->assertTrue($zip->addFromString('storage/uploads/lien', '/etc/passwd'));
            $this->assertTrue($zip->setExternalAttributesName(
                'storage/uploads/lien',
                \ZipArchive::OPSYS_UNIX,
                (0xA000 | 0777) << 16
            ));
        }

        $manifest = [
            'format' => PortableManifest::FORMAT,
            'format_version' => $options['format_version'] ?? PortableManifest::FORMAT_VERSION,
            'scoutmagic_version' => self::ARCHIVE_VERSION,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'installation_id' => 'aaaabbbbccccddddeeeeffff00001111',
            'includes_gallery' => false,
            'includes_secrets' => array_values(PortableManifest::SECRET_MEMBERS),
            'members' => $options['members'] ?? $members,
        ];
        $this->addEncrypted($zip, PortableManifest::MEMBER, (string) json_encode($manifest), $password);

        $this->assertTrue($zip->setArchiveComment(PortableKeys::comment($derivation)));
        $zip->close();

        return $path;
    }

    private function addEncrypted(\ZipArchive $zip, string $name, string $contents, string $password): void
    {
        $this->assertTrue($zip->addFromString($name, $contents));
        $this->assertTrue($zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $password));
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/portable_refusal_' . bin2hex(random_bytes(8)) . '.zip';
        $this->paths[] = $path;

        return $path;
    }
}
