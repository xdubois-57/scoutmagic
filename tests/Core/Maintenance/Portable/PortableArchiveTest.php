<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableArchive;
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Portable\PortableManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The reader, tested against archives the writer actually produced.
 *
 * **Nothing here builds a zip by hand**, and that is the point: a
 * hand-assembled fixture is a second implementation of the format, and the
 * day the writer changes it is the day the fixture stops proving anything
 * while continuing to pass. Every archive below comes out of
 * `BackupService::createPortableBackup()`, which is why this carries
 * `@group database` — a real archive contains a real dump.
 *
 * The failure cases are made by damaging a real archive rather than by
 * describing damage to a mock, because what has to be proven is that the
 * refusal happens **before anything is written**, and a mock cannot be
 * wrong about that in the way a real read can.
 *
 * @group database
 */
#[Group('database')]
final class PortableArchiveTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const MASTER_KEY = 'trente-deux-octets-de-clef-maitre';
    private const SECRETS_BLOB = 'le blob chiffre des identifiants';
    private const ARCHIVE_VERSION = '2.4.1';
    private const ORIGIN_ID = 'aaaabbbbccccddddeeeeffff00001111';

    private string $basePath;
    private string $storagePath;
    private ?string $zipPath = null;
    private ?string $dbDumpPath = null;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/portable_restore_test_' . uniqid();
        $this->storagePath = $this->basePath . '/storage';

        $this->makeFile('core/App.php', '<?php // app');
        $this->makeFile('public/index.php', '<?php // entry');
        $this->makeFile('storage/uploads/doc.pdf', 'fake-pdf-bytes');
        $this->makeFile('storage/keys/master.key', self::MASTER_KEY);
        $this->makeFile('storage/keys/dkim_private.pem', 'a dkim key, which does NOT travel');
        $this->makeFile('storage/config/secrets.enc', self::SECRETS_BLOB);
    }

    protected function tearDown(): void
    {
        foreach ([$this->zipPath, $this->dbDumpPath] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->basePath);
    }

    public function testItOpensAnArchiveAndSaysWhatItIs(): void
    {
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

        $this->assertSame(self::ARCHIVE_VERSION, $archive->version());
        $this->assertSame(self::ORIGIN_ID, $archive->originInstallationId());
        $this->assertFalse($archive->includesGallery());

        $archive->close();
    }

    /**
     * A wrong passphrase and a foreign file are different problems, and the
     * operator is told which one they have.
     *
     * Collapsing both into one message would leave somebody re-typing a
     * passphrase that was never wrong, or hunting for a file that was never
     * the one they picked.
     */
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

    public function testAZipThatIsNotOneOfOursIsRefusedBeforeThePassphraseMatters(): void
    {
        $foreign = sys_get_temp_dir() . '/not_scoutmagic_' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($foreign, \ZipArchive::CREATE) === true);
        $zip->addFromString('readme.txt', 'just a zip');
        $zip->close();

        try {
            PortableArchive::open($foreign, self::PASSPHRASE);
            $this->fail('A foreign zip was accepted as a portable archive.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('en-tête', $e->getMessage());
            $this->assertStringNotContainsString('phrase de passe', $e->getMessage());
        } finally {
            @unlink($foreign);
        }
    }

    public function testAMissingFileIsRefusedRatherThanOpened(): void
    {
        $this->expectException(BackupException::class);
        PortableArchive::open($this->basePath . '/nowhere.zip', self::PASSPHRASE);
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
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

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
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

        $archive->assertRestorableOnto(self::ARCHIVE_VERSION);
        $archive->assertRestorableOnto('2.5.0');
        $archive->assertRestorableOnto('10.0.0');

        $this->expectNotToPerformAssertions();
        $archive->close();
    }

    /**
     * A development build is not ordered against a release, in either
     * direction — see `assertRestorableOnto()` for why abstaining beats
     * inventing an order out of `version_compare()`'s ranking of "dev".
     */
    public function testADevelopmentBuildIsNotOrderedAgainstAnything(): void
    {
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

        $archive->assertRestorableOnto('dev-a1b2c3d');
        $archive->assertRestorableOnto('0.0.0');

        $this->expectNotToPerformAssertions();
        $archive->close();
    }

    public function testTheDeclaredMembersMatchWhatTheArchiveActuallyHolds(): void
    {
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

        $archive->verifyDeclaredMembers();

        $this->expectNotToPerformAssertions();
        $archive->close();
    }

    /**
     * **A damaged secret is caught before extraction, not after.**
     *
     * This is the corruption that is otherwise silent: a master key off by
     * one byte restores without complaint and leaves an installation that
     * starts, serves pages, and cannot read a single encrypted column. The
     * operator would conclude their backup was worthless — on the day they
     * needed it, having already overwritten what they had.
     */
    public function testATamperedSecretIsRefusedBeforeAnythingIsWritten(): void
    {
        $path = $this->buildArchive();
        $this->replaceMember($path, PortableManifest::SECRET_MEMBERS['keys/master.key'], 'des octets substitués');

        $archive = PortableArchive::open($path, self::PASSPHRASE);

        try {
            $archive->verifyDeclaredMembers();
            $this->fail('A member that does not match its declared digest was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('endommagée', $e->getMessage());
            // The member name is diagnostic, not something to put in front
            // of an operator — same rule as every other BackupException.
            // It still has to reach whoever is diagnosing, which is what
            // $previous is for throughout this feature.
            $this->assertStringNotContainsString('secrets/', $e->getMessage());
            $this->assertStringContainsString(
                'secrets/',
                $e->getPrevious()?->getMessage() ?? '',
                'the member that failed its digest is named nowhere, not even on the trace'
            );
        } finally {
            $archive->close();
        }
    }

    public function testTheSecretsComeBackAsTheyWentIn(): void
    {
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

        $secrets = $archive->unsealSecrets();

        $this->assertSame(self::MASTER_KEY, $secrets['storage/keys/master.key'] ?? null);
        $this->assertSame(self::SECRETS_BLOB, $secrets['storage/config/secrets.enc'] ?? null);

        $archive->close();
    }

    /**
     * What gets extracted, and — more importantly — what does not.
     *
     * `secrets/` must never be written as an ordinary entry: those members
     * hold the SEALED bytes, and putting them anywhere near the live paths
     * is how a successful restore locks an installation out of its own
     * database. The manifest describes the archive and belongs to no
     * installation.
     */
    public function testTheArchivesOwnBookkeepingIsNeverExtracted(): void
    {
        $archive = PortableArchive::open($this->buildArchive(), self::PASSPHRASE);

        $entries = $archive->restorableEntries();

        $this->assertNotContains(PortableManifest::MEMBER, $entries);
        foreach ($entries as $entry) {
            $this->assertStringStartsNotWith('secrets/', $entry, 'a sealed secret would be extracted as a plain file');
        }
        $this->assertContains('database.sql', $entries);
        $this->assertNotEmpty(
            array_filter($entries, static fn (string $e): bool => str_starts_with($e, 'storage/uploads/')),
            'the file trees the archive exists to carry are not in the extraction list'
        );

        $archive->close();
    }

    /** Builds a real archive and returns its path. */
    private function buildArchive(): string
    {
        $service = new BackupService($this->realDbConnection(), $this->storagePath, $this->basePath);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, self::ARCHIVE_VERSION, self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        return $this->zipPath;
    }

    /**
     * Swaps one member's contents, keeping it encrypted under the same
     * archive password — the shape a real corruption or a real tampering
     * takes, rather than a truncated file the zip layer would catch first.
     */
    private function replaceMember(string $zipPath, string $member, string $contents): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $comment = $zip->getArchiveComment();
        $keys = PortableKeys::derive(self::PASSPHRASE, PortableKeys::parseComment($comment));

        $this->assertTrue($zip->addFromString($member, $contents));
        $this->assertTrue($zip->setEncryptionName($member, \ZipArchive::EM_AES_256, $keys->archivePassword()));
        $zip->close();
    }

    private function makeFile(string $relativePath, string $content): void
    {
        $path = $this->basePath . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function realDbConnection(): Connection
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database not available: ' . (is_string($result) ? $result : 'unknown error'));
        }

        $introspector = new SchemaIntrospector($connection->getPdo());
        $runner = new MigrationRunner($connection, $introspector, new SchemaComparator(), new SqlParser());
        $runner->migrate([dirname(__DIR__, 4) . '/schema/core.sql']);

        return $connection;
    }
}
