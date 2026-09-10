<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableManifest;
use Core\Maintenance\Portable\SecretEnvelope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A portable archive, actually built, then opened and inspected.
 *
 * `SecretEnvelopeTest` proves the lock; this proves the archive is locked —
 * which is a different claim and the one an operator is relying on. The
 * failure it exists to catch is not a broken cipher but a correct cipher
 * wired past: the master key added to the zip by the ordinary directory
 * walk, in clear under the zip's own 1000-iteration derivation, with
 * everything else about the feature working perfectly.
 *
 * `@group database` because a portable archive contains a real database
 * dump, and `BackupService::createDatabaseDump()` needs a real engine.
 */
#[Group('database')]
final class PortableBackupArchiveTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const MASTER_KEY = 'trente-deux-octets-de-clef-maitre';
    private const SECRETS_BLOB = 'le blob chiffre des identifiants';

    private string $basePath;
    private string $storagePath;
    private ?string $zipPath = null;
    private ?string $dbDumpPath = null;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/portable_backup_test_' . uniqid();
        $this->storagePath = $this->basePath . '/storage';

        $this->makeFile('core/App.php', '<?php // app');
        $this->makeFile('public/index.php', '<?php // entry');
        $this->makeFile('modules/gallery/module.json', '{}');
        $this->makeFile('storage/uploads/doc.pdf', 'fake-pdf-bytes');
        $this->makeFile('storage/gallery/1/photo.jpg', 'fake-jpeg-bytes');
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

    /**
     * Builds the archive once per test that needs it, and returns an open
     * ZipArchive positioned on it.
     */
    private function buildArchive(): \ZipArchive
    {
        $service = new BackupService($this->realDbConnection(), $this->storagePath, $this->basePath);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', 'install-abc');
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zipPath) === true, 'The archive could not be reopened.');

        return $zip;
    }

    /** @return string[] every entry name in the archive */
    private function entryNames(\ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $names[] = (string) $stat['name'];
            }
        }

        return $names;
    }

    private function read(\ZipArchive $zip, string $entry): string
    {
        $zip->setPassword(self::PASSPHRASE);
        $contents = $zip->getFromName($entry);
        $this->assertIsString($contents, $entry . ' could not be read from the archive.');

        return $contents;
    }

    /** @return array<string, mixed> */
    private function manifest(\ZipArchive $zip): array
    {
        $decoded = json_decode($this->read($zip, PortableManifest::MEMBER), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testTheArchiveCarriesTheSecretsAndTheManifest(): void
    {
        $zip = $this->buildArchive();
        $names = $this->entryNames($zip);

        $this->assertContains(PortableManifest::MEMBER, $names);
        foreach (PortableManifest::SECRET_MEMBERS as $member) {
            $this->assertContains($member, $names);
        }
        $this->assertContains('database.sql', $names);
        $zip->close();
    }

    /**
     * **The assertion this whole file exists for.**
     *
     * The secrets are in the archive, and the bytes are NOT the secrets.
     * Verified by writing the plaintext into the member instead of the
     * sealed bytes — a one-word slip at the one call site — which fails
     * here and in the manifest test, and nowhere else: the archive would
     * otherwise be complete, correctly named, and readable by anyone who
     * breaks a 1000-iteration derivation.
     *
     * The neighbouring danger — routing these two files through the
     * ordinary directory walk, which would add them in clear UNDER THEIR
     * LIVE NAMES — is caught by
     * {@see testNoEntryWouldOverwriteTheLiveSecretsOnExtraction()} and
     * {@see testTheGalleryAndTheOtherKeysStayOnTheServer()} instead, both
     * confirmed by removing the exclusion and watching them fail. Two
     * different mistakes, two different guards; neither test covers the
     * other's case, which is why both are here.
     */
    public function testTheSecretsAreSealedRatherThanMerelyIncluded(): void
    {
        $zip = $this->buildArchive();

        $sealedKey = $this->read($zip, PortableManifest::SECRET_MEMBERS['keys/master.key']);
        $sealedSecrets = $this->read($zip, PortableManifest::SECRET_MEMBERS['config/secrets.enc']);

        $this->assertStringNotContainsString(self::MASTER_KEY, $sealedKey);
        $this->assertStringNotContainsString(self::SECRETS_BLOB, $sealedSecrets);

        // And they really are the secrets, not merely different bytes.
        $derivation = $this->manifest($zip)['secret_derivation'];
        $this->assertIsArray($derivation);
        $this->assertSame(self::MASTER_KEY, SecretEnvelope::open($sealedKey, self::PASSPHRASE, $derivation));
        $this->assertSame(self::SECRETS_BLOB, SecretEnvelope::open($sealedSecrets, self::PASSPHRASE, $derivation));
        $zip->close();
    }

    /**
     * No entry names the live path of a secret.
     *
     * An entry called `storage/keys/master.key` would be extracted over
     * the real key by any ordinary restore — writing the SEALED bytes on
     * top of it, and locking the installation out of its own database
     * through a restore that reported success.
     */
    public function testNoEntryWouldOverwriteTheLiveSecretsOnExtraction(): void
    {
        $zip = $this->buildArchive();

        foreach ($this->entryNames($zip) as $name) {
            $this->assertStringNotContainsString('storage/keys/', $name);
            $this->assertStringNotContainsString('storage/config/', $name);
        }
        $zip->close();
    }

    /** Only the two secrets are sealed twice; nothing else is. */
    public function testOnlyTheSecretsCarryTheSecondEnvelope(): void
    {
        $zip = $this->buildArchive();

        // An ordinary member is itself once the zip layer is off — the
        // envelope is not applied to the whole archive.
        $this->assertSame('<?php // app', $this->read($zip, 'core/App.php'));
        $this->assertStringContainsString('INSERT INTO', $this->read($zip, 'database.sql'));
        $zip->close();
    }

    /**
     * A wrong passphrase opens nothing, at either layer.
     *
     * The zip layer refuses first, which is already enough — but the point
     * of the second envelope is that it would refuse too, so the test
     * checks the layer that matters rather than the one that happens to
     * come first.
     */
    public function testAWrongPassphraseOpensNeitherLayer(): void
    {
        $zip = $this->buildArchive();
        $derivation = $this->manifest($zip)['secret_derivation'];
        $sealedKey = $this->read($zip, PortableManifest::SECRET_MEMBERS['keys/master.key']);
        $zip->close();

        $this->expectException(BackupException::class);
        SecretEnvelope::open($sealedKey, 'une phrase de passe entierement fausse', is_array($derivation) ? $derivation : []);
    }

    public function testTheManifestDescribesWhatIsActuallyInTheArchive(): void
    {
        $zip = $this->buildArchive();
        $manifest = $this->manifest($zip);

        $this->assertSame(PortableManifest::FORMAT, $manifest['format']);
        $this->assertSame('2.4.1', $manifest['scoutmagic_version']);
        $this->assertSame('install-abc', $manifest['installation_id']);
        $this->assertFalse($manifest['includes_gallery']);

        // The digests are of the members as they sit in the archive, so a
        // reader can check one without unsealing it.
        $members = $manifest['members'];
        $this->assertIsArray($members);
        foreach (PortableManifest::SECRET_MEMBERS as $livePath => $member) {
            $this->assertSame(
                hash('sha256', $this->read($zip, $member)),
                $members[$member]['sha256'],
                $member . ' does not hash to what the manifest claims.'
            );
            $this->assertSame('storage/' . $livePath, $members[$member]['restore_target']);
        }

        $this->assertSame(hash_file('sha256', (string) $this->dbDumpPath), $members['database.sql']['sha256']);
        $zip->close();
    }

    /**
     * The manifest is encrypted like every other member.
     *
     * In clear it would hand somebody holding the file — but not the
     * passphrase — the salt and the cost parameters, which is exactly the
     * head start the second lock exists to deny. Reading it without a
     * password has to fail.
     */
    public function testTheManifestIsNotReadableWithoutThePassphrase(): void
    {
        $zip = $this->buildArchive();
        $zip->close();

        $unauthenticated = new \ZipArchive();
        $this->assertTrue($unauthenticated->open((string) $this->zipPath) === true);
        $this->assertFalse(@$unauthenticated->getFromName(PortableManifest::MEMBER));
        $unauthenticated->close();
    }

    /**
     * The gallery stays behind, and so does everything else the ordinary
     * exclusions drop.
     *
     * This archive is the one meant to leave the server and, from IT-08, to
     * be uploaded on a schedule: the photos are what would make it too big
     * for both. The DKIM key is a second check on the same mechanism — the
     * exclusion of `storage/keys/` is still absolute, and only the two
     * files named in the map travel, by their own sealed path.
     */
    public function testTheGalleryAndTheOtherKeysStayOnTheServer(): void
    {
        $zip = $this->buildArchive();
        $names = $this->entryNames($zip);

        foreach ($names as $name) {
            $this->assertStringNotContainsString('storage/gallery/', $name);
            $this->assertStringNotContainsString('dkim_private.pem', $name);
        }
        // And the ordinary content is there, so the absence above is an
        // exclusion rather than an empty archive.
        $this->assertContains('storage/uploads/doc.pdf', $names);
        $zip->close();
    }

    /**
     * A missing secret refuses the whole backup.
     *
     * The tempting alternative is to skip it and carry on: the archive
     * would be produced, look complete, and be restorable nowhere. An
     * operator would find out on the day they needed it, which is the one
     * day this feature exists for.
     */
    public function testAnUnreadableSecretRefusesTheArchiveRatherThanShippingItIncomplete(): void
    {
        unlink($this->storagePath . '/keys/master.key');

        $service = new BackupService($this->realDbConnection(), $this->storagePath, $this->basePath);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption.');
        }

        $this->expectException(BackupException::class);
        $service->createPortableBackup(self::PASSPHRASE, '2.4.1', null);
    }

    public function testAnEmptyPassphraseIsRefusedByTheServiceToo(): void
    {
        $service = new BackupService($this->realDbConnection(), $this->storagePath, $this->basePath);

        $this->expectException(BackupException::class);
        $service->createPortableBackup('', '2.4.1', null);
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
        $runner->migrate([dirname(__DIR__, 3) . '/schema/core.sql']);

        return $connection;
    }
}
