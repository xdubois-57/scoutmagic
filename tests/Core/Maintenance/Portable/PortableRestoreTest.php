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
use Core\Maintenance\Portable\PortableManifest;
use Core\Maintenance\Portable\PortableRestore;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * One installation's archive, restored onto a different installation.
 *
 * **Two real install roots, never one.** The failure this feature exists to
 * prevent, and the one D5 is written against, only appears when the machine
 * being restored ONTO has credentials of its own to lose. A test that
 * restores an archive over the installation that produced it would pass
 * with the credential handling deleted entirely, because both sides would
 * hold the same values.
 *
 * @group database
 */
#[Group('database')]
final class PortableRestoreTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const ORIGIN_ID = 'aaaabbbbccccddddeeeeffff00001111';

    /** What only the origin has, and what the restore exists to carry. */
    private const ORIGIN_ENCRYPTION_KEY = 'la-clef-de-colonne-de-l-origine=';

    private string $originBase;
    private string $targetBase;
    private ?string $zipPath = null;
    private ?string $dbDumpPath = null;
    private string $originMasterKey;

    protected function setUp(): void
    {
        $this->originBase = sys_get_temp_dir() . '/portable_origin_' . uniqid();
        $this->targetBase = sys_get_temp_dir() . '/portable_target_' . uniqid();
        $this->originMasterKey = random_bytes(32);

        // The origin: content, and secrets naming ITS database.
        $this->makeFile($this->originBase, 'core/App.php', '<?php // origin app');
        $this->makeFile($this->originBase, 'public/index.php', '<?php // origin entry');
        $this->makeFile($this->originBase, 'storage/uploads/tresorerie.pdf', 'les comptes de l\'unite');
        $this->writeSecrets($this->originBase, $this->originMasterKey, [
            'db_host' => 'ancien-hebergeur.example',
            'db_port' => 3306,
            'db_name' => 'ancienne_base',
            'db_user' => 'ancien_user',
            'db_password' => 'ancien-mot-de-passe',
            'base_url' => 'https://ancien.example',
            'encryption_key' => self::ORIGIN_ENCRYPTION_KEY,
            'smtp_host' => 'smtp.example',
            'vapid_private_key' => 'la-clef-vapid-de-l-origine',
        ]);

        // The target: a different machine, with credentials of its own that
        // the restore must not touch.
        $this->makeFile($this->targetBase, 'storage/uploads/.gitkeep', '');
        $this->writeSecrets($this->targetBase, random_bytes(32), $this->targetOwnedSecrets() + [
            'encryption_key' => 'la-clef-du-site-neuf-a-remplacer',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->zipPath, $this->dbDumpPath] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->originBase);
        $this->removeDirectory($this->targetBase);
    }

    /** @return array<string, mixed> */
    private function targetOwnedSecrets(): array
    {
        return [
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_name' => 'base_du_site_neuf',
            'db_user' => 'user_du_site_neuf',
            'db_password' => 'mot-de-passe-du-site-neuf',
            'base_url' => 'https://nouveau.example',
        ];
    }

    /**
     * **The one that matters: the new machine keeps its own database.**
     *
     * Writing the origin's `secrets.enc` wholesale is the obvious
     * implementation and the one that bricks a fresh installation — it
     * points the new site at the old site's database host, name and
     * password. Either those are wrong and the site will not start, or
     * they are right and the new site is quietly writing into a database
     * that still belongs to somebody else.
     */
    public function testTheTargetKeepsItsOwnDatabaseCredentials(): void
    {
        $this->restoreOntoTarget();

        $restored = $this->readSecrets($this->targetBase);

        foreach ($this->targetOwnedSecrets() as $key => $expected) {
            $this->assertSame($expected, $restored[$key] ?? null, $key . ' was overwritten by the archive.');
        }
    }

    /**
     * And the half that would make keeping them pointless: everything the
     * archive exists to carry does arrive.
     *
     * A restore that preserved the target's credentials by preserving the
     * whole file would pass the test above and be completely useless — the
     * new site would start, and would not read a single encrypted column.
     */
    public function testTheEncryptionKeysOfTheOriginDoArrive(): void
    {
        $this->restoreOntoTarget();

        $restored = $this->readSecrets($this->targetBase);

        $this->assertSame(self::ORIGIN_ENCRYPTION_KEY, $restored['encryption_key'] ?? null);
        $this->assertSame('la-clef-vapid-de-l-origine', $restored['vapid_private_key'] ?? null);
        $this->assertSame('smtp.example', $restored['smtp_host'] ?? null);
        $this->assertSame(
            $this->originMasterKey,
            file_get_contents($this->targetBase . '/storage/keys/master.key'),
            'the master key on the target is not the origin\'s, so nothing it wrote can be read'
        );
    }

    public function testTheFilesOfTheOriginArrive(): void
    {
        $this->restoreOntoTarget();

        $this->assertFileExists($this->targetBase . '/storage/uploads/tresorerie.pdf');
        $this->assertSame(
            'les comptes de l\'unite',
            file_get_contents($this->targetBase . '/storage/uploads/tresorerie.pdf')
        );
    }

    /**
     * The archive's own bookkeeping never lands on the restored site.
     *
     * A `secrets/` directory in the site root would hold the SEALED bytes
     * next to the live key — the archive's whole protection undone by the
     * restore that used it.
     */
    public function testNeitherTheSealedSecretsNorTheManifestAreLeftOnDisk(): void
    {
        $this->restoreOntoTarget();

        $this->assertDirectoryDoesNotExist($this->targetBase . '/secrets');
        $this->assertFileDoesNotExist($this->targetBase . '/' . PortableManifest::MEMBER);
    }

    /**
     * D6 and its neighbours, on a real database.
     *
     * The identifier is blanked rather than replaced so that
     * `InstallationIdentityService` mints the next one through its own
     * concurrency-safe claim — so what is asserted here is that the site
     * no longer answers with the origin's identity, and that asking for one
     * produces something new.
     */
    public function testTheRestoredInstallationBecomesANewOneRatherThanASecondCopy(): void
    {
        $connection = $this->realDbConnection();
        $pdo = $connection->getPdo();

        $this->seedSetting($pdo, InstallationIdentityService::INSTALLATION_ID_SETTING, self::ORIGIN_ID);
        $this->seedSetting($pdo, PortableRestore::RESTORED_FROM_SETTING, '');
        $this->seedSetting($pdo, 'base_url', 'https://ancien.example');

        (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))
            ->adoptNewIdentity($pdo, self::ORIGIN_ID, 'https://nouveau.example');

        $this->assertSame(
            '',
            $this->readSetting($pdo, InstallationIdentityService::INSTALLATION_ID_SETTING),
            'the restored site still answers with the identity of the site it came from'
        );
        $this->assertSame(self::ORIGIN_ID, $this->readSetting($pdo, PortableRestore::RESTORED_FROM_SETTING));
        $this->assertSame('https://nouveau.example', $this->readSetting($pdo, 'base_url'));
    }

    /**
     * The push subscriptions of the old domain go.
     *
     * A service worker is bound to its origin: carried elsewhere, every
     * endpoint is already dead, and keeping them means every send spends a
     * request collecting a 410 before pruning what was never going to work.
     */
    public function testThePushSubscriptionsOfTheOldDomainAreEmptied(): void
    {
        $connection = $this->realDbConnection();
        $pdo = $connection->getPdo();

        $pdo->exec('DELETE FROM push_subscriptions');
        $userId = $this->seedUserAccount($pdo);
        $pdo->prepare(
            'INSERT INTO push_subscriptions (user_account_id, endpoint, endpoint_blind_index, auth_key, p256dh_key) '
            . 'VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, 'x', str_repeat('a', 64), 'y', 'z']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());

        (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))
            ->adoptNewIdentity($pdo, null, null);

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
    }

    /**
     * **The whole sequence, on a real database**: the archive's dump goes
     * back, its data goes back, its keys go in last.
     *
     * Last is deliberate and is the reason these four steps live in one
     * method rather than in each caller: an installation whose restore
     * died half way still holds the keys to the database it had before,
     * rather than the keys to one it never received.
     *
     * This is the wizard's path in miniature — the roadmap's « restauration
     * sur une base vide depuis l'assistant » — minus the HTTP layer, which
     * is what `SetupPortableRestoreTest` covers.
     */
    public function testTheWholeSequenceRestoresTheDatabaseTheDataAndTheKeys(): void
    {
        $connection = $this->realDbConnection();
        $service = new BackupService($connection, $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        // A row that exists only in the ORIGIN's database, so that "the dump
        // came back" is a fact about content and not about a table existing.
        $marker = 'unite-' . bin2hex(random_bytes(4));
        $this->seedSetting($connection->getPdo(), 'site_name', $marker);

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        // The target's database is emptied of that fact before the restore,
        // exactly as a freshly installed one would be.
        $this->seedSetting($connection->getPdo(), 'site_name', 'site tout neuf');

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);
        $archive->verifyDeclaredMembers();

        (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))->apply(
            $archive,
            new BackupService($connection, $this->targetBase . '/storage', $this->targetBase),
            $this->targetOwnedSecrets()
        );
        $archive->close();

        $this->assertSame(
            $marker,
            $this->readSetting($connection->getPdo(), 'site_name'),
            'the origin\'s database did not come back'
        );
        $this->assertFileExists($this->targetBase . '/storage/uploads/tresorerie.pdf');
        $this->assertSame(
            $this->originMasterKey,
            file_get_contents($this->targetBase . '/storage/keys/master.key')
        );

        $restored = $this->readSecrets($this->targetBase);
        $this->assertSame(self::ORIGIN_ENCRYPTION_KEY, $restored['encryption_key'] ?? null);
        foreach ($this->targetOwnedSecrets() as $key => $expected) {
            $this->assertSame($expected, $restored[$key] ?? null, $key . ' was overwritten by the archive.');
        }

        // And the dump it wrote to do all that does not survive it.
        $this->assertSame(
            [],
            glob($this->targetBase . '/storage/temp/portable_restore_*.sql') ?: [],
            'the restored dump was left behind in storage/temp'
        );
    }

    /**
     * An archive with no database in it is refused, rather than restoring
     * the files of a site whose data never arrives.
     */
    public function testAnArchiveWithoutADatabaseIsRefused(): void
    {
        $connection = $this->realDbConnection();
        $service = new BackupService($connection, $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zipPath) === true);
        $this->assertTrue($zip->deleteName('database.sql'));
        $zip->close();

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);

        $this->expectException(BackupException::class);

        try {
            (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))->apply(
                $archive,
                new BackupService($connection, $this->targetBase . '/storage', $this->targetBase),
                $this->targetOwnedSecrets()
            );
        } finally {
            $archive->close();
        }
    }

    /**
     * An archive carrying no site data is refused, and refused early.
     *
     * It is not a corrupt file — it opens, its digests match, its
     * database is there — which is exactly why it needs saying: without
     * this the restore would replace the database with the origin's and
     * then extract nothing, leaving a site whose data and whose files
     * disagree.
     */
    public function testAnArchiveWithNoSiteFilesIsRefusedBeforeTheDatabaseIsReplaced(): void
    {
        $connection = $this->realDbConnection();
        $service = new BackupService($connection, $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zipPath) === true);
        for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
            $stat = $zip->statIndex($i);
            if ($stat !== false && str_starts_with((string) $stat['name'], 'storage/')) {
                $this->assertTrue($zip->deleteIndex($i));
            }
        }
        $zip->close();

        $intact = 'cible-intacte-' . bin2hex(random_bytes(4));
        $this->seedSetting($connection->getPdo(), 'site_name', $intact);

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);

        try {
            (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))->apply(
                $archive,
                new BackupService($connection, $this->targetBase . '/storage', $this->targetBase),
                $this->targetOwnedSecrets()
            );
            $this->fail('An archive with nothing to restore was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('aucun fichier à restaurer', $e->getMessage());
        } finally {
            $archive->close();
        }

        $this->assertSame($intact, $this->readSetting($connection->getPdo(), 'site_name'));
    }

    /**
     * **A dump that inflates far past what its own header declares.**
     *
     * The size the ceiling is checked against comes from the zip's
     * central directory — which is to say from whoever wrote the archive.
     * An entry may declare a few kilobytes and expand to gigabytes;
     * DEFLATE ratios past 1000:1 are ordinary, and `PortableRestore` has
     * no disk budget of its own. So the declared size has to bound the
     * COPY, not merely be compared with it once the disk is full.
     *
     * Capping alone would not be enough either, and that is the half this
     * test actually bites on: with the copy capped and nothing checking
     * for what follows, a payload longer than its header claims is
     * SILENTLY TRUNCATED — and a dump cut at a statement boundary
     * restores without complaint, which is the failure this whole method
     * is written against. Remove the one-byte read past the cap and this
     * test fails, on the message.
     *
     * The cap itself is a `$length` argument, and its effect — how many
     * bytes reached the disk before the refusal — is not observable from
     * outside without a filesystem quota, so what is asserted here is the
     * refusal and the absence of truncation, not the byte count.
     *
     * The archive is genuine — written by `BackupService` — and only its
     * central-directory size field is altered afterwards, because that is
     * exactly the one thing an attacker controls and the reader believes.
     */
    public function testADumpThatInflatesPastItsDeclaredSizeIsRefused(): void
    {
        $connection = $this->realDbConnection();
        $service = new BackupService($connection, $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $this->assertTrue($this->understateDeclaredSize($this->zipPath, 'database.sql', 1000));

        $intact = 'cible-intacte-' . bin2hex(random_bytes(4));
        $this->seedSetting($connection->getPdo(), 'site_name', $intact);

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);

        try {
            (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))->apply(
                $archive,
                new BackupService($connection, $this->targetBase . '/storage', $this->targetBase),
                $this->targetOwnedSecrets()
            );
            $this->fail('A dump larger than the size it declares was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('en entier', $e->getMessage());
        } finally {
            $archive->close();
        }

        $this->assertSame(
            [],
            glob($this->targetBase . '/storage/temp/portable_restore_*.sql') ?: [],
            'the partial dump was left on the disk it was about to fill'
        );
        $this->assertSame($intact, $this->readSetting($connection->getPdo(), 'site_name'));
    }

    /**
     * Rewrites one entry's declared uncompressed size in the zip's central
     * directory, leaving the payload alone.
     *
     * Hand-patched bytes, deliberately: `ZipArchive` cannot produce an
     * archive that lies about itself, and an archive that lies about
     * itself is the whole subject.
     */
    private function understateDeclaredSize(string $zipPath, string $entry, int $declared): bool
    {
        $raw = (string) file_get_contents($zipPath);
        $offset = 0;
        $patched = false;

        while (($position = strpos($raw, "PK\x01\x02", $offset)) !== false) {
            $nameLength = (int) unpack('v', substr($raw, $position + 28, 2))[1];
            if (substr($raw, $position + 46, $nameLength) === $entry) {
                $raw = substr_replace($raw, pack('V', $declared), $position + 24, 4);
                $patched = true;
            }
            $offset = $position + 4;
        }

        file_put_contents($zipPath, $raw);

        return $patched;
    }

    /**
     * **A hostile entry is refused while the target is still intact.**
     *
     * `restorableEntries()` is where a `..` path, a symlink or a payload
     * over the ceiling is caught, and it used to be reached only through
     * `extractFiles()` — which runs AFTER the database has been replaced.
     * The refusal still happened, on an installation whose own data was
     * already gone: the archive cost exactly what it would have cost if it
     * had been accepted. So what is asserted here is not that the archive
     * is refused, which was never in doubt, but that the target's database
     * is untouched when it is.
     */
    public function testAHostileEntryIsRefusedBeforeTheDatabaseIsReplaced(): void
    {
        $connection = $this->realDbConnection();
        $service = new BackupService($connection, $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        // The name the archive's dump carries, so that "the database was
        // replaced" is a fact about content rather than about a table.
        $this->seedSetting($connection->getPdo(), 'site_name', 'le site de l\'archive');

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zipPath) === true);
        $this->assertTrue($zip->addFromString('storage/../../evil.txt', 'des octets choisis ailleurs'));
        $zip->close();

        $intact = 'cible-intacte-' . bin2hex(random_bytes(4));
        $this->seedSetting($connection->getPdo(), 'site_name', $intact);

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);

        try {
            (new PortableRestore($this->targetBase, $this->targetBase . '/storage'))->apply(
                $archive,
                new BackupService($connection, $this->targetBase . '/storage', $this->targetBase),
                $this->targetOwnedSecrets()
            );
            $this->fail('An archive containing a path outside the install root was accepted.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('chemin non autorisé', $e->getMessage());
        } finally {
            $archive->close();
        }

        $this->assertSame(
            $intact,
            $this->readSetting($connection->getPdo(), 'site_name'),
            'the target\'s database was replaced before the archive was found to be unusable'
        );
        $this->assertFileDoesNotExist(
            $this->targetBase . '/storage/uploads/tresorerie.pdf',
            'the archive\'s files landed on a target the restore then refused'
        );
    }

    /**
     * **What the safety backup cannot put back.**
     *
     * `BackupService::createFileBackup()` excludes `storage/keys/` and
     * `storage/config/` in every mode — secrets never travel in an
     * ordinary archive — so the automatic rollback restores the database
     * and the file tree and leaves whatever keys are on disk. After a
     * failed portable restore those are the ARCHIVE's, and both outcomes
     * are bad: an installation that cannot read the data just handed back
     * to it, or one quietly pointed at the origin's database while the
     * journal says the previous state was restored.
     *
     * Hence the snapshot. This asserts it round-trips the two files whose
     * loss is unrecoverable.
     */
    public function testTheTargetsOwnKeysCanBePutBackAfterAFailedRestore(): void
    {
        $restore = new PortableRestore($this->targetBase, $this->targetBase . '/storage');

        $before = $restore->secretsSnapshot();
        $this->assertNotNull($before['storage/keys/master.key'] ?? null);

        // Whatever a half-finished restore left behind.
        file_put_contents($this->targetBase . '/storage/keys/master.key', 'la clef de l\'archive');
        file_put_contents($this->targetBase . '/storage/config/secrets.enc', 'le blob de l\'archive');

        $restore->restoreSecretsSnapshot($before);

        $this->assertSame(
            $before['storage/keys/master.key'],
            file_get_contents($this->targetBase . '/storage/keys/master.key')
        );
        $this->assertSame(
            $before['storage/config/secrets.enc'],
            file_get_contents($this->targetBase . '/storage/config/secrets.enc')
        );
    }

    /**
     * **And on a fresh installation, putting back "nothing" means
     * deleting.**
     *
     * This is the wizard's case and the one that matters most there. A
     * failed restore that left the archive's keys behind would leave
     * `SecretManager::isInitialized()` answering true — so the next
     * attempt is refused as "already configured", on a site with no
     * database, no account and no way forward. The operator would be stuck
     * for good, by a failure that should have cost them one retry.
     */
    public function testAFreshInstallationIsLeftUnconfiguredRatherThanHalfConfigured(): void
    {
        $freshBase = $this->targetBase . '/fresh';
        @mkdir($freshBase . '/storage/keys', 0700, true);
        @mkdir($freshBase . '/storage/config', 0700, true);

        $restore = new PortableRestore($freshBase, $freshBase . '/storage');
        $before = $restore->secretsSnapshot();
        $this->assertNull($before['storage/keys/master.key']);

        file_put_contents($freshBase . '/storage/keys/master.key', 'la clef de l\'archive');
        file_put_contents($freshBase . '/storage/config/secrets.enc', 'le blob de l\'archive');
        $this->assertTrue(
            (new SecretManager(
                $freshBase . '/storage/keys/master.key',
                $freshBase . '/storage/config/secrets.enc'
            ))->isInitialized()
        );

        $restore->restoreSecretsSnapshot($before);

        $this->assertFalse(
            (new SecretManager(
                $freshBase . '/storage/keys/master.key',
                $freshBase . '/storage/config/secrets.enc'
            ))->isInitialized(),
            'the wizard would refuse the next attempt as already configured'
        );
    }

    /** Builds the origin's archive and restores it onto the target root. */
    private function restoreOntoTarget(): void
    {
        $service = new BackupService($this->realDbConnection(), $this->originBase . '/storage', $this->originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $result = $service->createPortableBackup(self::PASSPHRASE, '2.4.1', self::ORIGIN_ID);
        $this->zipPath = $result['zipPath'];
        $this->dbDumpPath = $result['dbDumpPath'];

        $archive = PortableArchive::open($this->zipPath, self::PASSPHRASE);
        $archive->verifyDeclaredMembers();

        $restore = new PortableRestore($this->targetBase, $this->targetBase . '/storage');
        $restore->extractFiles($archive);
        $restore->installSecrets($archive, $this->targetOwnedSecrets());

        $archive->close();
    }

    /** @param array<string, mixed> $secrets */
    private function writeSecrets(string $base, string $masterKey, array $secrets): void
    {
        $this->makeFile($base, 'storage/keys/master.key', $masterKey);
        (new SecretManager($base . '/storage/keys/master.key', $base . '/storage/config/secrets.enc'))
            ->writeSecrets($secrets);
    }

    /** @return array<string, mixed> */
    private function readSecrets(string $base): array
    {
        return (new SecretManager($base . '/storage/keys/master.key', $base . '/storage/config/secrets.enc'))
            ->readSecrets();
    }

    private function seedSetting(\PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, label, description) VALUES (?, ?, ?, ?, ?)'
        )->execute([$key, $value, 'text', $key, '']);
    }

    /**
     * A subscription needs an account to belong to — the foreign key is the
     * point, not an obstacle: it is what makes emptying this table on a
     * restore a decision rather than a side effect of the accounts arriving.
     */
    private function seedUserAccount(\PDO $pdo): int
    {
        $blindIndex = hash('sha256', 'portable-restore-test-' . uniqid());
        $pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['chiffre', $blindIndex]);

        return (int) $pdo->lastInsertId();
    }

    private function readSetting(\PDO $pdo, string $key): ?string
    {
        $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function makeFile(string $base, string $relativePath, string $content): void
    {
        $path = $base . '/' . $relativePath;
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
